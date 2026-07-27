<?php
/**
 * Reads, writes and describes the plugin's settings.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * The single dbmanager_options row.
 *
 * The plugin has always kept its settings in one row, so there is no
 * consolidation to do here. What this class replaces is the habit of calling
 * get_option() at the top of every file and then guarding each key at the point
 * of use - or, more often, not guarding it and emitting a PHP 8 warning.
 *
 * @since 3.0.0
 */
class DBManager_Options {

	/**
	 * Option holding the plugin settings.
	 */
	const OPTION = 'dbmanager_options';

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
}
