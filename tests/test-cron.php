<?php
/**
 * The three scheduled jobs.
 *
 * @package WP-DBManager
 */

/**
 * Scheduling used to happen inside the settings form's POST handler, so a
 * change made any other way left the events pointing at the old interval.
 * It hangs off the option now, which is what most of this file checks.
 */
class Test_DBManager_Cron extends DBManager_TestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		DBManager_Cron::init();
	}

	/**
	 * Write settings the way anything other than the form would.
	 *
	 * @param array $changes Settings to change.
	 * @return void
	 */
	protected function save( array $changes ) {
		update_option( DBManager_Options::OPTION, array_merge( DBManager_Options::get(), $changes ) );
	}

	/**
	 * Each schedule is the count multiplied by the period.
	 */
	public function test_schedules_are_the_configured_intervals() {
		$this->save(
			array(
				'backup'          => 2,
				'backup_period'   => 86400,
				'optimize'        => 5,
				'optimize_period' => 3600,
				'repair'          => 4,
				'repair_period'   => 604800,
			)
		);

		$schedules = DBManager_Cron::schedules( array() );

		$this->assertSame( 2 * 86400, $schedules['dbmanager_backup']['interval'] );
		$this->assertSame( 5 * 3600, $schedules['dbmanager_optimize']['interval'] );
		$this->assertSame( 4 * 604800, $schedules['dbmanager_repair']['interval'] );
	}

	/**
	 * A disabled job still gets a registered schedule.
	 *
	 * WordPress warns about an event attached to an interval it does not know,
	 * so the plugin registers an effectively-never one rather than nothing.
	 */
	public function test_a_disabled_job_still_has_a_schedule() {
		$this->save( array( 'backup_period' => 0 ) );

		$schedules = DBManager_Cron::schedules( array() );

		$this->assertArrayHasKey( 'dbmanager_backup', $schedules );
		$this->assertSame( YEAR_IN_SECONDS, $schedules['dbmanager_backup']['interval'] );
	}

	/**
	 * Existing schedules from other plugins are left alone.
	 */
	public function test_schedules_preserves_what_it_was_given() {
		$schedules = DBManager_Cron::schedules( array( 'someone_else' => array( 'interval' => 60 ) ) );

		$this->assertArrayHasKey( 'someone_else', $schedules );
	}

	/**
	 * Saving settings schedules the enabled jobs.
	 */
	public function test_saving_schedules_the_enabled_jobs() {
		$this->save(
			array(
				'backup_period'   => 86400,
				'optimize_period' => 3600,
				'repair_period'   => 604800,
			)
		);

		$this->assertNotFalse( wp_next_scheduled( 'dbmanager_cron_backup' ) );
		$this->assertNotFalse( wp_next_scheduled( 'dbmanager_cron_optimize' ) );
		$this->assertNotFalse( wp_next_scheduled( 'dbmanager_cron_repair' ) );
	}

	/**
	 * Disabling a job unschedules it.
	 */
	public function test_disabling_a_job_unschedules_it() {
		$this->save( array( 'backup_period' => 86400 ) );
		$this->assertNotFalse( wp_next_scheduled( 'dbmanager_cron_backup' ) );

		$this->save( array( 'backup_period' => 0 ) );
		$this->assertFalse( wp_next_scheduled( 'dbmanager_cron_backup' ) );
	}

	/**
	 * A programmatic write reschedules just as a form submission does.
	 *
	 * This is the whole reason the rescheduling moved onto the option: before
	 * 3.0.0 a WP-CLI change to the interval left the event on the old one.
	 */
	public function test_a_programmatic_write_reschedules() {
		$this->save( array( 'optimize_period' => 3600 ) );
		$first = wp_next_scheduled( 'dbmanager_cron_optimize' );

		$this->assertNotFalse( $first );

		$this->save( array( 'optimize_period' => 0 ) );

		$this->assertFalse( wp_next_scheduled( 'dbmanager_cron_optimize' ) );
	}

	/**
	 * Rescheduling does not stack duplicate events.
	 */
	public function test_rescheduling_does_not_duplicate_events() {
		$this->save( array( 'backup_period' => 86400 ) );
		$this->save( array( 'backup_period' => 3600 ) );
		$this->save( array( 'backup_period' => 86400 ) );

		$crons = _get_cron_array();
		$count = 0;

		foreach ( $crons as $events ) {
			if ( isset( $events['dbmanager_cron_backup'] ) ) {
				$count += count( $events['dbmanager_cron_backup'] );
			}
		}

		$this->assertSame( 1, $count );
	}

	/**
	 * Clearing removes every event the plugin owns.
	 */
	public function test_clear_removes_every_event() {
		$this->save(
			array(
				'backup_period'   => 86400,
				'optimize_period' => 3600,
				'repair_period'   => 604800,
			)
		);

		DBManager_Cron::clear();

		foreach ( DBManager_Cron::jobs() as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook );
		}
	}

	/**
	 * A disabled backup job does nothing when it fires.
	 */
	public function test_a_disabled_backup_job_takes_no_backup() {
		$this->save( array( 'backup_period' => 0 ) );

		DBManager_Cron::backup();

		$this->assertSame( array(), DBManager_Backups::all( $this->backup_dir ) );
	}

	/**
	 * A backup job with nowhere to write does not try.
	 */
	public function test_a_backup_job_without_a_folder_does_nothing() {
		$this->save(
			array(
				'backup_period' => 86400,
				'path'          => '/no/such/place',
			)
		);

		DBManager_Cron::backup();

		$this->assertSame( array(), DBManager_Backups::all( $this->backup_dir ) );
	}

	/**
	 * The scheduled backup writes a real dump.
	 *
	 * @group requires-mysqldump
	 */
	public function test_the_scheduled_backup_writes_a_dump() {
		if ( ! is_executable( $this->mysqldump_path() ) ) {
			$this->markTestSkipped( 'mysqldump is not available in this environment.' );
		}

		$this->save( array( 'backup_period' => 86400 ) );

		DBManager_Cron::backup();

		$this->assertCount( 1, DBManager_Backups::all( $this->backup_dir ) );
	}

	/**
	 * A disabled optimize job leaves the tables alone.
	 */
	public function test_a_disabled_optimize_job_does_nothing() {
		$this->save( array( 'optimize_period' => 0 ) );

		$ran = 0;
		add_filter(
			'query',
			function ( $query ) use ( &$ran ) {
				if ( 0 === stripos( $query, 'OPTIMIZE TABLE' ) ) {
					++$ran;
				}
				return $query;
			}
		);

		DBManager_Cron::optimize();

		$this->assertSame( 0, $ran );
	}

	/**
	 * An enabled optimize job runs against every table.
	 */
	public function test_an_enabled_optimize_job_runs() {
		$this->save( array( 'optimize_period' => 3600 ) );

		$seen = '';
		add_filter(
			'query',
			function ( $query ) use ( &$seen ) {
				if ( 0 === stripos( $query, 'OPTIMIZE TABLE' ) ) {
					$seen = $query;
				}
				return $query;
			}
		);

		DBManager_Cron::optimize();

		$this->assertStringContainsString( 'OPTIMIZE TABLE', $seen );
		$this->assertStringContainsString( $GLOBALS['wpdb']->posts, $seen );
	}

	/**
	 * A disabled repair job leaves the tables alone.
	 */
	public function test_a_disabled_repair_job_does_nothing() {
		$this->save( array( 'repair_period' => 0 ) );

		$ran = 0;
		add_filter(
			'query',
			function ( $query ) use ( &$ran ) {
				if ( 0 === stripos( $query, 'REPAIR TABLE' ) ) {
					++$ran;
				}
				return $query;
			}
		);

		DBManager_Cron::repair();

		$this->assertSame( 0, $ran );
	}

	/**
	 * An enabled repair job runs against every table.
	 */
	public function test_an_enabled_repair_job_runs() {
		$this->save( array( 'repair_period' => 604800 ) );

		$seen = '';
		add_filter(
			'query',
			function ( $query ) use ( &$seen ) {
				if ( 0 === stripos( $query, 'REPAIR TABLE' ) ) {
					$seen = $query;
				}
				return $query;
			}
		);

		DBManager_Cron::repair();

		$this->assertStringContainsString( 'REPAIR TABLE', $seen );
	}

	/**
	 * A successful scheduled backup e-mails when an address is configured.
	 *
	 * @group requires-mysqldump
	 */
	public function test_a_successful_scheduled_backup_mails() {
		if ( ! is_executable( $this->mysqldump_path() ) ) {
			$this->markTestSkipped( 'mysqldump is not available in this environment.' );
		}

		$mails = 0;
		add_filter(
			'pre_wp_mail',
			function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);

		$this->save(
			array(
				'backup_period' => 86400,
				'backup_email'  => 'someone@example.com',
			)
		);

		DBManager_Cron::backup();

		$this->assertSame( 1, $mails );
	}

	/**
	 * With no address configured, a successful backup is silent.
	 *
	 * @group requires-mysqldump
	 */
	public function test_a_successful_backup_without_an_address_is_silent() {
		if ( ! is_executable( $this->mysqldump_path() ) ) {
			$this->markTestSkipped( 'mysqldump is not available in this environment.' );
		}

		$mails = 0;
		add_filter(
			'pre_wp_mail',
			function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);

		$this->save(
			array(
				'backup_period' => 86400,
				'backup_email'  => '',
			)
		);

		DBManager_Cron::backup();

		$this->assertCount( 1, DBManager_Backups::all( $this->backup_dir ) );
		$this->assertSame( 0, $mails );
	}

	/**
	 * A failed scheduled backup sends no e-mail.
	 *
	 * The pre-3.0.0 job renamed and mailed whatever was on disk, so a dump that
	 * never ran arrived as a zero byte attachment that looked like a backup.
	 */
	public function test_a_failed_scheduled_backup_sends_no_mail() {
		$mails = 0;

		add_filter(
			'pre_wp_mail',
			function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);

		$this->save(
			array(
				'backup_period' => 86400,
				'backup_email'  => 'someone@example.com',
				'mysqldumppath' => '/nonexistent/mysqldump',
			)
		);

		DBManager_Cron::backup();

		$this->assertSame( 0, $mails );
	}
}
