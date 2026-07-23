<?php
/**
 * Batched full index rebuild.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuilds the whole index in resumable batches.
 *
 * Driven from the settings screen one AJAX tick at a time rather than in a
 * single request, so a large site can't hit the PHP time limit halfway through
 * and leave a half-written index behind. Each tick is independently
 * capability-checked and nonce-verified.
 */
final class Coywolf_Search_Rebuilder {

	const STATE_OPTION = 'coywolf_search_rebuild_state';
	const AJAX_ACTION  = 'coywolf_search_rebuild';
	const NONCE_ACTION = 'coywolf_search_rebuild';
	const CRON_HOOK    = 'coywolf_search_rebuild_batch';
	const TICK_LOCK    = 'coywolf_search_tick_lock';

	/**
	 * Posts indexed per tick.
	 */
	const BATCH = 25;

	/**
	 * How long a tick holds the lock. If a rebuild is abandoned (browser tab
	 * closed mid-run), the lock lapses and normal indexing resumes.
	 */
	const LOCK_SECONDS = 120;

	/**
	 * How long one background run keeps working before yielding and
	 * rescheduling. Long enough to make real progress on a big site, short
	 * enough to stay well inside a normal PHP time limit.
	 */
	const CRON_RUN_SECONDS = 20;

	/**
	 * How long a single tick may hold the advance lock.
	 */
	const TICK_LOCK_SECONDS = 60;

	/**
	 * Register the AJAX endpoint and the background runner.
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_background' ) );
	}

	/**
	 * Queue a rebuild to run in the background.
	 *
	 * Used on activation and whenever a setting changes what would be stored,
	 * so the index looks after itself instead of waiting for someone to notice
	 * a notice and press a button.
	 */
	public static function schedule_auto_rebuild() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::CRON_HOOK );
	}

	/**
	 * Work through the rebuild for a bounded slice of time, then reschedule if
	 * there is more to do. Cron callback.
	 */
	public function run_background() {
		$state = self::state();

		if ( empty( $state['running'] ) ) {
			$this->start();
		}

		$deadline = time() + self::CRON_RUN_SECONDS;

		do {
			$progress = $this->tick();
			if ( ! $progress['running'] ) {
				return;
			}
		} while ( time() < $deadline );

		wp_schedule_single_event( time() + 10, self::CRON_HOOK );
	}

	/**
	 * Default state.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_state() {
		return array(
			'running'    => false,
			'offset'     => 0,
			'total'      => 0,
			'done'       => 0,
			'lock_until' => 0,
		);
	}

	/**
	 * Current rebuild state.
	 *
	 * @return array<string,mixed>
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return array_merge( self::default_state(), $state );
	}

	/**
	 * Whether a rebuild currently owns the tables.
	 *
	 * @return bool
	 */
	public static function is_running() {
		$state = self::state();
		return ! empty( $state['running'] ) && (int) $state['lock_until'] > time();
	}

	/**
	 * Persist state and refresh the lock.
	 *
	 * @param array<string,mixed> $state State.
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * AJAX entry point for start / tick / status.
	 */
	public function handle_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to rebuild the index.', 'coywolf-search' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';

		switch ( $step ) {
			case 'start':
				wp_send_json_success( $this->start() );
				break;
			case 'tick':
				wp_send_json_success( $this->tick() );
				break;
			case 'cancel':
				wp_send_json_success( $this->cancel() );
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Unknown rebuild step.', 'coywolf-search' ) ), 400 );
		}
	}

	/**
	 * Clear the index and prepare to walk every eligible post.
	 *
	 * @return array<string,mixed> Progress payload.
	 */
	public function start() {
		global $wpdb;

		Coywolf_Search_Schema::maybe_upgrade();

		$postings = Coywolf_Search_Schema::postings_table();
		$terms    = Coywolf_Search_Schema::terms_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Emptying the plugin's own index tables at the start of a rebuild.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $postings ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Emptying the plugin's own vocabulary table at the start of a rebuild.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $terms ) );

		Coywolf_Search_Indexer::update_stats( Coywolf_Search_Indexer::default_stats() );
		Coywolf_Search_Vocabulary::invalidate();

		$total = $this->count_eligible();

		$state = array(
			'running'    => true,
			'offset'     => 0,
			'total'      => $total,
			'done'       => 0,
			'lock_until' => time() + self::LOCK_SECONDS,
		);
		self::save_state( $state );

		return $this->progress( $state );
	}

	/**
	 * Index the next batch.
	 *
	 * @return array<string,mixed> Progress payload.
	 */
	public function tick() {
		$state = self::state();

		if ( empty( $state['running'] ) ) {
			return $this->progress( $state );
		}

		// A rebuild can be advanced from two places at once — someone watching
		// the progress bar while the background runner is also working. Both
		// read the offset, so without a claim they would index the same batch
		// twice and advance past the next one, silently skipping posts.
		if ( ! $this->claim_tick() ) {
			return $this->progress( $state );
		}

		try {
			$post_ids = $this->next_batch( (int) $state['offset'] );

			if ( empty( $post_ids ) ) {
				return $this->finish( $state );
			}

			$indexer = new Coywolf_Search_Indexer();
			foreach ( $post_ids as $post_id ) {
				$indexer->index_post( (int) $post_id );
			}

			$state['offset']     = (int) $state['offset'] + count( $post_ids );
			$state['done']       = (int) $state['done'] + count( $post_ids );
			$state['lock_until'] = time() + self::LOCK_SECONDS;
			self::save_state( $state );

			return $this->progress( $state );
		} finally {
			$this->release_tick();
		}
	}

	/**
	 * Claim the right to advance the rebuild by one batch.
	 *
	 * `add_option()` is the claim: the options table has a unique index on the
	 * name, so exactly one caller can create the row.
	 *
	 * @return bool
	 */
	private function claim_tick() {
		$now = time();

		if ( add_option( self::TICK_LOCK, $now, '', false ) ) {
			return true;
		}

		$held = (int) get_option( self::TICK_LOCK, 0 );

		// A claim left behind by a request that died mid-batch expires rather
		// than wedging the rebuild forever.
		if ( $held > 0 && ( $now - $held ) < self::TICK_LOCK_SECONDS ) {
			return false;
		}

		update_option( self::TICK_LOCK, $now, false );
		return true;
	}

	/**
	 * Give up the claim.
	 */
	private function release_tick() {
		delete_option( self::TICK_LOCK );
	}

	/**
	 * Abandon a running rebuild.
	 *
	 * @return array<string,mixed> Progress payload.
	 */
	public function cancel() {
		$state = self::default_state();
		self::save_state( $state );
		return $this->progress( $state );
	}

	/**
	 * Finalise a completed rebuild.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed> Progress payload.
	 */
	private function finish( array $state ) {
		$indexer = new Coywolf_Search_Indexer();
		$indexer->refresh_term_count();
		$indexer->clear_queue();

		$stats                  = Coywolf_Search_Indexer::stats();
		$stats['last_build']    = time();
		$stats['pipeline_hash'] = Coywolf_Search_Settings::pipeline_hash();
		Coywolf_Search_Indexer::update_stats( $stats );

		delete_option( 'coywolf_search_index_stale' );
		Coywolf_Search_Vocabulary::invalidate();

		/**
		 * Fires when a full index rebuild completes.
		 */
		do_action( 'coywolf_search_index_rebuilt' );

		$state['running']    = false;
		$state['lock_until'] = 0;
		self::save_state( $state );

		return $this->progress( $state, true );
	}

	/**
	 * Shape the payload the progress bar consumes.
	 *
	 * @param array<string,mixed> $state    State.
	 * @param bool                $complete Whether the rebuild just finished.
	 * @return array<string,mixed>
	 */
	private function progress( array $state, $complete = false ) {
		$total = max( 0, (int) $state['total'] );
		$done  = min( $total, max( 0, (int) $state['done'] ) );

		return array(
			'running'  => ! empty( $state['running'] ),
			'complete' => (bool) $complete,
			'total'    => $total,
			'done'     => $done,
			'percent'  => $total > 0 ? (int) floor( ( $done / $total ) * 100 ) : 100,
			'stats'    => Coywolf_Search_Admin::stats_payload(),
		);
	}

	/**
	 * How many posts the rebuild will visit.
	 *
	 * Counted with the same constraints the batch walk uses, so the progress
	 * bar can actually reach 100% — `wp_count_posts()` would include
	 * password-protected posts, which are never indexed.
	 *
	 * @return int
	 */
	private function count_eligible() {
		$types = (array) Coywolf_Search_Settings::get( 'post_types' );
		if ( empty( $types ) ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'has_password'           => false,
				'coywolf_search_bypass'  => true,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * The next page of post IDs to index.
	 *
	 * @param int $offset Offset.
	 * @return int[]
	 */
	private function next_batch( $offset ) {
		$types = (array) Coywolf_Search_Settings::get( 'post_types' );
		if ( empty( $types ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => self::BATCH,
				'offset'                 => (int) $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'suppress_filters'       => false,
				'has_password'           => false,
				// The rebuild must read posts from the database, not from the
				// engine that is being rebuilt.
				'coywolf_search_bypass'  => true,
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Clear rebuild state (used by lifecycle teardown).
	 */
	public static function reset() {
		delete_option( self::STATE_OPTION );
		delete_option( self::TICK_LOCK );
	}
}
