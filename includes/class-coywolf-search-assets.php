<?php
/**
 * Front-end asset loading.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the typeahead only on pages that actually have a search box.
 *
 * A search plugin has no business adding bytes to a page with nothing to
 * search from, so nothing is enqueued until something on the page proves a
 * search UI exists — the results page itself, a rendered `core/search` block,
 * or a call to `get_search_form()`. Themes that hand-roll a form can opt in
 * through the `coywolf_search_should_enqueue` filter.
 */
final class Coywolf_Search_Assets {

	const HANDLE_SCRIPT     = 'coywolf-search-typeahead';
	const HANDLE_STYLE      = 'coywolf-search-typeahead';
	const HANDLE_MINISEARCH = 'coywolf-search-minisearch';

	/**
	 * Whether a search UI has been seen on this page.
	 *
	 * @var bool
	 */
	private $needed = false;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ) );

		add_filter( 'get_search_form', array( $this, 'flag_from_search_form' ) );
		add_filter( 'render_block', array( $this, 'flag_from_block' ), 10, 2 );

		// Late enough that both signals above have had a chance to fire while
		// the page rendered, early enough that WordPress has not yet printed
		// the footer scripts.
		add_action( 'wp_footer', array( $this, 'maybe_enqueue' ), 1 );
	}

	/**
	 * Register the assets without enqueuing any of them.
	 */
	public function register() {
		if ( ! Coywolf_Search_Settings::get( 'typeahead' ) ) {
			return;
		}

		wp_register_style(
			self::HANDLE_STYLE,
			COYWOLF_SEARCH_URL . 'assets/css/typeahead.css',
			array(),
			COYWOLF_SEARCH_VERSION
		);

		// Registered but never enqueued: the browser fetches it from the URL
		// below only once a visitor actually interacts with a search box, so a
		// page nobody searches from never pays for it.
		wp_register_script(
			self::HANDLE_MINISEARCH,
			COYWOLF_SEARCH_URL . 'assets/js/vendor/minisearch/minisearch.umd.js',
			array(),
			COYWOLF_SEARCH_VERSION,
			true
		);

		wp_register_script(
			self::HANDLE_SCRIPT,
			COYWOLF_SEARCH_URL . 'assets/js/typeahead.js',
			array(),
			COYWOLF_SEARCH_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// The boot config is attached in maybe_enqueue(), not here: building it
		// reads the index stats, and registration runs on every front-end page
		// view while the config is only needed on pages that enqueue.
	}

	/**
	 * Boot data for the client.
	 *
	 * @return array<string,mixed>
	 */
	private function config() {
		$version = Coywolf_Search_Typeahead::version();

		return array(
			'searchUrl'     => rest_url( Coywolf_Search_REST_Controller::NAMESPACE_V1 . '/search' ),
			'docsUrl'       => add_query_arg( 'v', $version, rest_url( Coywolf_Search_REST_Controller::NAMESPACE_V1 . '/typeahead' ) ),
			'docsVersion'   => $version,
			'libraryUrl'    => COYWOLF_SEARCH_URL . 'assets/js/vendor/minisearch/minisearch.umd.js?ver=' . rawurlencode( COYWOLF_SEARCH_VERSION ),
			'max'           => (int) Coywolf_Search_Settings::get( 'typeahead_max' ),
			'debounce'      => (int) Coywolf_Search_Settings::get( 'typeahead_debounce' ),
			'enterOpensTop' => (bool) Coywolf_Search_Settings::get( 'typeahead_enter' ),
			'showType'      => (bool) Coywolf_Search_Settings::get( 'typeahead_show_type' ),
			'minChars'      => max( 1, (int) Coywolf_Search_Settings::get( 'min_token_length' ) ),
			'strings'       => array(
				'clear'      => __( 'Clear search', 'coywolf-search' ),
				'listLabel'  => __( 'Search suggestions', 'coywolf-search' ),
				'noResults'  => __( 'No matching results.', 'coywolf-search' ),
				/* translators: %d: number of suggestions available. */
				'available'  => __( '%d suggestions available.', 'coywolf-search' ),
				'hint'       => __( 'Use the arrow keys to review suggestions and Enter to open one. Escape closes the list.', 'coywolf-search' ),
			),
		);
	}

	/**
	 * The configured colours, as a custom-property declaration.
	 *
	 * Only colours that were actually set are emitted, so a site that has
	 * changed nothing ships no extra bytes and the stylesheet's own
	 * theme-following fallbacks stay in charge. Values come out of
	 * sanitize_hex_color(), so they are `#rgb`/`#rrggbb` or nothing at all.
	 *
	 * @return string CSS, or an empty string when nothing is configured.
	 */
	private function colour_css() {
		$declarations = array();

		foreach ( Coywolf_Search_Settings::colour_map() as $key => $property ) {
			$value = (string) Coywolf_Search_Settings::get( $key );
			if ( '' === $value ) {
				continue;
			}
			$declarations[] = $property . ':' . $value;

			// A chosen snippet colour is shown at full strength; the default
			// dimming only exists to soften inherited body text.
			if ( 'colour_snippet' === $key ) {
				$declarations[] = '--coywolf-search-snippet-opacity:1';
			}
		}

		if ( empty( $declarations ) ) {
			return '';
		}

		return '.coywolf-search-suggestions{' . implode( ';', $declarations ) . '}';
	}

	/**
	 * A theme rendered a search form.
	 *
	 * @param string $form The form markup.
	 * @return string
	 */
	public function flag_from_search_form( $form ) {
		$this->needed = true;
		return $form;
	}

	/**
	 * The block editor rendered a search block.
	 *
	 * @param string               $content Rendered block content.
	 * @param array<string,mixed>  $block   Block data.
	 * @return string
	 */
	public function flag_from_block( $content, $block ) {
		if ( isset( $block['blockName'] ) && 'core/search' === $block['blockName'] ) {
			$this->needed = true;
		}
		return $content;
	}

	/**
	 * Enqueue if this page has somewhere to type.
	 */
	public function maybe_enqueue() {
		if ( ! Coywolf_Search_Settings::get( 'typeahead' ) ) {
			return;
		}

		$needed = $this->needed || is_search();

		/**
		 * Filters whether the typeahead assets load on this request.
		 *
		 * Themes with a hand-rolled search form that goes through neither
		 * `get_search_form()` nor the `core/search` block can return true here.
		 *
		 * @param bool $needed Whether a search UI was detected.
		 */
		if ( ! apply_filters( 'coywolf_search_should_enqueue', $needed ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE_STYLE );

		$colours = $this->colour_css();
		if ( '' !== $colours ) {
			wp_add_inline_style( self::HANDLE_STYLE, $colours );
		}

		wp_enqueue_script( self::HANDLE_SCRIPT );

		wp_add_inline_script(
			self::HANDLE_SCRIPT,
			'window.coywolfSearchTypeahead = ' . wp_json_encode( $this->config() ) . ';',
			'before'
		);
	}
}
