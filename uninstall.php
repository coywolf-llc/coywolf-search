<?php
/**
 * Uninstall: remove everything Coywolf Search created.
 *
 * Deactivation already drops the index tables and keeps the settings, so this
 * file's job is to remove the settings too, plus anything a partially-completed
 * lifecycle might have left behind. It runs standalone — none of the plugin's
 * classes are loaded — so every table name and option key is spelled out here.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Remove the plugin's data for the current site.
 */
function coywolf_search_uninstall_site() {
	global $wpdb;

	$coywolf_search_tables = array(
		$wpdb->prefix . 'coywolf_search_postings',
		$wpdb->prefix . 'coywolf_search_terms',
	);

	foreach ( $coywolf_search_tables as $coywolf_search_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own index tables on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $coywolf_search_table ) );
	}

	$coywolf_search_options = array(
		'coywolf_search_settings',
		'coywolf_search_index_stats',
		'coywolf_search_queue',
		'coywolf_search_rebuild_state',
		'coywolf_search_db_version',
		'coywolf_search_index_stale',
		'coywolf_search_needs_first_build',
		'coywolf_search_tick_lock',
	);

	foreach ( $coywolf_search_options as $coywolf_search_option ) {
		delete_option( $coywolf_search_option );
	}

	// Transients the engine and the GitHub build's updater may have written.
	delete_transient( 'coywolf_search_vocabulary' );
	delete_transient( 'coywolf_search_typeahead_docs' );
	delete_site_transient( 'coywolf_search_gh_release' );
	delete_site_transient( 'coywolf_search_gh_release_neg' );
	delete_site_transient( 'coywolf_search_gh_release_err' );

	// Any remaining prefixed transients (rate-limit buckets and the like) live
	// in the options table; sweep them by name so none are orphaned.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall sweep; there is no core API for deleting transients by prefix.
	$coywolf_search_orphans = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_coywolf_search_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_coywolf_search_' ) . '%'
		)
	);

	foreach ( (array) $coywolf_search_orphans as $coywolf_search_orphan ) {
		delete_option( $coywolf_search_orphan );
	}

	wp_unschedule_hook( 'coywolf_search_process_queue' );
	wp_unschedule_hook( 'coywolf_search_rebuild_batch' );
	wp_unschedule_hook( 'coywolf_search_refresh_release' );

	// Generated typeahead payloads live under uploads.
	$coywolf_search_uploads = wp_upload_dir();
	if ( empty( $coywolf_search_uploads['error'] ) && ! empty( $coywolf_search_uploads['basedir'] ) ) {
		$coywolf_search_dir = trailingslashit( $coywolf_search_uploads['basedir'] ) . 'coywolf-search';

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base && $wp_filesystem->is_dir( $coywolf_search_dir ) ) {
			$wp_filesystem->rmdir( $coywolf_search_dir, true );
		}
	}
}

if ( is_multisite() ) {
	$coywolf_search_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $coywolf_search_site_ids as $coywolf_search_site_id ) {
		switch_to_blog( (int) $coywolf_search_site_id );
		coywolf_search_uninstall_site();
		restore_current_blog();
	}
} else {
	coywolf_search_uninstall_site();
}
