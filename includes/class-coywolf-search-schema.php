<?php
/**
 * Index table names, creation, and teardown.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the two custom index tables.
 *
 * The index is entirely derived data: everything in these tables can be
 * rebuilt from the posts they were generated from, which is why deactivation
 * is free to drop them.
 */
final class Coywolf_Search_Schema {

	/**
	 * Bumped whenever the table definitions change, so an upgraded install
	 * re-runs dbDelta once instead of on every request.
	 */
	const DB_VERSION = 1;

	/**
	 * Field identifiers stored in the `field` column.
	 */
	const FIELD_TITLE    = 0;
	const FIELD_CONTENT  = 1;
	const FIELD_EXCERPT  = 2;
	const FIELD_TAXONOMY = 3;

	/**
	 * Vocabulary table: one row per distinct term.
	 *
	 * @return string
	 */
	public static function terms_table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_search_terms';
	}

	/**
	 * Postings table: one row per (term, post, field).
	 *
	 * @return string
	 */
	public static function postings_table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_search_postings';
	}

	/**
	 * Map a field key to its stored identifier.
	 *
	 * @param string $key One of title|content|excerpt|taxonomy.
	 * @return int
	 */
	public static function field_id( $key ) {
		$map = array(
			'title'    => self::FIELD_TITLE,
			'content'  => self::FIELD_CONTENT,
			'excerpt'  => self::FIELD_EXCERPT,
			'taxonomy' => self::FIELD_TAXONOMY,
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : self::FIELD_CONTENT;
	}

	/**
	 * All field keys, in stored-identifier order.
	 *
	 * @return string[]
	 */
	public static function field_keys() {
		return array( 'title', 'content', 'excerpt', 'taxonomy' );
	}

	/**
	 * Create (or migrate) the index tables.
	 *
	 * The `dl` column on postings carries the total token count of that
	 * post's field. BM25 needs the document length to normalise term
	 * frequency, and the sum of the *matched* terms' `tf` is not the document
	 * length — so it is denormalised onto every posting row and read in the
	 * same fetch as the postings themselves, keeping the search path to a
	 * single query.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$terms           = self::terms_table();
		$postings        = self::postings_table();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one
		// definition per line, every KEY named, no backticks.
		$sql = "CREATE TABLE {$terms} (
	term_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	term varchar(64) NOT NULL,
	doc_freq int(10) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (term_id),
	UNIQUE KEY term (term)
) {$charset_collate};

CREATE TABLE {$postings} (
	term_id bigint(20) unsigned NOT NULL,
	post_id bigint(20) unsigned NOT NULL,
	field tinyint(3) unsigned NOT NULL,
	tf smallint(5) unsigned NOT NULL,
	dl smallint(5) unsigned NOT NULL,
	PRIMARY KEY  (term_id,post_id,field),
	KEY post_id (post_id)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'coywolf_search_db_version', self::DB_VERSION, false );
		self::flush_existence_cache();
	}

	/**
	 * Run dbDelta once after an upgrade that changed the table definitions.
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( 'coywolf_search_db_version', 0 );
		if ( $installed === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
	}

	/**
	 * Drop both index tables.
	 *
	 * Safe at deactivation: the index holds no user-authored content, only a
	 * derived projection of posts that a rebuild regenerates.
	 */
	public static function drop_tables() {
		global $wpdb;

		foreach ( array( self::postings_table(), self::terms_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own index tables; no caching or core API applies.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( 'coywolf_search_db_version' );
		self::flush_existence_cache();
	}

	/**
	 * Per-request memo of the existence probe.
	 *
	 * @var bool|null
	 */
	private static $tables_exist = null;

	/**
	 * Whether both index tables exist.
	 *
	 * Memoised: the search path checks this before every query, and the tables
	 * cannot appear or vanish partway through a request, so probing once keeps
	 * a search to the single postings query it is supposed to cost.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		if ( null !== self::$tables_exist ) {
			return self::$tables_exist;
		}

		// The version option is written when the tables are created and
		// deleted when they are dropped, so on the happy path it answers
		// without touching the database schema — two SHOW TABLES probes on
		// every search add up. The probe below remains the fallback for any
		// state the option cannot vouch for.
		if ( (int) get_option( 'coywolf_search_db_version', 0 ) === self::DB_VERSION ) {
			self::$tables_exist = true;
			return true;
		}

		self::$tables_exist = true;

		foreach ( array( self::terms_table(), self::postings_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence probe on the plugin's own tables; no core API for this.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				self::$tables_exist = false;
				break;
			}
		}

		return self::$tables_exist;
	}

	/**
	 * Forget the memo, after creating or dropping the tables.
	 */
	public static function flush_existence_cache() {
		self::$tables_exist = null;
	}
}
