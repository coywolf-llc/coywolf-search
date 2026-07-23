<?php
/**
 * Front-end search replacement.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Substitutes the engine's ranked results for the main search query.
 *
 * Hooking `posts_pre_query` rather than rewriting the SQL keeps the theme's
 * own search template, pagination, and template tags working untouched — the
 * plugin changes which posts come back, not how they are displayed.
 *
 * Every path that can't produce a confident answer returns null, which makes
 * WordPress run its own query as though the plugin weren't installed. A search
 * feature is never worth a white screen.
 */
final class Coywolf_Search_Query_Integration {

	/**
	 * The query this instance is currently answering.
	 *
	 * @var WP_Query|null
	 */
	private $handled_query = null;

	/**
	 * Total matches for that query.
	 *
	 * @var int
	 */
	private $handled_total = 0;

	/**
	 * Register the filters.
	 */
	public function init() {
		add_filter( 'posts_pre_query', array( $this, 'intercept' ), 10, 2 );

		// For `fields => ids` queries WordPress calls set_found_posts() even
		// when posts_pre_query already returned rows, which would overwrite
		// the count with FOUND_ROWS() from an unrelated query. These two
		// filters keep the total correct on that path.
		add_filter( 'found_posts_query', array( $this, 'filter_found_posts_query' ), 10, 2 );
		add_filter( 'found_posts', array( $this, 'filter_found_posts' ), 10, 2 );
	}

	/**
	 * Answer the main front-end search query from the index.
	 *
	 * @param array|null $posts Posts, or null to let WordPress query.
	 * @param WP_Query   $query The query.
	 * @return array|null
	 */
	public function intercept( $posts, $query ) {
		if ( null !== $posts ) {
			return $posts;
		}

		if ( ! $this->should_handle( $query ) ) {
			return null;
		}

		try {
			$engine = new Coywolf_Search_Query_Engine();
			$result = $engine->search(
				(string) $query->get( 's' ),
				array(
					'post_types' => $query->get( 'post_type' ),
					'paged'      => max( 1, (int) $query->get( 'paged' ) ),
					'per_page'   => (int) $query->get( 'posts_per_page' ),
				)
			);
		} catch ( Throwable $e ) {
			return null;
		}

		if ( null === $result ) {
			return null;
		}

		$per_page = (int) $query->get( 'posts_per_page' );

		$this->handled_query = $query;
		$this->handled_total = (int) $result['total'];

		$query->found_posts   = (int) $result['total'];
		$query->max_num_pages = $per_page > 0 ? (int) ceil( $result['total'] / $per_page ) : 1;

		if ( 'ids' === $query->get( 'fields' ) ) {
			return $result['ids'];
		}

		if ( empty( $result['ids'] ) ) {
			return array();
		}

		_prime_post_caches( $result['ids'], true, true );

		$out = array();
		foreach ( $result['ids'] as $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				$out[] = $post;
			}
		}

		return $out;
	}

	/**
	 * Whether this query is one the engine should answer.
	 *
	 * @param WP_Query $query The query.
	 * @return bool
	 */
	private function should_handle( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return false;
		}

		// Front-end main search query only. Admin list-table searches keep
		// WordPress's own behaviour, which admins expect.
		if ( is_admin() ) {
			return false;
		}

		if ( ! $query->is_search() || ! $query->is_main_query() ) {
			return false;
		}

		if ( $query->get( 'coywolf_search_bypass' ) ) {
			return false;
		}

		if ( '' === trim( (string) $query->get( 's' ) ) ) {
			return false;
		}

		// The engine ranks by relevance and can't honour constraints it has no
		// index for. Anything that asks for a different ordering or a filtered
		// subset goes back to WordPress rather than silently ignoring it.
		if ( $query->get( 'orderby' ) ) {
			return false;
		}
		foreach ( array( 'tax_query', 'meta_query', 'post__in', 'author', 'year', 'monthnum', 'day' ) as $var ) {
			if ( $query->get( $var ) ) {
				return false;
			}
		}

		if ( ! Coywolf_Search_Indexer::has_index() ) {
			return false;
		}

		/**
		 * Filters whether the engine answers this request.
		 *
		 * Return false to fall back to native WordPress search.
		 *
		 * @param bool     $enabled Whether the engine handles the query.
		 * @param WP_Query $query   The query.
		 */
		return (bool) apply_filters( 'coywolf_search_enabled', true, $query );
	}

	/**
	 * Replace the FOUND_ROWS() query for a request we answered.
	 *
	 * @param string   $sql   The found-posts query.
	 * @param WP_Query $query The query.
	 * @return string
	 */
	public function filter_found_posts_query( $sql, $query ) {
		if ( $query !== $this->handled_query ) {
			return $sql;
		}
		return 'SELECT 0';
	}

	/**
	 * Restore the real total for a request we answered.
	 *
	 * @param int      $found_posts Found posts.
	 * @param WP_Query $query       The query.
	 * @return int
	 */
	public function filter_found_posts( $found_posts, $query ) {
		if ( $query !== $this->handled_query ) {
			return $found_posts;
		}
		return $this->handled_total;
	}
}
