<?php
/**
 * Plugin Name: WP-DBManager
 * Plugin URI: https://lesterchan.net/portfolio/programming/php/
 * Description: Manages your WordPress database. Allows you to optimize database, repair database, backup database, restore database, delete backup database , drop/empty tables and run selected queries. Supports automatic scheduling of backing up, optimizing and repairing of database.
 * Version: 4.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Lester 'GaMerZ' Chan
 * Author URI: https://lesterchan.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-dbmanager
 * Domain Path: /languages
 *
 * @package WP-DBManager
 */

/*
	Copyright 2026  Lester Chan  (email : lesterchan@gmail.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
*/

defined( 'ABSPATH' ) || exit;

/**
 * WP-DBManager version. The last-run value is kept in the wp_dbmanager_version row.
 */
define( 'WP_DBMANAGER_VERSION', '4.0.0' );

/**
 * Schema counter. Bumped only when the stored rows need reshaping.
 */
define( 'WP_DBMANAGER_DB_VERSION', '1' );

/**
 * WP-DBManager slug, which is also the text domain.
 */
define( 'WP_DBMANAGER_SLUG', 'wp-dbmanager' );

/**
 * WP-DBManager main file.
 */
define( 'WP_DBMANAGER_MAIN_FILE', __FILE__ );

/**
 * WP-DBManager directory, with a trailing slash.
 */
define( 'WP_DBMANAGER_DIR', plugin_dir_path( __FILE__ ) );

/**
 * WP-DBManager URL, with a trailing slash.
 */
define( 'WP_DBMANAGER_URL', plugin_dir_url( __FILE__ ) );

require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-options.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-database.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-tables.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-backups.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-folder.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-mailer.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-cron.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-admin.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-backups-table.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-tables-table.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-screens.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager-settings.php';
require_once WP_DBMANAGER_DIR . 'includes/class-wp-dbmanager.php';

WP_DBManager::get_instance();
