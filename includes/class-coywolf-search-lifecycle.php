<?php
/**
 * Activation and deactivation.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What happens when the plugin is switched on and off.
 */
final class Coywolf_Search_Lifecycle {

	/**
	 * Create the tables and seed defaults.
	 *
	 * Nothing is scheduled and nothing is indexed here: activation should be
	 * fast and predictable, and the settings screen offers the first build.
	 */
	public static function activate() {
		Coywolf_Search_Schema::create_tables();

		if ( false === get_option( Coywolf_Search_Settings::OPTION, false ) ) {
			add_option( Coywolf_Search_Settings::OPTION, Coywolf_Search_Settings::defaults() );
		}

		Coywolf_Search_Indexer::update_stats( Coywolf_Search_Indexer::default_stats() );
		Coywolf_Search_Rebuilder::reset();

		update_option( 'coywolf_search_needs_first_build', 1, false );
	}

	/**
	 * Tear the index down.
	 *
	 * The tables are dropped rather than left behind, because everything in
	 * them is derived from posts and a rebuild regenerates it exactly. The
	 * settings survive, so reactivating restores the configuration and one
	 * rebuild restores the index. WordPress resumes its own search the moment
	 * the plugin's filters stop being registered.
	 */
	public static function deactivate() {
		// Referenced as literals rather than through the updater class: that
		// class is not present in the WordPress.org build, and the hook names
		// need to be unschedulable either way.
		wp_unschedule_hook( Coywolf_Search_Indexer::CRON_HOOK );
		wp_unschedule_hook( 'coywolf_search_refresh_release' );

		Coywolf_Search_Vocabulary::invalidate();
		Coywolf_Search_Rebuilder::reset();

		delete_option( Coywolf_Search_Indexer::QUEUE_OPTION );
		delete_option( Coywolf_Search_Indexer::STATS_OPTION );
		delete_option( 'coywolf_search_index_stale' );
		delete_option( 'coywolf_search_needs_first_build' );

		Coywolf_Search_Schema::drop_tables();

		// WordPress refreshes the plugin-update transient later in the same
		// request that deactivates a plugin, which gives the GitHub build's
		// updater one last chance to schedule its refresh after this hook has
		// already run. Sweeping again at shutdown means deactivation really
		// does leave no scheduled events behind.
		add_action(
			'shutdown',
			static function () {
				wp_unschedule_hook( 'coywolf_search_refresh_release' );
			},
			PHP_INT_MAX
		);
	}
}
