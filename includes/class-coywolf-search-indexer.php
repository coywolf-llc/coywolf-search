<?php
/**
 * Index writes: per-post indexing, removal, and the debounced dirty queue.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything that writes to the index tables.
 *
 * Post edits never index synchronously — a save only flags the post dirty and
 * makes sure a single cron event is scheduled. The actual work happens off the
 * request path so publishing stays as fast as it was without the plugin.
 */
final class Coywolf_Search_Indexer {

	const QUEUE_OPTION = 'coywolf_search_queue';
	const STATS_OPTION = 'coywolf_search_index_stats';
	const CRON_HOOK    = 'coywolf_search_process_queue';

	/**
	 * How long to wait before draining the queue, so a burst of edits
	 * collapses into one run.
	 */
	const DEBOUNCE_SECONDS = 60;

	/**
	 * Ceiling for the tf and dl columns (smallint unsigned).
	 */
	const COUNT_MAX = 65535;

	/**
	 * Posts drained from the queue per cron run.
	 */
	const QUEUE_BATCH = 100;

	/**
	 * Register the hooks that detect content changes.
	 */
	public function init() {
		// Status transitions are the primary signal: they cover publish,
		// unpublish, trash, untrash, and a scheduled post going live. A plain
		// save_post only catches edits to an already-published post.
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'on_delete' ), 10, 1 );
		add_action( 'set_object_terms', array( $this, 'on_set_terms' ), 10, 6 );
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 10, 3 );

		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Change detection                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Flag a post whose status changed.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( $new_status === $old_status && 'publish' !== $new_status ) {
			return;
		}
		$this->mark_dirty( $post->ID );
	}

	/**
	 * Flag an edited post.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$this->mark_dirty( $post_id );
	}

	/**
	 * Drop a deleted post's postings immediately — there is nothing left to
	 * read later, so this one case does run inline.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete( $post_id ) {
		$this->unqueue( (int) $post_id );
		$this->remove_post( (int) $post_id );
	}

	/**
	 * Flag a post whose term assignments changed.
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Terms (unused).
	 * @param array  $tt_ids     Term taxonomy IDs (unused).
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Whether terms were appended (unused).
	 * @param array  $old_tt_ids Previous term taxonomy IDs (unused).
	 */
	public function on_set_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		$indexed = (array) Coywolf_Search_Settings::get( 'taxonomies' );
		if ( ! in_array( $taxonomy, $indexed, true ) ) {
			return;
		}
		$this->mark_dirty( (int) $object_id );
	}

	/**
	 * Flag every post carrying a renamed term.
	 *
	 * Bounded: a term on tens of thousands of posts would otherwise write a
	 * queue option too large to be useful. Beyond the cap the site needs a
	 * rebuild, which the settings screen already surfaces.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_edited_term( $term_id, $tt_id, $taxonomy ) {
		$indexed = (array) Coywolf_Search_Settings::get( 'taxonomies' );
		if ( ! in_array( $taxonomy, $indexed, true ) ) {
			return;
		}

		$object_ids = get_objects_in_term( (int) $term_id, $taxonomy );
		if ( is_wp_error( $object_ids ) || empty( $object_ids ) ) {
			return;
		}

		foreach ( array_slice( $object_ids, 0, 500 ) as $object_id ) {
			$this->mark_dirty( (int) $object_id );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Queue                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Add a post to the dirty queue and make sure a drain is scheduled.
	 *
	 * @param int $post_id Post ID.
	 */
	public function mark_dirty( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		// Revisions and autosaves are never indexed, and they arrive through
		// several of the hooks above — transition_post_status fires for the
		// revision's own "inherit" status too. Rejecting them here rather than
		// at each call site means the queue only ever holds real posts, which
		// is also what the queue depth on the settings screen reports.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$queue = $this->queue();
		if ( isset( $queue[ $post_id ] ) ) {
			return;
		}

		$queue[ $post_id ] = 1;
		update_option( self::QUEUE_OPTION, $queue, false );

		$this->schedule_drain();
	}

	/**
	 * Schedule the single, argument-less drain event if one isn't pending.
	 *
	 * Argument-less on purpose: `wp_next_scheduled()` matches on hook *and*
	 * arguments, so a per-post event would defeat the debounce and schedule
	 * one cron entry per edit.
	 */
	private function schedule_drain() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		/**
		 * Filters how long to wait before reindexing edited posts.
		 *
		 * Lower it to make edits searchable sooner, at the cost of collapsing
		 * fewer edits into one run. WP-Cron only fires on a page request, so
		 * the real delay on a quiet site is this plus the wait for the next
		 * visitor.
		 *
		 * @param int $seconds Debounce window in seconds.
		 */
		$delay = (int) apply_filters( 'coywolf_search_reindex_delay', self::DEBOUNCE_SECONDS );

		wp_schedule_single_event( time() + max( 1, $delay ), self::CRON_HOOK );
	}

	/**
	 * Current dirty queue.
	 *
	 * @return array<int,int> Post ID => 1.
	 */
	public function queue() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * How many posts are waiting to be indexed.
	 *
	 * @return int
	 */
	public function queue_depth() {
		return count( $this->queue() );
	}

	/**
	 * Remove one post from the queue.
	 *
	 * @param int $post_id Post ID.
	 */
	private function unqueue( $post_id ) {
		$queue = $this->queue();
		if ( ! isset( $queue[ $post_id ] ) ) {
			return;
		}
		unset( $queue[ $post_id ] );
		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Empty the queue.
	 */
	public function clear_queue() {
		update_option( self::QUEUE_OPTION, array(), false );
	}

	/**
	 * Drain the dirty queue. Cron callback.
	 */
	public function process_queue() {
		// A full rebuild owns the tables while it runs; the queue would be
		// rewriting rows the rebuild is about to replace.
		if ( Coywolf_Search_Rebuilder::is_running() ) {
			wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::CRON_HOOK );
			return;
		}

		if ( ! Coywolf_Search_Schema::tables_exist() ) {
			return;
		}

		$queue = $this->queue();
		if ( empty( $queue ) ) {
			return;
		}

		$batch     = array_slice( array_keys( $queue ), 0, self::QUEUE_BATCH );
		$remaining = $queue;

		foreach ( $batch as $post_id ) {
			$this->index_post( (int) $post_id );
			unset( $remaining[ $post_id ] );
		}

		update_option( self::QUEUE_OPTION, $remaining, false );

		Coywolf_Search_Vocabulary::invalidate();
		$this->refresh_term_count();

		if ( ! empty( $remaining ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Indexing                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Whether a post belongs in the index.
	 *
	 * Password-protected posts are never indexed, by design and without an
	 * option to change it: their content would otherwise be readable through
	 * search results and typeahead snippets by people who don't have the
	 * password.
	 *
	 * @param WP_Post|int|null $post Post or ID.
	 * @return bool
	 */
	public function should_index( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( '' !== (string) $post->post_password ) {
			return false;
		}

		$types = (array) Coywolf_Search_Settings::get( 'post_types' );
		if ( ! in_array( $post->post_type, $types, true ) ) {
			return false;
		}

		/**
		 * Filters whether a post is indexed.
		 *
		 * @param bool    $should_index Whether to index.
		 * @param WP_Post $post         The post.
		 */
		return (bool) apply_filters( 'coywolf_search_should_index', true, $post );
	}

	/**
	 * Rewrite one post's postings.
	 *
	 * Delete-then-insert rather than diffing: it is idempotent, so a queue
	 * that runs twice produces the same rows, and it is the same code path for
	 * a new post, an edited post, and a post that just became ineligible.
	 *
	 * @param int $post_id Post ID.
	 */
	public function index_post( $post_id ) {
		$post_id = (int) $post_id;

		$this->remove_post( $post_id );

		$post = get_post( $post_id );
		if ( ! $this->should_index( $post ) ) {
			return;
		}

		$tokenizer = Coywolf_Search_Tokenizer::from_settings();
		$fields    = (array) Coywolf_Search_Settings::get( 'fields' );

		$texts = array();
		if ( in_array( 'title', $fields, true ) ) {
			$texts['title'] = $post->post_title;
		}
		if ( in_array( 'content', $fields, true ) ) {
			$texts['content'] = $tokenizer->clean( $post->post_content );
		}
		if ( in_array( 'excerpt', $fields, true ) ) {
			$texts['excerpt'] = $tokenizer->clean( $post->post_excerpt );
		}
		if ( in_array( 'taxonomy', $fields, true ) ) {
			$texts['taxonomy'] = $this->taxonomy_text( $post_id );
		}

		$per_field = array();
		$all_terms = array();

		foreach ( $texts as $key => $text ) {
			if ( '' === trim( (string) $text ) ) {
				continue;
			}
			$result = $tokenizer->tokenize( $text );
			if ( empty( $result['terms'] ) ) {
				continue;
			}
			$per_field[ $key ] = $result;
			foreach ( $result['terms'] as $term => $unused_tf ) {
				$all_terms[ $term ] = true;
			}
		}

		if ( empty( $all_terms ) ) {
			return;
		}

		$term_ids = $this->term_ids( array_keys( $all_terms ) );
		if ( empty( $term_ids ) ) {
			return;
		}

		$rows         = array();
		$field_totals = array();

		foreach ( $per_field as $key => $result ) {
			$field  = Coywolf_Search_Schema::field_id( $key );
			$length = min( self::COUNT_MAX, (int) $result['length'] );

			$field_totals[ $field ] = $length;

			foreach ( $result['terms'] as $term => $tf ) {
				if ( ! isset( $term_ids[ $term ] ) ) {
					continue;
				}
				$rows[] = array(
					(int) $term_ids[ $term ],
					$post_id,
					$field,
					min( self::COUNT_MAX, (int) $tf ),
					$length,
				);
			}
		}

		if ( empty( $rows ) ) {
			return;
		}

		$this->insert_postings( $rows );
		$this->bump_doc_freq( array_values( $term_ids ), 1 );
		$this->adjust_stats( $field_totals, 1 );
	}

	/**
	 * Delete a post's postings and roll back the statistics they contributed.
	 *
	 * @param int $post_id Post ID.
	 */
	public function remove_post( $post_id ) {
		global $wpdb;

		$post_id  = (int) $post_id;
		$postings = Coywolf_Search_Schema::postings_table();

		if ( ! Coywolf_Search_Schema::tables_exist() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the plugin's own postings table; no core API applies.
		$existing = $wpdb->get_results(
			$wpdb->prepare( 'SELECT term_id, field, dl FROM %i WHERE post_id = %d', $postings, $post_id ),
			ARRAY_A
		);

		if ( empty( $existing ) ) {
			return;
		}

		$term_ids     = array();
		$field_totals = array();
		foreach ( $existing as $row ) {
			$term_ids[ (int) $row['term_id'] ] = true;
			$field_totals[ (int) $row['field'] ] = (int) $row['dl'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from the plugin's own postings table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d', $postings, $post_id ) );

		$this->bump_doc_freq( array_keys( $term_ids ), -1 );
		$this->adjust_stats( $field_totals, -1 );
	}

	/**
	 * Concatenated term names for the post, across the indexed taxonomies.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function taxonomy_text( $post_id ) {
		$taxonomies = (array) Coywolf_Search_Settings::get( 'taxonomies' );
		if ( empty( $taxonomies ) ) {
			return '';
		}

		$names = array();
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}
		}

		return implode( ' ', $names );
	}

	/**
	 * Resolve terms to IDs, creating any that are new.
	 *
	 * @param string[] $terms Terms.
	 * @return array<string,int> Term => term_id.
	 */
	private function term_ids( array $terms ) {
		global $wpdb;

		if ( empty( $terms ) ) {
			return array();
		}

		$table = Coywolf_Search_Schema::terms_table();
		$found = $this->select_term_ids( $terms );

		$missing = array_values( array_diff( $terms, array_keys( $found ) ) );
		if ( empty( $missing ) ) {
			return $found;
		}

		// INSERT IGNORE so a concurrent request that created the same term
		// doesn't turn into a duplicate-key error.
		$chunks = array_chunk( $missing, 200 );
		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '(%s,0)' ) );
			$sql          = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated from the value count, then bound below.
				"INSERT IGNORE INTO %i (term, doc_freq) VALUES {$placeholders}",
				array_merge( array( $table ), $chunk )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; writing to the plugin's own vocabulary table.
			$wpdb->query( $sql );
		}

		return $this->select_term_ids( $terms );
	}

	/**
	 * Look up the IDs of terms that already exist.
	 *
	 * @param string[] $terms Terms.
	 * @return array<string,int> Term => term_id.
	 */
	private function select_term_ids( array $terms ) {
		global $wpdb;

		$table = Coywolf_Search_Schema::terms_table();
		$out   = array();

		foreach ( array_chunk( $terms, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated from the value count, then bound below.
				"SELECT term_id, term FROM %i WHERE term IN ({$placeholders})",
				array_merge( array( $table ), $chunk )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; reading the plugin's own vocabulary table.
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$out[ (string) $row['term'] ] = (int) $row['term_id'];
			}
		}

		return $out;
	}

	/**
	 * Bulk-insert posting rows.
	 *
	 * @param array<int,array{0:int,1:int,2:int,3:int,4:int}> $rows Rows.
	 */
	private function insert_postings( array $rows ) {
		global $wpdb;

		$table = Coywolf_Search_Schema::postings_table();

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $row ) {
				$values = array_merge( $values, $row );
			}
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '(%d,%d,%d,%d,%d)' ) );
			$sql          = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated from the row count, then bound below.
				"INSERT INTO %i (term_id, post_id, field, tf, dl) VALUES {$placeholders}",
				array_merge( array( $table ), $values )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; writing to the plugin's own postings table.
			$wpdb->query( $sql );
		}
	}

	/**
	 * Adjust document frequency for a set of terms.
	 *
	 * @param int[] $term_ids Term IDs.
	 * @param int   $delta    +1 when a document gains the terms, -1 when it loses them.
	 */
	private function bump_doc_freq( array $term_ids, $delta ) {
		global $wpdb;

		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
		if ( empty( $term_ids ) ) {
			return;
		}

		$table = Coywolf_Search_Schema::terms_table();

		foreach ( array_chunk( $term_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			if ( $delta > 0 ) {
				$sql = $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated from the ID count, then bound below.
					"UPDATE %i SET doc_freq = doc_freq + 1 WHERE term_id IN ({$placeholders})",
					array_merge( array( $table ), $chunk )
				);
			} else {
				// Floor at zero so a double-removal can't wrap the unsigned column.
				$sql = $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated from the ID count, then bound below.
					"UPDATE %i SET doc_freq = CASE WHEN doc_freq > 0 THEN doc_freq - 1 ELSE 0 END WHERE term_id IN ({$placeholders})",
					array_merge( array( $table ), $chunk )
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; updating the plugin's own vocabulary table.
			$wpdb->query( $sql );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Statistics                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Default statistics shape.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_stats() {
		return array(
			'doc_count'     => 0,
			'field_tokens'  => array(),
			'field_docs'    => array(),
			'terms'         => 0,
			'last_build'    => 0,
			'pipeline_hash' => '',
		);
	}

	/**
	 * Current index statistics.
	 *
	 * @return array<string,mixed>
	 */
	public static function stats() {
		$stats = get_option( self::STATS_OPTION, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}
		return array_merge( self::default_stats(), $stats );
	}

	/**
	 * Replace the statistics wholesale.
	 *
	 * @param array<string,mixed> $stats Statistics.
	 */
	public static function update_stats( array $stats ) {
		update_option( self::STATS_OPTION, array_merge( self::default_stats(), $stats ), false );
	}

	/**
	 * Apply one document's field lengths to the running totals.
	 *
	 * BM25 needs the average field length across the corpus; keeping running
	 * sums here avoids a full-table aggregate on every search.
	 *
	 * @param array<int,int> $field_totals Field ID => token count.
	 * @param int            $sign         +1 when adding a document, -1 when removing.
	 */
	private function adjust_stats( array $field_totals, $sign ) {
		if ( empty( $field_totals ) ) {
			return;
		}

		$stats = self::stats();

		$stats['doc_count'] = max( 0, (int) $stats['doc_count'] + $sign );

		foreach ( $field_totals as $field => $length ) {
			$field = (int) $field;

			$tokens = isset( $stats['field_tokens'][ $field ] ) ? (int) $stats['field_tokens'][ $field ] : 0;
			$docs   = isset( $stats['field_docs'][ $field ] ) ? (int) $stats['field_docs'][ $field ] : 0;

			$stats['field_tokens'][ $field ] = max( 0, $tokens + ( $sign * (int) $length ) );
			$stats['field_docs'][ $field ]   = max( 0, $docs + $sign );
		}

		self::update_stats( $stats );
	}

	/**
	 * Recount the vocabulary and store it in the statistics.
	 */
	public function refresh_term_count() {
		global $wpdb;

		if ( ! Coywolf_Search_Schema::tables_exist() ) {
			return;
		}

		$table = Coywolf_Search_Schema::terms_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting rows in the plugin's own vocabulary table.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE doc_freq > 0', $table ) );

		$stats          = self::stats();
		$stats['terms'] = $count;
		self::update_stats( $stats );
	}

	/**
	 * Whether the index holds anything worth searching.
	 *
	 * @return bool
	 */
	public static function has_index() {
		$stats = self::stats();
		return (int) $stats['doc_count'] > 0;
	}
}
