<?php
/**
 * Plugin Name: WP-DBManager
 * Plugin URI: https://lesterchan.net/portfolio/programming/php/
 * Description: Manages your WordPress database. Allows you to optimize database, repair database, backup database, restore database, delete backup database , drop/empty tables and run selected queries. Supports automatic scheduling of backing up, optimizing and repairing of database.
 * Version: 3.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Lester 'GaMerZ' Chan
 * Author URI: https://lesterchan.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-dbmanager
 * Domain Path: /languages
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-DBManager version.
 */
define( 'WP_DBMANAGER_VERSION', '3.0.0' );

/**
 * WP-DBManager main file.
 */
define( 'WP_DBMANAGER_MAIN_FILE', __FILE__ );

/**
 * Absolute path to the plugin directory, with a trailing slash.
 */
define( 'WP_DBMANAGER_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL of the plugin directory, with a trailing slash.
 */
define( 'WP_DBMANAGER_URL', plugin_dir_url( __FILE__ ) );

require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager-options.php';
require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager-database.php';
require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager-tables.php';
require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager-backups.php';
require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager-folder.php';
require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager-mailer.php';
require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager-cron.php';
require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager-admin.php';
require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager-screens.php';
require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager-settings.php';
require_once WP_DBMANAGER_DIR . 'includes/class-dbmanager.php';

DBManager::get_instance();
