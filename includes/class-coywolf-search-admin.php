<?php
/**
 * Settings → Search.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's single admin screen.
 */
final class Coywolf_Search_Admin {

	const PAGE_SLUG = 'coywolf-search';
	const SCREEN_ID = 'settings_page_coywolf-search';

	/**
	 * Register the screen, settings, assets, and notices.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( 'Coywolf_Search_Settings', 'register' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Add the Settings → Search page.
	 */
	public function register_menu() {
		add_options_page(
			__( 'Search', 'coywolf-search' ),
			__( 'Search', 'coywolf-search' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Assets for this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'coywolf-search-admin',
			COYWOLF_SEARCH_URL . 'assets/css/admin.css',
			array(),
			COYWOLF_SEARCH_VERSION
		);

		wp_enqueue_script(
			'coywolf-search-admin',
			COYWOLF_SEARCH_URL . 'assets/js/admin.js',
			array(),
			COYWOLF_SEARCH_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			'coywolf-search-admin',
			'window.coywolfSearchAdmin = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => Coywolf_Search_Rebuilder::AJAX_ACTION,
					'nonce'   => wp_create_nonce( Coywolf_Search_Rebuilder::NONCE_ACTION ),
					'strings' => array(
						'confirm'  => __( 'Rebuild the search index now? Search keeps working while it runs.', 'coywolf-search' ),
						'building' => __( 'Building index…', 'coywolf-search' ),
						'done'     => __( 'Index rebuilt.', 'coywolf-search' ),
						'failed'   => __( 'The rebuild stopped unexpectedly. Try again.', 'coywolf-search' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/* --------------------------------------------------------------------- */
	/* Notices                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Notices, scoped to this screen.
	 */
	public function render_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::SCREEN_ID !== $screen->id ) {
			return;
		}

		if ( get_option( 'coywolf_search_needs_first_build' ) && ! Coywolf_Search_Indexer::has_index() ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html__( 'Coywolf Search is active but has no index yet. Build one below to start returning results.', 'coywolf-search' )
			);
		}

		if ( get_option( 'coywolf_search_index_stale' ) && Coywolf_Search_Indexer::has_index() ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'You changed a setting that affects how content is indexed. Rebuild the index so searches match what is stored.', 'coywolf-search' )
			);
		}
	}

	/* --------------------------------------------------------------------- */
	/* Fields                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Register every section and field.
	 */
	public function register_fields() {
		$page = self::PAGE_SLUG;

		add_settings_section(
			'coywolf_search_content',
			__( 'Content', 'coywolf-search' ),
			array( $this, 'section_content' ),
			$page
		);
		$this->add_field( 'post_types', __( 'Post types to index', 'coywolf-search' ), 'field_post_types', $page, 'coywolf_search_content' );
		$this->add_field( 'taxonomies', __( 'Taxonomies to index', 'coywolf-search' ), 'field_taxonomies', $page, 'coywolf_search_content' );
		$this->add_field( 'fields', __( 'Fields to index', 'coywolf-search' ), 'field_fields', $page, 'coywolf_search_content' );

		add_settings_section(
			'coywolf_search_ranking',
			__( 'Ranking and matching', 'coywolf-search' ),
			array( $this, 'section_ranking' ),
			$page
		);
		$this->add_field( 'weights', __( 'Field weights', 'coywolf-search' ), 'field_weights', $page, 'coywolf_search_ranking' );
		$this->add_field( 'bm25', __( 'BM25 tuning', 'coywolf-search' ), 'field_bm25', $page, 'coywolf_search_ranking' );
		$this->add_field( 'fuzzy', __( 'Fuzzy matching', 'coywolf-search' ), 'field_fuzzy', $page, 'coywolf_search_ranking' );
		$this->add_field( 'prefix_last', __( 'Prefix matching', 'coywolf-search' ), 'field_prefix', $page, 'coywolf_search_ranking' );
		$this->add_field( 'or_fallback', __( 'No-results fallback', 'coywolf-search' ), 'field_or_fallback', $page, 'coywolf_search_ranking' );

		add_settings_section(
			'coywolf_search_tokenization',
			__( 'Tokenization', 'coywolf-search' ),
			array( $this, 'section_tokenization' ),
			$page
		);
		$this->add_field( 'min_token_length', __( 'Minimum token length', 'coywolf-search' ), 'field_min_token', $page, 'coywolf_search_tokenization' );
		$this->add_field( 'stopwords', __( 'Stopwords', 'coywolf-search' ), 'field_stopwords', $page, 'coywolf_search_tokenization' );
		$this->add_field( 'stemming', __( 'Stemming', 'coywolf-search' ), 'field_stemming', $page, 'coywolf_search_tokenization' );

		add_settings_section(
			'coywolf_search_typeahead',
			__( 'Typeahead', 'coywolf-search' ),
			array( $this, 'section_typeahead' ),
			$page
		);
		$this->add_field( 'typeahead', __( 'Instant suggestions', 'coywolf-search' ), 'field_typeahead', $page, 'coywolf_search_typeahead' );
	}

	/**
	 * Shorthand for add_settings_field.
	 *
	 * @param string $id       Field ID.
	 * @param string $label    Field label.
	 * @param string $callback Method on this class.
	 * @param string $page     Page slug.
	 * @param string $section  Section ID.
	 */
	private function add_field( $id, $label, $callback, $page, $section ) {
		add_settings_field(
			'coywolf_search_' . $id,
			$label,
			array( $this, $callback ),
			$page,
			$section
		);
	}

	/**
	 * Content section description.
	 */
	public function section_content() {
		echo '<p>' . esc_html__( 'What goes into the index. Changing anything here requires a rebuild.', 'coywolf-search' ) . '</p>';
	}

	/**
	 * Ranking section description.
	 */
	public function section_ranking() {
		echo '<p>' . esc_html__( 'How results are scored and how forgiving matching is. These take effect immediately — no rebuild needed.', 'coywolf-search' ) . '</p>';
	}

	/**
	 * Tokenization section description.
	 */
	public function section_tokenization() {
		echo '<p>' . esc_html__( 'How text is split into searchable terms. Changing anything here requires a rebuild.', 'coywolf-search' ) . '</p>';
	}

	/**
	 * Typeahead section description.
	 */
	public function section_typeahead() {
		echo '<p>' . esc_html__( 'Suggestions shown as a visitor types in a search box.', 'coywolf-search' ) . '</p>';
	}

	/* --------------------------------------------------------------------- */
	/* Field renderers                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Post type checkboxes.
	 */
	public function field_post_types() {
		$selected = (array) Coywolf_Search_Settings::get( 'post_types' );
		foreach ( Coywolf_Search_Settings::available_post_types() as $slug => $label ) {
			$this->checkbox( 'post_types', $slug, $label, in_array( $slug, $selected, true ) );
		}
		echo '<p class="description">' . esc_html__( 'Password-protected posts are never indexed, and drafts, private, and scheduled posts only enter the index once they are published.', 'coywolf-search' ) . '</p>';
	}

	/**
	 * Taxonomy checkboxes.
	 */
	public function field_taxonomies() {
		$selected = (array) Coywolf_Search_Settings::get( 'taxonomies' );
		foreach ( Coywolf_Search_Settings::available_taxonomies() as $slug => $label ) {
			$this->checkbox( 'taxonomies', $slug, $label, in_array( $slug, $selected, true ) );
		}
		echo '<p class="description">' . esc_html__( 'Term names are indexed alongside the post, so a search for a category name finds posts in it.', 'coywolf-search' ) . '</p>';
	}

	/**
	 * Field checkboxes.
	 */
	public function field_fields() {
		$selected = (array) Coywolf_Search_Settings::get( 'fields' );
		$labels   = array(
			'title'   => __( 'Title', 'coywolf-search' ),
			'content' => __( 'Content', 'coywolf-search' ),
			'excerpt' => __( 'Excerpt', 'coywolf-search' ),
		);
		foreach ( $labels as $slug => $label ) {
			$this->checkbox( 'fields', $slug, $label, in_array( $slug, $selected, true ) );
		}
	}

	/**
	 * Per-field weight inputs.
	 */
	public function field_weights() {
		$labels = array(
			'weight_title'    => __( 'Title', 'coywolf-search' ),
			'weight_content'  => __( 'Content', 'coywolf-search' ),
			'weight_excerpt'  => __( 'Excerpt', 'coywolf-search' ),
			'weight_taxonomy' => __( 'Taxonomy', 'coywolf-search' ),
		);
		foreach ( $labels as $key => $label ) {
			$this->number( $key, $label, 0, 10, 0.1 );
		}
		echo '<p class="description">' . esc_html__( 'A match in a higher-weighted field counts for more. Set a weight to 0 to ignore that field when ranking.', 'coywolf-search' ) . '</p>';
	}

	/**
	 * BM25 inputs.
	 */
	public function field_bm25() {
		$this->number( 'bm25_k1', __( 'k1 (term frequency saturation)', 'coywolf-search' ), 0, 3, 0.1 );
		$this->number( 'bm25_b', __( 'b (length normalisation)', 'coywolf-search' ), 0, 1, 0.05 );
		echo '<p class="description">' . esc_html__( 'Defaults of 1.2 and 0.75 suit most sites. Raise k1 to reward repeated terms more; lower b to stop long posts being penalised.', 'coywolf-search' ) . '</p>';
	}

	/**
	 * Fuzzy matching controls.
	 */
	public function field_fuzzy() {
		$value   = Coywolf_Search_Settings::get( 'fuzzy' );
		$options = array(
			'off'  => __( 'Off', 'coywolf-search' ),
			'one'  => __( 'One character', 'coywolf-search' ),
			'auto' => __( 'Automatic (two for long terms)', 'coywolf-search' ),
		);
		echo '<select name="' . esc_attr( Coywolf_Search_Settings::OPTION . '[fuzzy]' ) . '">';
		foreach ( $options as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select> ';
		$this->number( 'fuzzy_min_length', __( 'Minimum term length', 'coywolf-search' ), 2, 12, 1 );
		echo '<p class="description">' . esc_html__( 'Tolerates typos. Unavailable on very large vocabularies, where the index status below says so.', 'coywolf-search' ) . '</p>';
	}

	/**
	 * Prefix matching controls.
	 */
	public function field_prefix() {
		$this->toggle( 'prefix_last', __( 'Match the last word as a prefix', 'coywolf-search' ) );
		$this->number( 'max_expansions', __( 'Maximum term expansions', 'coywolf-search' ), 1, 500, 1 );
	}

	/**
	 * OR fallback toggle.
	 */
	public function field_or_fallback() {
		$this->toggle( 'or_fallback', __( 'When no result contains every word, show results containing some of them', 'coywolf-search' ) );
	}

	/**
	 * Minimum token length.
	 */
	public function field_min_token() {
		$this->number( 'min_token_length', __( 'Characters', 'coywolf-search' ), 1, 10, 1 );
	}

	/**
	 * Stopword controls.
	 */
	public function field_stopwords() {
		$mode    = Coywolf_Search_Settings::get( 'stopwords_mode' );
		$options = array(
			'none'    => __( 'Index every word', 'coywolf-search' ),
			'builtin' => __( 'Skip common English words', 'coywolf-search' ),
			'custom'  => __( 'Skip my own list', 'coywolf-search' ),
		);
		echo '<select name="' . esc_attr( Coywolf_Search_Settings::OPTION . '[stopwords_mode]' ) . '">';
		foreach ( $options as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $mode, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		printf(
			'<p><textarea name="%s" rows="6" cols="40" class="large-text code" placeholder="%s">%s</textarea></p>',
			esc_attr( Coywolf_Search_Settings::OPTION . '[stopwords_custom]' ),
			esc_attr__( 'One word per line', 'coywolf-search' ),
			esc_textarea( (string) Coywolf_Search_Settings::get( 'stopwords_custom' ) )
		);
	}

	/**
	 * Stemming toggle.
	 */
	public function field_stemming() {
		$this->toggle( 'stemming', __( 'Match different forms of the same word (run, running, ran)', 'coywolf-search' ) );
		echo '<p class="description">' . esc_html__( 'English only. Turning this on or off changes what is stored, so the index needs rebuilding.', 'coywolf-search' ) . '</p>';
	}

	/**
	 * Typeahead controls.
	 */
	public function field_typeahead() {
		$this->toggle( 'typeahead', __( 'Suggest results as visitors type', 'coywolf-search' ) );
		$this->number( 'typeahead_max', __( 'Maximum suggestions', 'coywolf-search' ), 1, 20, 1 );
		$this->number( 'typeahead_debounce', __( 'Delay before searching (ms)', 'coywolf-search' ), 0, 2000, 10 );
		$this->toggle( 'typeahead_enter', __( 'Pressing Enter opens the top suggestion', 'coywolf-search' ) );
		echo '<p class="description">' . esc_html__( 'With Enter-to-open off, Enter submits the search form as usual. Suggestions always fall back to a normal search when JavaScript is unavailable.', 'coywolf-search' ) . '</p>';
	}

	/**
	 * Render one checkbox of a multi-value list.
	 *
	 * The name has to be `option[key][]`, not `option[key[]]` — PHP does not
	 * parse nested brackets, so the latter silently arrives as no value at all
	 * and the sanitiser stores an empty list.
	 *
	 * @param string $key     Setting key holding the list.
	 * @param string $value   Checkbox value.
	 * @param string $label   Label text.
	 * @param bool   $checked Whether it is checked.
	 */
	private function checkbox( $key, $value, $label, $checked ) {
		printf(
			'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%s" value="%s"%s> %s</label>',
			esc_attr( Coywolf_Search_Settings::OPTION . '[' . $key . '][]' ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Render a boolean toggle.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label text.
	 */
	private function toggle( $key, $label ) {
		printf(
			'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%s" value="1"%s> %s</label>',
			esc_attr( Coywolf_Search_Settings::OPTION . '[' . $key . ']' ),
			checked( (bool) Coywolf_Search_Settings::get( $key ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Render a labelled number input.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label text.
	 * @param float  $min   Minimum.
	 * @param float  $max   Maximum.
	 * @param float  $step  Step.
	 */
	private function number( $key, $label, $min, $max, $step ) {
		printf(
			'<label style="display:inline-block;margin:0 12px 6px 0">%s <input type="number" name="%s" value="%s" min="%s" max="%s" step="%s" class="small-text"></label>',
			esc_html( $label ),
			esc_attr( Coywolf_Search_Settings::OPTION . '[' . $key . ']' ),
			esc_attr( (string) Coywolf_Search_Settings::get( $key ) ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max ),
			esc_attr( (string) $step )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Page                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * The numbers shown on the index status card.
	 *
	 * @return array<string,string>
	 */
	public static function stats_payload() {
		$stats = Coywolf_Search_Indexer::stats();

		$last_build = (int) $stats['last_build'];

		return array(
			'documents' => number_format_i18n( (int) $stats['doc_count'] ),
			'terms'     => number_format_i18n( (int) $stats['terms'] ),
			'queue'     => number_format_i18n( ( new Coywolf_Search_Indexer() )->queue_depth() ),
			'built'     => $last_build > 0
				? sprintf(
					/* translators: %s: human-readable time difference, e.g. "2 hours". */
					__( '%s ago', 'coywolf-search' ),
					human_time_diff( $last_build )
				)
				: __( 'Never', 'coywolf-search' ),
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stats = self::stats_payload();
		?>
		<div class="wrap coywolf-search-settings">
			<h1><?php esc_html_e( 'Search', 'coywolf-search' ); ?></h1>

			<div class="coywolf-search-card">
				<h2><?php esc_html_e( 'Index status', 'coywolf-search' ); ?></h2>
				<ul class="coywolf-search-stats">
					<li><strong data-coywolf-stat="documents"><?php echo esc_html( $stats['documents'] ); ?></strong> <span><?php esc_html_e( 'documents indexed', 'coywolf-search' ); ?></span></li>
					<li><strong data-coywolf-stat="terms"><?php echo esc_html( $stats['terms'] ); ?></strong> <span><?php esc_html_e( 'unique terms', 'coywolf-search' ); ?></span></li>
					<li><strong data-coywolf-stat="queue"><?php echo esc_html( $stats['queue'] ); ?></strong> <span><?php esc_html_e( 'waiting to reindex', 'coywolf-search' ); ?></span></li>
					<li><strong data-coywolf-stat="built"><?php echo esc_html( $stats['built'] ); ?></strong> <span><?php esc_html_e( 'last full rebuild', 'coywolf-search' ); ?></span></li>
				</ul>

				<?php if ( Coywolf_Search_Vocabulary::is_sql_mode() ) : ?>
					<p class="description"><?php esc_html_e( 'This site has a large vocabulary, so term expansion runs against the database and fuzzy matching is unavailable. Prefix matching still works.', 'coywolf-search' ); ?></p>
				<?php endif; ?>

				<p>
					<button type="button" class="button button-primary" id="coywolf-search-rebuild"><?php esc_html_e( 'Rebuild index', 'coywolf-search' ); ?></button>
					<span id="coywolf-search-progress" class="coywolf-search-progress" hidden>
						<span class="coywolf-search-progress-bar"><span></span></span>
						<span class="coywolf-search-progress-text"></span>
					</span>
				</p>

				<p class="description"><?php esc_html_e( 'Deactivating the plugin removes the index and hands search back to WordPress; your settings are kept. Uninstalling removes everything the plugin created.', 'coywolf-search' ); ?></p>
			</div>

			<form action="options.php" method="post">
				<?php
				settings_fields( Coywolf_Search_Settings::GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
