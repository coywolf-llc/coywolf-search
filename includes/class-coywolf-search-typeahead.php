<?php
/**
 * The titles-only payload the browser searches while you type.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves a compact list of published titles for the client-side index.
 *
 * Suggestions have to appear on the keystroke, not a round trip later, so the
 * browser needs its own copy of something searchable. Titles are enough for
 * that: they are short, they are what people recognise in a suggestion list,
 * and the debounced server request fills in everything titles can't answer.
 */
final class Coywolf_Search_Typeahead {

	const CACHE_KEY = 'coywolf_search_typeahead_docs';

	/**
	 * Most titles shipped to the browser.
	 */
	const MAX_DOCS = 10000;

	/**
	 * Register the payload route.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'coywolf_search_index_rebuilt', array( __CLASS__, 'invalidate' ) );
	}

	/**
	 * Route definition.
	 */
	public function register_routes() {
		register_rest_route(
			Coywolf_Search_REST_Controller::NAMESPACE_V1,
			'/typeahead',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_docs' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'v' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Return the payload.
	 *
	 * The URL carries the index version, so the response can be cached hard —
	 * a new index means a new URL, and the stale copy is simply never asked
	 * for again.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_docs() {
		$version = self::version();
		$etag    = '"' . $version . '"';

		// A validator alongside the cache header: page-cache and CDN layers
		// routinely rewrite Cache-Control to max-age=0, and without an ETag
		// that turns every fetch into a full re-download of the payload. With
		// one, the browser revalidates and gets a 304 instead.
		$sent = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) )
			: '';

		if ( '' !== $sent && trim( $sent, 'W/' ) === $etag ) {
			$response = new WP_REST_Response( null, 304 );
			$response->header( 'ETag', $etag );
			$response->header( 'Cache-Control', 'public, max-age=86400' );
			return $response;
		}

		$response = new WP_REST_Response(
			array(
				'v'    => $version,
				'docs' => self::docs(),
			),
			200
		);

		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'public, max-age=86400' );

		return $response;
	}

	/**
	 * A fingerprint that changes whenever the payload would.
	 *
	 * @return string
	 */
	public static function version() {
		$stats = Coywolf_Search_Indexer::stats();

		return substr(
			md5(
				(string) wp_json_encode(
					array(
						(int) $stats['doc_count'],
						(int) $stats['last_build'],
						(int) $stats['terms'],
						Coywolf_Search_Settings::pipeline_hash(),
					)
				)
			),
			0,
			12
		);
	}

	/**
	 * The titles themselves, cached.
	 *
	 * Stored as tuples rather than objects because this is the one payload
	 * every visitor downloads, and the key names would otherwise be repeated
	 * once per post.
	 *
	 * @return array<int,array{0:int,1:string,2:string,3:string}>
	 */
	public static function docs() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$types = (array) Coywolf_Search_Settings::get( 'post_types' );
		if ( empty( $types ) ) {
			return array();
		}

		/**
		 * Filters how many titles are sent to the browser.
		 *
		 * Beyond the cap the server-side results still cover everything; only
		 * the instant local suggestions are limited.
		 *
		 * @param int $max Maximum documents.
		 */
		$max = (int) apply_filters( 'coywolf_search_typeahead_cap', self::MAX_DOCS );

		$query = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, $max ),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// Password-protected posts are excluded here as well as at
				// index time: this payload is public, static, and cached, so
				// anything in it is readable by anyone who fetches the URL.
				'has_password'           => false,
				'coywolf_search_bypass'  => true,
			)
		);

		$labels = array();
		$docs   = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			if ( '' !== (string) $post->post_password ) {
				continue;
			}

			if ( ! isset( $labels[ $post->post_type ] ) ) {
				$type                        = get_post_type_object( $post->post_type );
				$labels[ $post->post_type ] = $type ? $type->labels->singular_name : $post->post_type;
			}

			$docs[] = array(
				(int) $post->ID,
				wp_strip_all_tags( get_the_title( $post ) ),
				get_permalink( $post ),
				$labels[ $post->post_type ],
			);
		}

		set_transient( self::CACHE_KEY, $docs, DAY_IN_SECONDS );

		return $docs;
	}

	/**
	 * Drop the cached payload.
	 */
	public static function invalidate() {
		delete_transient( self::CACHE_KEY );
	}
}
