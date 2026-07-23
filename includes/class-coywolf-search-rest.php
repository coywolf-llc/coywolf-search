<?php
/**
 * Public read-only search endpoint.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET coywolf-search/v1/search
 *
 * Returns the same ranked results the front-end search page shows, with a
 * highlighted snippet per result. Public and anonymous, because it answers
 * queries about content that is already public — but it is throttled, and it
 * validates before it writes anything.
 */
final class Coywolf_Search_REST_Controller {

	const NAMESPACE_V1 = 'coywolf-search/v1';

	/**
	 * Longest query string accepted.
	 */
	const MAX_QUERY_LENGTH = 200;

	/**
	 * Requests allowed per throttle bucket per window.
	 */
	const RATE_LIMIT = 120;

	/**
	 * Throttle window, in seconds.
	 */
	const RATE_WINDOW = 60;

	/**
	 * Characters of the hashed client address used as the bucket key.
	 *
	 * Three hex characters is 4,096 buckets. Bucketing rather than keying on
	 * the address itself is deliberate: the address can be influenced by a
	 * forwarded header, and a transient keyed directly on it would let someone
	 * write an unbounded number of option rows just by varying that header.
	 * A fixed keyspace caps what the throttle can ever accumulate.
	 */
	const BUCKET_CHARS = 3;

	/**
	 * Multiple of the per-visitor limit allowed per connecting address.
	 *
	 * The forwarded header the visitor-level throttle buckets on is spoofable,
	 * so on its own it can be rotated past. This second layer counts requests
	 * by the address the connection actually came from, which cannot be faked.
	 * It is deliberately loose — behind a proxy that address is an edge server
	 * shared by many real visitors — but it turns "unlimited via header
	 * rotation" into a hard per-connection ceiling.
	 */
	const TRANSPORT_MULTIPLIER = 10;

	/**
	 * Register the route.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route definition.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_query' ),
					),
					'page'     => array(
						'default'           => 1,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_page' ),
					),
					'per_page' => array(
						'default'           => 10,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_per_page' ),
					),
				),
			)
		);
	}

	/**
	 * A query has to be non-empty and of sane length.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function validate_query( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$trimmed = trim( $value );
		return '' !== $trimmed && strlen( $trimmed ) <= self::MAX_QUERY_LENGTH;
	}

	/**
	 * Page numbers are positive and bounded.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function validate_page( $value ) {
		return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 1000;
	}

	/**
	 * Page size is bounded.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function validate_per_page( $value ) {
		return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 50;
	}

	/**
	 * Answer a search request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_search( $request ) {
		// The arguments above are validated by the REST server before this
		// runs, so the throttle only ever records requests that were already
		// well-formed. Recording first would let malformed traffic fill the
		// options table.
		$throttled = $this->throttle();
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$query    = (string) $request->get_param( 'q' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, (int) $request->get_param( 'per_page' ) );

		try {
			$engine = new Coywolf_Search_Query_Engine();
			$result = $engine->search(
				$query,
				array(
					'paged'    => $page,
					'per_page' => $per_page,
				)
			);
		} catch ( Throwable $e ) {
			$result = null;
		}

		if ( null === $result ) {
			return new WP_REST_Response(
				array(
					'results' => array(),
					'total'   => 0,
					'page'    => $page,
					'pages'   => 0,
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'results' => $this->shape( $result['ids'], $query ),
				'total'   => (int) $result['total'],
				'page'    => $page,
				'pages'   => (int) ceil( $result['total'] / $per_page ),
			),
			200
		);
	}

	/**
	 * Turn ranked IDs into the payload the client renders.
	 *
	 * @param int[]  $ids   Ranked post IDs.
	 * @param string $query The search query.
	 * @return array<int,array<string,mixed>>
	 */
	private function shape( array $ids, $query ) {
		if ( empty( $ids ) ) {
			return array();
		}

		_prime_post_caches( $ids, false, false );

		$tokenizer = Coywolf_Search_Tokenizer::from_settings();
		$out       = array();

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$type   = get_post_type_object( $post->post_type );
			$source = $tokenizer->clean( $post->post_excerpt );
			if ( '' === $source ) {
				$source = $tokenizer->clean( $post->post_content );
			}

			$out[] = array(
				'id'         => (int) $post->ID,
				'title'      => wp_strip_all_tags( get_the_title( $post ) ),
				'url'        => get_permalink( $post ),
				'date'       => get_the_date( 'c', $post ),
				'type'       => $post->post_type,
				'type_label' => $type ? $type->labels->singular_name : $post->post_type,
				'snippet'    => $this->snippet( $source, $query ),
			);
		}

		return $out;
	}

	/**
	 * Build a highlighted excerpt around the first match.
	 *
	 * The text is escaped first and the highlight markup added afterwards, so
	 * the only tags that can appear in a snippet are the ones added here.
	 *
	 * @param string $text  Cleaned plain-text projection of the post.
	 * @param string $query The search query.
	 * @return string Escaped HTML containing only <mark> tags.
	 */
	private function snippet( $text, $query ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( '' === $text ) {
			return '';
		}

		$words = $this->highlight_words( $query );
		$width = 180;
		$start = 0;

		foreach ( $words as $word ) {
			$position = $this->find( $text, $word );
			if ( false !== $position ) {
				// Leave a little context before the match rather than starting
				// on it, unless it is already near the beginning.
				$start = max( 0, $position - 40 );
				break;
			}
		}

		$excerpt = $this->substr( $text, $start, $width );

		if ( $start > 0 ) {
			$excerpt = '…' . $excerpt;
		}
		if ( $this->strlen( $text ) > $start + $width ) {
			$excerpt .= '…';
		}

		$escaped = esc_html( $excerpt );

		if ( empty( $words ) ) {
			return $escaped;
		}

		$pattern = '/(' . implode( '|', array_map( 'preg_quote', $words, array_fill( 0, count( $words ), '/' ) ) ) . ')/iu';

		return (string) preg_replace( $pattern, '<mark>$1</mark>', $escaped );
	}

	/**
	 * The words to highlight.
	 *
	 * Taken from what the visitor typed rather than from the index terms:
	 * stemming and accent folding mean an index term often is not the string
	 * that appears in the text, and highlighting something the reader did not
	 * type reads as a bug.
	 *
	 * @param string $query The search query.
	 * @return string[]
	 */
	private function highlight_words( $query ) {
		$parts = preg_split( '/[\p{Z}\p{P}\p{C}\s]+/u', (string) $query, -1, PREG_SPLIT_NO_EMPTY );

		$out = array();
		foreach ( (array) $parts as $part ) {
			if ( $this->strlen( $part ) >= 2 ) {
				$out[] = $part;
			}
		}

		// Longest first, so "photograph" wins over "photo" and the shorter
		// word can't split the longer one's highlight in two.
		usort(
			$out,
			function ( $a, $b ) {
				return $this->strlen( $b ) <=> $this->strlen( $a );
			}
		);

		return array_slice( $out, 0, 10 );
	}

	/**
	 * Case-insensitive position of a needle.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return int|false
	 */
	private function find( $haystack, $needle ) {
		if ( function_exists( 'mb_stripos' ) ) {
			return mb_stripos( $haystack, $needle, 0, 'UTF-8' );
		}
		return stripos( $haystack, $needle );
	}

	/**
	 * Multibyte-safe substring.
	 *
	 * @param string $text   Text.
	 * @param int    $start  Start offset.
	 * @param int    $length Length.
	 * @return string
	 */
	private function substr( $text, $start, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $start, $length, 'UTF-8' );
		}
		return substr( $text, $start, $length );
	}

	/**
	 * Multibyte-safe length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private function strlen( $text ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}
		return strlen( $text );
	}

	/* --------------------------------------------------------------------- */
	/* Throttle                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Count this request against its buckets, refusing it if either is full.
	 *
	 * Two layers, because the two addresses involved have opposite failure
	 * modes. The visitor layer buckets on the forwarded client address — fair
	 * to individual visitors behind a shared proxy, but spoofable, since the
	 * header is caller-supplied. The transport layer buckets on the connecting
	 * address — unforgeable, but shared by everyone behind the same proxy
	 * edge, so its limit is a multiple of the visitor limit. Rotating the
	 * forwarded header now buys nothing: every spoofed request still lands in
	 * the same transport bucket.
	 *
	 * Buckets are derived with wp_hash(), not plain md5: a salted hash means
	 * an attacker cannot precompute which forged address shares a victim's
	 * bucket, which would otherwise allow filling a target's bucket on
	 * purpose.
	 *
	 * @return true|WP_Error
	 */
	private function throttle() {
		/**
		 * Filters the per-window request allowance.
		 *
		 * @param int $limit Requests per window.
		 */
		$limit = (int) apply_filters( 'coywolf_search_rate_limit', self::RATE_LIMIT );

		if ( $limit <= 0 ) {
			return true;
		}

		$layers = array(
			array( 'coywolf_search_rl_', $this->client_ip(), $limit ),
			array( 'coywolf_search_rlt_', $this->remote_addr(), $limit * self::TRANSPORT_MULTIPLIER ),
		);

		$keys = array();
		foreach ( $layers as $layer ) {
			$key   = $layer[0] . substr( wp_hash( $layer[1] ), 0, self::BUCKET_CHARS );
			$count = (int) get_transient( $key );

			if ( $count >= $layer[2] ) {
				return new WP_Error(
					'coywolf_search_rate_limited',
					__( 'Too many searches. Please wait a moment and try again.', 'coywolf-search' ),
					array( 'status' => 429 )
				);
			}

			$keys[ $key ] = $count;
		}

		// Recorded only after both layers pass, so a refused request costs the
		// caller nothing and cannot be used to inflate someone else's bucket.
		foreach ( $keys as $key => $count ) {
			set_transient( $key, $count + 1, self::RATE_WINDOW );
		}

		return true;
	}

	/**
	 * The address this request actually connected from.
	 *
	 * @return string
	 */
	private function remote_addr() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return 'unknown';
		}

		$value = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : 'unknown';
	}

	/**
	 * The visitor's address, as best the server can tell.
	 *
	 * Behind a reverse proxy every request arrives from the proxy, so the
	 * forwarded header is the only way to tell visitors apart. It is also
	 * trivially spoofable, which is why the throttle hashes this into a fixed
	 * number of buckets rather than trusting it as an identity.
	 *
	 * @return string
	 */
	private function client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' );

		foreach ( $candidates as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				/**
				 * Filters the address the throttle buckets on.
				 *
				 * @param string $value  The detected address.
				 * @param string $header The header it came from.
				 */
				return (string) apply_filters( 'coywolf_search_client_ip', $value, $header );
			}
		}

		return (string) apply_filters( 'coywolf_search_client_ip', 'unknown', '' );
	}
}
