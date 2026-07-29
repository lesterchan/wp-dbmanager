<?php
/**
 * The three scheduled jobs.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's cron schedules and runs the scheduled work.
 *
 * @since 3.0.0
 */
class WP_DBManager_Cron {

	/**
	 * The three jobs, as setting prefix => hook.
	 *
	 * Driving the schedule wiring off one list is what stops the "clear, test,
	 * reschedule" block being written out three times with one of the three
	 * quietly reading the wrong option.
	 *
	 * @return array
	 */
	public static function jobs() {
		return array(
			'backup'   => 'wp_dbmanager_cron_backup',
			'optimize' => 'wp_dbmanager_cron_optimize',
			'repair'   => 'wp_dbmanager_cron_repair',
		);
	}

	/**
	 * The unprefixed hook names used up to and including 3.0.0.
	 *
	 * @return array
	 */
	public static function legacy_jobs() {
		return array(
			'backup'   => 'dbmanager_cron_backup',
			'optimize' => 'dbmanager_cron_optimize',
			'repair'   => 'dbmanager_cron_repair',
		);
	}

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		self::register_schedules();

		foreach ( self::jobs() as $job => $hook ) {
			add_action( $hook, array( __CLASS__, $job ) );
		}

		// Whenever the settings change, however they change - the settings screen,
		// WP-CLI, another plugin - the schedule follows. Hanging this off the
		// option rather than off the form is what keeps the two in step.
		add_action( 'update_option_' . WP_DBManager_Options::OPTION, array( __CLASS__, 'reschedule' ) );
		add_action( 'add_option_' . WP_DBManager_Options::OPTION, array( __CLASS__, 'reschedule' ) );
	}

	/**
	 * Register the plugin's recurrences, once.
	 *
	 * Guarded because the migration needs them registered too, and it can run
	 * from the activation hook - by which point plugins_loaded, and so init(),
	 * has already been and gone for this request.
	 *
	 * @return void
	 */
	public static function register_schedules() {
		if ( has_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ) ) {
			return;
		}

		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- The sniff wants a fixed interval of at least fifteen minutes; here the interval is the site owner's own Backup/Optimize/Repair setting, and registering it is the documented way to schedule on a user-chosen period.
	}

	/**
	 * Move a pre-4.0.0 install onto the prefixed hook names.
	 *
	 * The events themselves cannot be renamed in place, so the old ones are
	 * cleared and the schedule is rebuilt from the settings. Doing nothing here
	 * would leave a site with three orphaned events firing hooks nothing listens
	 * to, and no scheduled backups at all.
	 *
	 * @return void
	 */
	public static function migrate() {
		self::register_schedules();

		foreach ( self::legacy_jobs() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		self::reschedule();
	}

	/**
	 * Register the plugin's cron schedules from the configured intervals.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$options = WP_DBManager_Options::get();

		$labels = array(
			'backup'   => __( 'WP-DBManager Backup Schedule', 'wp-dbmanager' ),
			'optimize' => __( 'WP-DBManager Optimize Schedule', 'wp-dbmanager' ),
			'repair'   => __( 'WP-DBManager Repair Schedule', 'wp-dbmanager' ),
		);

		foreach ( array_keys( self::jobs() ) as $job ) {
			$interval = (int) $options[ $job ] * (int) $options[ $job . '_period' ];

			// A disabled job still needs a registered schedule, because WordPress
			// warns about events attached to an interval it does not know. A year
			// is the plugin's long-standing stand-in for "effectively never".
			if ( 0 === $interval ) {
				$interval = YEAR_IN_SECONDS;
			}

			$schedules[ 'wp_dbmanager_' . $job ] = array(
				'interval' => $interval,
				'display'  => $labels[ $job ],
			);
		}

		return $schedules;
	}

	/**
	 * Line the scheduled events up with the current settings.
	 *
	 * @return void
	 */
	public static function reschedule() {
		$options = WP_DBManager_Options::get();

		foreach ( self::jobs() as $job => $hook ) {
			wp_clear_scheduled_hook( $hook );

			if ( (int) $options[ $job . '_period' ] > 0 ) {
				wp_schedule_event( time(), 'wp_dbmanager_' . $job, $hook );
			}
		}
	}

	/**
	 * Remove every scheduled event the plugin owns.
	 *
	 * @return void
	 */
	public static function clear() {
		foreach ( self::jobs() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Run the scheduled database backup.
	 *
	 * @return void
	 */
	public static function backup() {
		$options = WP_DBManager_Options::get();

		// Do not start a dump that has nowhere to land.
		if ( '' === $options['path'] || ! WP_DBManager_Backups::is_folder_valid( $options['path'] ) ) {
			return;
		}

		if ( (int) $options['backup_period'] <= 0 ) {
			return;
		}

		$result = WP_DBManager_Database::backup( 1 === (int) $options['backup_gzip'] );

		// Never rename or e-mail a dump that did not happen.
		if ( ! $result['success'] ) {
			return;
		}

		if ( ! empty( $options['backup_email'] ) ) {
			WP_DBManager_Mailer::send(
				$options['backup_email'],
				$result['filepath'],
				1 === (int) $options['backup_email_attach']
			);
		}
	}

	/**
	 * Run the scheduled table optimisation.
	 *
	 * @return void
	 */
	public static function optimize() {
		$options = WP_DBManager_Options::get();

		if ( (int) $options['optimize_period'] <= 0 ) {
			return;
		}

		WP_DBManager_Tables::optimize( WP_DBManager_Tables::all() );
	}

	/**
	 * Run the scheduled table repair.
	 *
	 * @return void
	 */
	public static function repair() {
		$options = WP_DBManager_Options::get();

		if ( (int) $options['repair_period'] <= 0 ) {
			return;
		}

		WP_DBManager_Tables::repair( WP_DBManager_Tables::all() );
	}
}
