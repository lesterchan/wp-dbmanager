<?php
/**
 * Reads, writes and describes the plugin's settings.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * The two rows the plugin owns, and the migration onto them.
 *
 * The plugin has always kept its settings in one row, so there is no
 * consolidation to do here. What this class replaces is the habit of calling
 * get_option() at the top of every file and then guarding each key at the point
 * of use - or, more often, not guarding it and emitting a PHP 8 warning.
 *
 * The upgrade markers live in their own row rather than inside the settings
 * array (section 2.1). The settings form never posts them, so a marker kept in
 * the settings array would have to be rescued from the stored value on every
 * save; with two rows the settings screen writes one, the upgrade routine
 * writes the other, and neither can corrupt the other.
 *
 * @since 3.0.0
 */
class WP_DBManager_Options {

	/**
	 * Option holding the plugin settings. Autoloaded.
	 */
	const OPTION = 'wp_dbmanager_options';

	/**
	 * Upgrade markers row, holding 'plugin' and 'db'. Autoloaded.
	 */
	const VERSION = 'wp_dbmanager_version';

	/**
	 * The settings row name used by every release up to and including 3.0.0.
	 */
	const LEGACY_OPTION = 'dbmanager_options';

	/**
	 * Default settings.
	 *
	 * Several defaults depend on other site options, so this is built per call
	 * rather than held in a constant.
	 *
	 * The two binary paths default to empty on purpose. Detecting them shells
	 * out to `which`, which is far too expensive to repeat on every read; the
	 * activation hook runs the detection once and stores the result.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mysqldumppath'          => '',
			'mysqlpath'              => '',
			'path'                   => str_replace( '\\', '/', WP_CONTENT_DIR ) . '/backup-db',
			'max_backup'             => 10,
			'backup'                 => 1,
			'backup_gzip'            => 1,
			'backup_period'          => 604800,
			'backup_email'           => '',
			'backup_email_attach'    => 1,
			'backup_email_from'      => get_option( 'admin_email' ),
			'backup_email_from_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' ' . __( 'Administrator', 'wp-dbmanager' ),
			'backup_email_subject'   => __( '%SITE_NAME% Database Backup File For %POST_DATE% @ %POST_TIME%', 'wp-dbmanager' ),
			'optimize'               => 3,
			'optimize_period'        => 86400,
			'repair'                 => 2,
			'repair_period'          => 604800,
			'hide_admin_notices'     => 0,
		);
	}

	/**
	 * Get every setting, or one of them.
	 *
	 * Merged over the defaults, so a key that was never written does not have to
	 * be guarded at the call site.
	 *
	 * @param string|null $key Setting to return, or null for all of them.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$stored = get_option( self::OPTION, array() );
		$data   = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );

		if ( null === $key ) {
			return $data;
		}

		return isset( $data[ $key ] ) ? $data[ $key ] : null;
	}

	/**
	 * Store the settings.
	 *
	 * @param array $options Settings to store.
	 * @return bool Whether the row changed.
	 */
	public static function update( array $options ) {
		return update_option( self::OPTION, $options );
	}

	/**
	 * The configured backup folder.
	 *
	 * @return string
	 */
	public static function backup_path() {
		return (string) self::get( 'path' );
	}

	/**
	 * The periods offered for the three schedules.
	 *
	 * Shared by the settings screen and its sanitizer so the screen cannot offer
	 * an interval the sanitizer then rejects, which is how a setting comes to
	 * report "saved" and silently revert.
	 *
	 * @return array Seconds => label.
	 */
	public static function periods() {
		return array(
			0       => __( 'Disable', 'wp-dbmanager' ),
			60      => __( 'Minutes(s)', 'wp-dbmanager' ),
			3600    => __( 'Hour(s)', 'wp-dbmanager' ),
			86400   => __( 'Day(s)', 'wp-dbmanager' ),
			604800  => __( 'Week(s)', 'wp-dbmanager' ),
			2592000 => __( 'Month(s)', 'wp-dbmanager' ),
		);
	}

	/**
	 * The stored upgrade markers, normalised.
	 *
	 * @return array
	 */
	public static function markers() {
		$markers = get_option( self::VERSION, array() );

		return is_array( $markers ) ? $markers : array();
	}

	/**
	 * Write the settings row from inside an upgrade.
	 *
	 * `update_option()` declines to write a value equal to the one
	 * `get_option()` would return, and `register_setting()` is passed a
	 * `default`, which installs a `default_option_wp_dbmanager_options` filter answering with
	 * the shipped defaults for a row that does not exist. So on an admin request
	 * -- the path every real update takes, because activation hooks do not fire
	 * on an update -- a migration whose result happens to equal the defaults
	 * writes nothing at all, while the legacy rows it read are deleted anyway.
	 *
	 * Passing an explicit default to `get_option()` defeats the registered one,
	 * because `filter_default_option()` returns early when a default was passed.
	 * That is what lets an absent row be told from a defaulted one and added
	 * outright. `add_option()` runs the sanitize callback exactly as
	 * `update_option()` does, so nothing else about the write changes.
	 *
	 * Latent here rather than live: `get()` merges over the defaults, so a
	 * missing row and a defaults row read identically and nothing is lost today.
	 * It stops being latent the moment this plugin gains a setting whose
	 * *absence* means something other than its default -- and then the failure is
	 * silent, browser-only, and the legacy rows are already gone. §7.6.1 has the
	 * three plugins this shape has already bitten.
	 *
	 * @param array $options The settings to store.
	 * @return void
	 */
	private static function write( array $options ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $options );

			return;
		}

		update_option( self::OPTION, $options );
	}

	/**
	 * Move an older install onto the current row names and record the version.
	 *
	 * Everything up to and including the released 3.0.0 stored its settings in
	 * dbmanager_options, so this runs against a row that is out in the world
	 * rather than against an unreleased rename. The old row is read, written to
	 * the new name and deleted; a site that has already migrated finds nothing
	 * to fold in and only the markers are refreshed.
	 *
	 * Both markers are written in one call at the end, so an upgrade that dies
	 * half way never records itself as finished.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$markers = self::markers();

		$plugin = isset( $markers['plugin'] ) ? (string) $markers['plugin'] : '';
		$db     = isset( $markers['db'] ) ? (string) $markers['db'] : '';

		if ( WP_DBMANAGER_VERSION === $plugin && WP_DBMANAGER_DB_VERSION === $db ) {
			return;
		}

		$legacy = get_option( self::LEGACY_OPTION );

		if ( is_array( $legacy ) ) {
			$current = get_option( self::OPTION );

			// The new row wins if both somehow exist: it is the one the settings
			// screen has been writing to since the migration first ran.
			if ( ! is_array( $current ) ) {
				self::write( array_merge( self::defaults(), $legacy ) );
			}
		}

		if ( false !== $legacy ) {
			delete_option( self::LEGACY_OPTION );
		}

		// The reachability verdict was cached under the unprefixed name, and the
		// prefixed one starts empty rather than inheriting a stale answer.
		delete_transient( 'dbmanager_backup_folder_public' );

		// The three cron events moved with the hooks, and an event cannot be
		// renamed in place.
		WP_DBManager_Cron::migrate();

		update_option(
			self::VERSION,
			array(
				'plugin' => WP_DBMANAGER_VERSION,
				'db'     => WP_DBMANAGER_DB_VERSION,
			),
			true
		);
	}
}
