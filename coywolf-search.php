<?php
/**
 * Plugin Name:       Coywolf Search
 * Plugin URI:        https://coywolf.com/plugins/coywolf-search/
 * Description:       Replaces WordPress search with a custom full-text index — BM25 ranking, fuzzy and prefix matching, and per-post-type control.
 * Version:           1.1.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            Coywolf LLC
 * Author URI:        https://coywolf.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coywolf-search
 * Update URI:        https://github.com/coywolf-llc/coywolf-search
 *
 * @package CoywolfSearch
 *
 * Coywolf Search
 * Copyright (C) 2026 Coywolf LLC
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2, as published
 * by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COYWOLF_SEARCH_VERSION', '1.1.0' );
define( 'COYWOLF_SEARCH_FILE', __FILE__ );
define( 'COYWOLF_SEARCH_PATH', plugin_dir_path( __FILE__ ) );
define( 'COYWOLF_SEARCH_URL', plugin_dir_url( __FILE__ ) );

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
require_once __DIR__ . '/includes/class-github-updater.php';
// Flags this as the GitHub distribution (stripped from the WordPress.org build).
define( 'COYWOLF_SEARCH_GITHUB_BUILD', true );
/* wporg-strip:end */

require_once __DIR__ . '/includes/class-coywolf-search-schema.php';
require_once __DIR__ . '/includes/class-coywolf-search-settings.php';
require_once __DIR__ . '/includes/class-coywolf-search-stemmer.php';
require_once __DIR__ . '/includes/class-coywolf-search-tokenizer.php';
require_once __DIR__ . '/includes/class-coywolf-search-vocabulary.php';
require_once __DIR__ . '/includes/class-coywolf-search-indexer.php';
require_once __DIR__ . '/includes/class-coywolf-search-rebuilder.php';
require_once __DIR__ . '/includes/class-coywolf-search-query-engine.php';
require_once __DIR__ . '/includes/class-coywolf-search-query-integration.php';
require_once __DIR__ . '/includes/class-coywolf-search-rest.php';
require_once __DIR__ . '/includes/class-coywolf-search-admin.php';
require_once __DIR__ . '/includes/class-coywolf-search-lifecycle.php';
require_once __DIR__ . '/includes/class-coywolf-search-plugin.php';

register_activation_hook( __FILE__, array( 'Coywolf_Search_Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Coywolf_Search_Lifecycle', 'deactivate' ) );

Coywolf_Search_Plugin::instance();

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
// Wire in the GitHub self-updater so releases show up on Dashboard → Updates.
( new Coywolf_Search_GitHub_Updater( __FILE__, COYWOLF_SEARCH_VERSION ) )->init();
/* wporg-strip:end */
