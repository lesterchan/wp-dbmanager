<?php
/**
 * Plugin bootstrap.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin up and holds the handful of things everything else needs.
 *
 * @since 3.0.0
 */
class DBManager {

	/**
	 * Static instance.
	 *
	 * @var DBManager|null
	 */
	private static $instance;

	/**
	 * Constructor.
	 *
	 * The activation hook is registered here rather than on a later hook: this
	 * runs while the main plugin file is being loaded, which is where WordPress
	 * requires it to be registered.
	 */
	public function __construct() {
		register_activation_hook( WP_DBMANAGER_MAIN_FILE, array( $this, 'activate' ) );

		add_action( 'plugins_loaded', array( $this, 'add_hooks' ) );
	}

	/**
	 * Initialize the plugin object and return its instance.
	 *
	 * @return DBManager
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the plugin's hooks.
	 *
	 * @return void
	 */
	public function add_hooks() {
		DBManager_Cron::init();

		add_filter( 'upload_mimes', array( $this, 'upload_mimes' ) );

		// Both of these answer a POST and then exit, so they have to run before
		// anything renders.
		add_action( 'init', array( 'DBManager_Backups', 'maybe_download' ) );
		add_action( 'init', array( 'DBManager_Folder', 'maybe_fix' ) );

		if ( is_admin() ) {
			DBManager_Admin::init();
			DBManager_Settings::init();
		}
	}

	/**
	 * Allow .sql files to be uploaded to the media library.
	 *
	 * @param array $mime_types Allowed mime types keyed by extension.
	 * @return array
	 */
	public function upload_mimes( $mime_types ) {
		$mime_types['sql'] = 'application/sql';

		return $mime_types;
	}

	/**
	 * Format a Unix timestamp in the site's timezone.
	 *
	 * Backup timestamps are stored as real Unix time, so the site's GMT offset
	 * has to be applied before formatting.
	 *
	 * @param int         $timestamp Unix timestamp.
	 * @param string|null $format    Date format, defaults to the site date and time formats combined.
	 * @return string
	 */
	public static function format_timestamp( $timestamp, $format = null ) {
		if ( null === $format ) {
			/* translators: 1: date, 2: time. */
			$format = sprintf( __( '%1$s @ %2$s', 'wp-dbmanager' ), get_option( 'date_format' ), get_option( 'time_format' ) );
		}

		$offset = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;

		return mysql2date( $format, gmdate( 'Y-m-d H:i:s', (int) ( $timestamp + $offset ) ) );
	}

	/**
	 * Prepare a single site on activation.
	 *
	 * @return void
	 */
	public static function activate_site() {
		$binaries = DBManager_Database::detect_binaries();
		$defaults = DBManager_Options::defaults();

		$defaults['mysqldumppath'] = $binaries['mysqldump'];
		$defaults['mysqlpath']     = $binaries['mysql'];

		add_option( DBManager_Options::OPTION, $defaults );

		DBManager_Folder::create();

		// Dropped in 2.80.6 in favour of install_plugins, but sites activated
		// before then still carry it.
		$role = get_role( 'administrator' );

		if ( $role && $role->has_cap( 'manage_database' ) ) {
			$role->remove_cap( 'manage_database' );
		}
	}

	/**
	 * Create the default options and the backup folder on activation.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network wide.
	 * @return void
	 */
	public function activate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			// number => 0 lifts WP_Site_Query's default cap of 100; a network can
			// be larger, and the sites past the hundredth would silently get no
			// options and no backup folder.
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				// Paired inside the loop: switch_to_blog() pushes onto a stack.
				restore_current_blog();
			}

			return;
		}

		self::activate_site();
	}
}
