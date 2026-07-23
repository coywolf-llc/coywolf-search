<?php
/**
 * The search pipeline: tokenize, expand, fetch postings, score, rank.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a query string into ranked post IDs.
 *
 * Knows nothing about WP_Query — the front-end integration and the REST
 * endpoint both call {@see search()} and adapt the result themselves.
 */
final class Coywolf_Search_Query_Engine {

	/**
	 * Most query tokens considered.
	 *
	 * Real queries are a handful of words. The cap exists because the search
	 * endpoint is anonymous and every token costs an expansion pass — without
	 * a ceiling, a 200-character query packed with two-letter tokens does
	 * dozens of vocabulary scans on one request.
	 */
	const MAX_TOKENS = 16;

	/**
	 * Most tokens that get the fuzzy treatment.
	 *
	 * Fuzzy expansion is the expensive step — an edit-distance pass over the
	 * cached vocabulary per token — and its value is in catching a typo or
	 * two, not in fuzzing every word of a long query.
	 */
	const MAX_FUZZY_TOKENS = 6;

	/**
	 * Hard ceiling on posting rows fetched for one search.
	 *
	 * Scoring wants every posting for the expanded terms, and on any site
	 * within this plugin's design range it gets exactly that — the limit is
	 * far above what real corpora produce. It exists so that the worst case
	 * (a pathological corpus and a query of nothing but ultra-common terms)
	 * degrades to slightly-imperfect ranking instead of an out-of-memory
	 * request.
	 */
	const MAX_POSTING_ROWS = 50000;

	/**
	 * Run a search.
	 *
	 * @param string               $query_string Raw query.
	 * @param array<string,mixed>  $args         post_types, paged, per_page.
	 * @return array{ids:int[],total:int}|null Null when the engine has nothing to say
	 *                                        and the caller should fall back.
	 */
	public function search( $query_string, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'post_types' => (array) Coywolf_Search_Settings::get( 'post_types' ),
				'paged'      => 1,
				'per_page'   => (int) get_option( 'posts_per_page', 10 ),
			)
		);

		if ( ! Coywolf_Search_Schema::tables_exist() || ! Coywolf_Search_Indexer::has_index() ) {
			return null;
		}

		$tokenizer = Coywolf_Search_Tokenizer::from_settings();
		$tokens    = $tokenizer->tokenize_query( (string) $query_string );

		if ( empty( $tokens ) ) {
			return null;
		}

		$tokens = array_slice( $tokens, 0, self::MAX_TOKENS );

		$groups = $this->expand( $tokens );
		if ( empty( $groups ) ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}

		$post_types = $this->allowed_post_types( $args['post_types'] );
		if ( empty( $post_types ) ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}

		$rows = $this->fetch_postings( $groups, $post_types );
		if ( empty( $rows ) ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}

		$ranked = $this->score( $rows, $groups );

		/**
		 * Filters the ranked post IDs before pagination.
		 *
		 * @param int[]  $ids          Ranked post IDs, best first.
		 * @param string $query_string The search query.
		 */
		$ranked = (array) apply_filters( 'coywolf_search_results', $ranked, (string) $query_string );

		$total    = count( $ranked );
		$per_page = (int) $args['per_page'];
		$paged    = max( 1, (int) $args['paged'] );

		if ( $per_page > 0 ) {
			$ranked = array_slice( $ranked, ( $paged - 1 ) * $per_page, $per_page );
		}

		return array(
			'ids'   => array_map( 'intval', $ranked ),
			'total' => $total,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Expansion                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Expand each query token into the set of index terms that satisfy it.
	 *
	 * One group per token. A document has to satisfy every group to be an AND
	 * match, and within a group any term will do — that is what makes "fuzzy"
	 * and "prefix" behave like alternatives rather than extra requirements.
	 *
	 * @param string[] $tokens Query tokens, in order.
	 * @return array<int,array{terms:array<int,int>,df:array<int,int>}> Groups.
	 */
	private function expand( array $tokens ) {
		$settings   = Coywolf_Search_Settings::all();
		$cap        = (int) $settings['max_expansions'];
		$last_index = count( $tokens ) - 1;

		$exact = Coywolf_Search_Vocabulary::lookup( $tokens );

		$groups  = array();
		$fuzzied = 0;

		foreach ( $tokens as $index => $token ) {
			$candidates = array();

			if ( isset( $exact[ $token ] ) ) {
				$candidates[ $token ] = $exact[ $token ];
			}

			// The last token is the one the user may still be typing.
			if ( ! empty( $settings['prefix_last'] ) && $index === $last_index ) {
				foreach ( Coywolf_Search_Vocabulary::expand_prefix( $token, $cap ) as $term => $entry ) {
					$candidates[ $term ] = $entry;
				}
			}

			$distance = $fuzzied < self::MAX_FUZZY_TOKENS ? $this->fuzzy_distance( $token, $settings ) : 0;
			if ( $distance > 0 ) {
				++$fuzzied;
				foreach ( Coywolf_Search_Vocabulary::expand_fuzzy( $token, $distance, $cap ) as $term => $entry ) {
					$candidates[ $term ] = $entry;
				}
			}

			if ( empty( $candidates ) ) {
				// Nothing in the index satisfies this token. Keep the empty
				// group so AND correctly yields nothing and the OR fallback
				// still scores the tokens that did match.
				$groups[] = array(
					'terms' => array(),
					'df'    => array(),
				);
				continue;
			}

			$terms = array();
			$dfs   = array();
			foreach ( $candidates as $entry ) {
				$terms[]              = (int) $entry[0];
				$dfs[ (int) $entry[0] ] = (int) $entry[1];
			}

			$groups[] = array(
				'terms' => $terms,
				'df'    => $dfs,
			);
		}

		// Every group empty means the query has no footing in the index at all.
		foreach ( $groups as $group ) {
			if ( ! empty( $group['terms'] ) ) {
				return $groups;
			}
		}

		return array();
	}

	/**
	 * The edit distance to allow for a token, given the fuzzy setting.
	 *
	 * @param string              $token    Token.
	 * @param array<string,mixed> $settings Settings.
	 * @return int Zero when fuzzy matching does not apply.
	 */
	private function fuzzy_distance( $token, array $settings ) {
		$mode = isset( $settings['fuzzy'] ) ? $settings['fuzzy'] : 'off';
		if ( 'off' === $mode ) {
			return 0;
		}

		$length = strlen( $token );
		if ( $length < (int) $settings['fuzzy_min_length'] ) {
			return 0;
		}

		if ( 'one' === $mode ) {
			return 1;
		}

		// Auto: a second edit only for terms long enough that two typos are
		// still a recognisable word.
		return $length >= 8 ? 2 : 1;
	}

	/* --------------------------------------------------------------------- */
	/* Fetch                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * The post types that may appear in results.
	 *
	 * @param mixed $requested Requested types (from the query var, if scoped).
	 * @return string[]
	 */
	private function allowed_post_types( $requested ) {
		$indexed = (array) Coywolf_Search_Settings::get( 'post_types' );

		if ( empty( $requested ) || 'any' === $requested ) {
			return $indexed;
		}

		$requested = array_map( 'sanitize_key', (array) $requested );

		return array_values( array_intersect( $indexed, $requested ) );
	}

	/**
	 * Fetch every posting for the expanded terms, in one query.
	 *
	 * The join against the posts table is what keeps a post that was
	 * unpublished, password-protected, or switched to a private type since it
	 * was indexed from surfacing in results — the index is updated by a
	 * debounced background job, so it can legitimately lag by a minute, and
	 * that window must not be a disclosure.
	 *
	 * @param array<int,array{terms:array<int,int>,df:array<int,int>}> $groups     Groups.
	 * @param string[]                                                 $post_types Post types.
	 * @return array<int,array<string,int>> Posting rows.
	 */
	private function fetch_postings( array $groups, array $post_types ) {
		global $wpdb;

		$term_ids = array();
		foreach ( $groups as $group ) {
			foreach ( $group['terms'] as $term_id ) {
				$term_ids[ $term_id ] = true;
			}
		}
		$term_ids = array_keys( $term_ids );
		if ( empty( $term_ids ) ) {
			return array();
		}

		$fields = array();
		foreach ( (array) Coywolf_Search_Settings::get( 'fields' ) as $key ) {
			$fields[] = Coywolf_Search_Schema::field_id( $key );
		}
		$fields = array_values( array_unique( $fields ) );
		if ( empty( $fields ) ) {
			return array();
		}

		$postings = Coywolf_Search_Schema::postings_table();
		$posts    = $wpdb->posts;

		$term_placeholders  = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		$field_placeholders = implode( ',', array_fill( 0, count( $fields ), '%d' ) );
		$type_placeholders  = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// The three interpolated fragments are placeholder lists ("%d,%d,%d")
		// generated from the counts of the value arrays below — no caller data
		// reaches the SQL string. Every actual value is bound by prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT ps.term_id, ps.post_id, ps.field, ps.tf, ps.dl
			FROM %i ps
			INNER JOIN %i p ON p.ID = ps.post_id
			WHERE ps.term_id IN ({$term_placeholders})
			AND ps.field IN ({$field_placeholders})
			AND p.post_status = 'publish'
			AND p.post_password = ''
			AND p.post_type IN ({$type_placeholders})
			LIMIT %d",
			array_merge(
				array( $postings, $posts ),
				$term_ids,
				$fields,
				$post_types,
				array( self::MAX_POSTING_ROWS )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; reading the plugin's own postings table. Results are query-specific and not worth caching.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/* --------------------------------------------------------------------- */
	/* Scoring                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Score and rank the fetched postings with BM25.
	 *
	 * @param array<int,array<string,int>>                              $rows   Posting rows.
	 * @param array<int,array{terms:array<int,int>,df:array<int,int>}>  $groups Groups.
	 * @return int[] Ranked post IDs, best first.
	 */
	private function score( array $rows, array $groups ) {
		$settings = Coywolf_Search_Settings::all();
		$stats    = Coywolf_Search_Indexer::stats();

		$doc_count = max( 1, (int) $stats['doc_count'] );
		$k1        = (float) $settings['bm25_k1'];
		$b         = (float) $settings['bm25_b'];

		$weights = array(
			Coywolf_Search_Schema::FIELD_TITLE    => (float) $settings['weight_title'],
			Coywolf_Search_Schema::FIELD_CONTENT  => (float) $settings['weight_content'],
			Coywolf_Search_Schema::FIELD_EXCERPT  => (float) $settings['weight_excerpt'],
			Coywolf_Search_Schema::FIELD_TAXONOMY => (float) $settings['weight_taxonomy'],
		);

		$avg_length = $this->average_lengths( $stats );

		// Which group(s) each term belongs to. A term can satisfy more than one
		// group when two query tokens expand onto the same index term.
		$term_groups = array();
		foreach ( $groups as $index => $group ) {
			foreach ( $group['terms'] as $term_id ) {
				$term_groups[ $term_id ][] = $index;
			}
		}

		$idf = array();
		foreach ( $groups as $group ) {
			foreach ( $group['df'] as $term_id => $df ) {
				$idf[ $term_id ] = log( ( ( $doc_count - $df + 0.5 ) / ( $df + 0.5 ) ) + 1 );
			}
		}

		// Best contribution per post, per group, per field. Taking the maximum
		// rather than the sum stops a token that expanded into a stem, a
		// prefix, and two fuzzy variants from out-scoring a token that matched
		// exactly once.
		$best = array();

		foreach ( $rows as $row ) {
			$term_id = (int) $row['term_id'];
			if ( ! isset( $term_groups[ $term_id ], $idf[ $term_id ] ) ) {
				continue;
			}

			$post_id = (int) $row['post_id'];
			$field   = (int) $row['field'];
			$tf      = (int) $row['tf'];
			$dl      = max( 1, (int) $row['dl'] );

			$avg    = isset( $avg_length[ $field ] ) ? $avg_length[ $field ] : (float) $dl;
			$weight = isset( $weights[ $field ] ) ? $weights[ $field ] : 1.0;

			if ( $weight <= 0.0 ) {
				continue;
			}

			$norm  = $tf * ( $k1 + 1 ) / ( $tf + ( $k1 * ( 1 - $b + ( $b * ( $dl / max( 0.0001, $avg ) ) ) ) ) );
			$value = $idf[ $term_id ] * $norm * $weight;

			foreach ( $term_groups[ $term_id ] as $group_index ) {
				if ( ! isset( $best[ $post_id ][ $group_index ][ $field ] ) || $value > $best[ $post_id ][ $group_index ][ $field ] ) {
					$best[ $post_id ][ $group_index ][ $field ] = $value;
				}
			}
		}

		if ( empty( $best ) ) {
			return array();
		}

		$group_count = count( $groups );
		$satisfiable = 0;
		foreach ( $groups as $group ) {
			if ( ! empty( $group['terms'] ) ) {
				++$satisfiable;
			}
		}

		$and_scores = array();
		$or_scores  = array();

		foreach ( $best as $post_id => $per_group ) {
			$score = 0.0;
			foreach ( $per_group as $per_field ) {
				$score += array_sum( $per_field );
			}

			$matched = count( $per_group );

			// A post matches under AND semantics when it covers every group
			// that anything in the index could satisfy.
			if ( $matched >= $satisfiable && $satisfiable === $group_count ) {
				$and_scores[ $post_id ] = $score;
			}

			// Partial matches are scaled by coverage so that, in the fallback,
			// a post matching three of four tokens still outranks one matching
			// a single token.
			$or_scores[ $post_id ] = $score * ( $matched / max( 1, $group_count ) );
		}

		$scores = $and_scores;

		if ( empty( $scores ) && ! empty( $settings['or_fallback'] ) ) {
			$scores = $or_scores;
		}

		if ( empty( $scores ) ) {
			return array();
		}

		return $this->sort_by_score( $scores );
	}

	/**
	 * Average token count per field across the corpus.
	 *
	 * @param array<string,mixed> $stats Index statistics.
	 * @return array<int,float>
	 */
	private function average_lengths( array $stats ) {
		$out = array();

		$tokens = isset( $stats['field_tokens'] ) ? (array) $stats['field_tokens'] : array();
		$docs   = isset( $stats['field_docs'] ) ? (array) $stats['field_docs'] : array();

		foreach ( $tokens as $field => $total ) {
			$count            = isset( $docs[ $field ] ) ? max( 1, (int) $docs[ $field ] ) : 1;
			$out[ (int) $field ] = (float) $total / $count;
		}

		return $out;
	}

	/**
	 * Sort scored posts, breaking ties by recency then ID.
	 *
	 * @param array<int,float> $scores Post ID => score.
	 * @return int[]
	 */
	private function sort_by_score( array $scores ) {
		global $wpdb;

		$ids = array_keys( $scores );

		// The tiebreak needs one small column per matched post — not the full
		// row. Priming the post cache here would hydrate every matched post
		// including its content before pagination has discarded most of them;
		// on a broad query that is thousands of complete posts fetched to
		// return ten. Only the page of results that survives the slice gets
		// hydrated, by whoever renders it.
		$dates        = array();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// The placeholder list is generated from the ID count and every value
		// is bound by prepare(); the direct query exists because the core API
		// for reading post dates hydrates complete post objects.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_date_gmt FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {
			$dates[ (int) $row['ID'] ] = (string) $row['post_date_gmt'];
		}
		foreach ( $ids as $post_id ) {
			if ( ! isset( $dates[ $post_id ] ) ) {
				$dates[ $post_id ] = '';
			}
		}

		usort(
			$ids,
			static function ( $a, $b ) use ( $scores, $dates ) {
				if ( abs( $scores[ $a ] - $scores[ $b ] ) > 0.000001 ) {
					return $scores[ $b ] <=> $scores[ $a ];
				}
				if ( $dates[ $a ] !== $dates[ $b ] ) {
					return strcmp( $dates[ $b ], $dates[ $a ] );
				}
				return $b <=> $a;
			}
		);

		return $ids;
	}
}
