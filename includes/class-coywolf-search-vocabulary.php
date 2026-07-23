<?php
/**
 * Vocabulary cache and term expansion.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves query terms to term IDs, and expands them by prefix and edit
 * distance.
 *
 * Small and medium vocabularies are held in memory (object cache when the site
 * has one, transient otherwise) so prefix and fuzzy expansion are array scans
 * rather than queries. Above a bounded size the in-memory copy would cost more
 * than it saves, so expansion falls back to index-backed SQL — a right-anchored
 * `LIKE 'prefix%'`, never a leading wildcard.
 */
final class Coywolf_Search_Vocabulary {

	const CACHE_KEY   = 'coywolf_search_vocabulary';
	const CACHE_GROUP = 'coywolf-search';

	/**
	 * Above this many terms the vocabulary is not held in memory.
	 */
	const MAX_CACHED_TERMS = 50000;

	/**
	 * Per-request memo.
	 *
	 * @var array<string,array{0:int,1:int}>|null
	 */
	private static $memo = null;

	/**
	 * Whether the memo represents the whole vocabulary.
	 *
	 * @var bool
	 */
	private static $memo_complete = false;

	/**
	 * Drop every cached copy of the vocabulary.
	 */
	public static function invalidate() {
		self::$memo          = null;
		self::$memo_complete = false;
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Whether expansion is running in SQL mode (vocabulary too large to cache).
	 *
	 * @return bool
	 */
	public static function is_sql_mode() {
		self::load();
		return ! self::$memo_complete;
	}

	/**
	 * Load the vocabulary into memory if it is small enough.
	 */
	private static function load() {
		if ( null !== self::$memo ) {
			return;
		}

		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( false === $cached ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				wp_cache_set( self::CACHE_KEY, $cached, self::CACHE_GROUP, HOUR_IN_SECONDS );
			}
		}

		if ( is_array( $cached ) && isset( $cached['complete'], $cached['terms'] ) ) {
			self::$memo          = $cached['terms'];
			self::$memo_complete = (bool) $cached['complete'];
			return;
		}

		$stats = Coywolf_Search_Indexer::stats();
		if ( (int) $stats['terms'] > self::MAX_CACHED_TERMS ) {
			self::$memo          = array();
			self::$memo_complete = false;
			self::store( array(), false );
			return;
		}

		self::$memo          = self::fetch_all();
		self::$memo_complete = true;
		self::store( self::$memo, true );
	}

	/**
	 * Persist the loaded vocabulary.
	 *
	 * @param array<string,array{0:int,1:int}> $terms    Vocabulary.
	 * @param bool                             $complete Whether it is the whole vocabulary.
	 */
	private static function store( array $terms, $complete ) {
		$payload = array(
			'complete' => (bool) $complete,
			'terms'    => $terms,
		);
		wp_cache_set( self::CACHE_KEY, $payload, self::CACHE_GROUP, HOUR_IN_SECONDS );
		set_transient( self::CACHE_KEY, $payload, HOUR_IN_SECONDS );
	}

	/**
	 * Read the whole vocabulary from the database.
	 *
	 * @return array<string,array{0:int,1:int}> Term => [term_id, doc_freq].
	 */
	private static function fetch_all() {
		global $wpdb;

		if ( ! Coywolf_Search_Schema::tables_exist() ) {
			return array();
		}

		$table = Coywolf_Search_Schema::terms_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the plugin's own vocabulary table; the result is what gets cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT term_id, term, doc_freq FROM %i WHERE doc_freq > 0', $table ),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['term'] ] = array( (int) $row['term_id'], (int) $row['doc_freq'] );
		}
		return $out;
	}

	/**
	 * Resolve exact terms to their IDs and document frequencies.
	 *
	 * @param string[] $terms Terms.
	 * @return array<string,array{0:int,1:int}> Term => [term_id, doc_freq].
	 */
	public static function lookup( array $terms ) {
		if ( empty( $terms ) ) {
			return array();
		}

		self::load();

		if ( self::$memo_complete ) {
			$out = array();
			foreach ( $terms as $term ) {
				if ( isset( self::$memo[ $term ] ) ) {
					$out[ $term ] = self::$memo[ $term ];
				}
			}
			return $out;
		}

		return self::lookup_sql( $terms );
	}

	/**
	 * SQL-mode exact lookup.
	 *
	 * @param string[] $terms Terms.
	 * @return array<string,array{0:int,1:int}>
	 */
	private static function lookup_sql( array $terms ) {
		global $wpdb;

		if ( ! Coywolf_Search_Schema::tables_exist() ) {
			return array();
		}

		$table        = Coywolf_Search_Schema::terms_table();
		$placeholders = implode( ',', array_fill( 0, count( $terms ), '%s' ) );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated from the term count, then bound below.
			"SELECT term_id, term, doc_freq FROM %i WHERE doc_freq > 0 AND term IN ({$placeholders})",
			array_merge( array( $table ), array_values( $terms ) )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; reading the plugin's own vocabulary table.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['term'] ] = array( (int) $row['term_id'], (int) $row['doc_freq'] );
		}
		return $out;
	}

	/**
	 * Terms beginning with the given prefix, most common first.
	 *
	 * @param string $prefix Prefix.
	 * @param int    $cap    Maximum expansions.
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function expand_prefix( $prefix, $cap ) {
		$prefix = (string) $prefix;
		$cap    = max( 1, (int) $cap );

		if ( '' === $prefix ) {
			return array();
		}

		self::load();

		if ( self::$memo_complete ) {
			$matches = array();
			$length  = strlen( $prefix );
			foreach ( self::$memo as $term => $entry ) {
				if ( 0 === strncmp( $term, $prefix, $length ) ) {
					$matches[ $term ] = $entry;
				}
			}
			return self::top_by_doc_freq( $matches, $cap );
		}

		return self::expand_prefix_sql( $prefix, $cap );
	}

	/**
	 * SQL-mode prefix expansion.
	 *
	 * Right-anchored so the unique index on `term` is usable; a leading
	 * wildcard would force a full scan of the vocabulary table.
	 *
	 * @param string $prefix Prefix.
	 * @param int    $cap    Maximum expansions.
	 * @return array<string,array{0:int,1:int}>
	 */
	private static function expand_prefix_sql( $prefix, $cap ) {
		global $wpdb;

		if ( ! Coywolf_Search_Schema::tables_exist() ) {
			return array();
		}

		$table = Coywolf_Search_Schema::terms_table();
		$like  = $wpdb->esc_like( $prefix ) . '%';

		$sql = $wpdb->prepare(
			'SELECT term_id, term, doc_freq FROM %i WHERE doc_freq > 0 AND term LIKE %s ORDER BY doc_freq DESC LIMIT %d',
			$table,
			$like,
			$cap
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; reading the plugin's own vocabulary table.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['term'] ] = array( (int) $row['term_id'], (int) $row['doc_freq'] );
		}
		return $out;
	}

	/**
	 * Terms within a small edit distance of the given term.
	 *
	 * Only available while the vocabulary is held in memory: computing edit
	 * distance in SQL would mean scanning the vocabulary table per query.
	 *
	 * @param string $term     Term.
	 * @param int    $distance Maximum edit distance.
	 * @param int    $cap      Maximum expansions.
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function expand_fuzzy( $term, $distance, $cap ) {
		$term     = (string) $term;
		$distance = max( 1, (int) $distance );
		$cap      = max( 1, (int) $cap );

		self::load();

		if ( ! self::$memo_complete || '' === $term ) {
			return array();
		}

		$length  = strlen( $term );
		$matches = array();

		foreach ( self::$memo as $candidate => $entry ) {
			// A term whose length differs by more than the allowed distance
			// cannot be within that distance; skipping those first avoids the
			// expensive comparison for almost the whole vocabulary.
			if ( abs( strlen( $candidate ) - $length ) > $distance ) {
				continue;
			}
			if ( $candidate === $term ) {
				continue;
			}
			if ( levenshtein( $term, $candidate ) <= $distance ) {
				$matches[ $candidate ] = $entry;
			}
		}

		return self::top_by_doc_freq( $matches, $cap );
	}

	/**
	 * Keep the most common N of a match set.
	 *
	 * @param array<string,array{0:int,1:int}> $matches Matches.
	 * @param int                              $cap     Maximum to keep.
	 * @return array<string,array{0:int,1:int}>
	 */
	private static function top_by_doc_freq( array $matches, $cap ) {
		if ( count( $matches ) <= $cap ) {
			return $matches;
		}
		uasort(
			$matches,
			static function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		return array_slice( $matches, 0, $cap, true );
	}
}
