<?php
/**
 * Settings storage.
 *
 * @package WP-DBManager
 */

/**
 * Reading, writing and defaulting the one option row.
 */
class Test_DBManager_Options extends DBManager_TestCase {

	/**
	 * The plugin owns exactly one row.
	 */
	public function test_there_is_one_option_row() {
		$this->assertSame( 'dbmanager_options', DBManager_Options::OPTION );
	}

	/**
	 * A key that was never written still comes back with a value.
	 *
	 * Merging over the defaults is what stops every call site having to guard,
	 * which is where the PHP 8 warnings used to come from.
	 */
	public function test_missing_keys_fall_back_to_defaults() {
		update_option( DBManager_Options::OPTION, array( 'max_backup' => 3 ) );

		$this->assertSame( 3, DBManager_Options::get( 'max_backup' ) );
		$this->assertSame( DBManager_Options::defaults()['backup_period'], DBManager_Options::get( 'backup_period' ) );
		$this->assertArrayHasKey( 'hide_admin_notices', DBManager_Options::get() );
	}

	/**
	 * A row that is not an array at all does not take the plugin down.
	 */
	public function test_a_corrupt_row_falls_back_to_defaults() {
		update_option( DBManager_Options::OPTION, 'not an array' );

		$this->assertSame( DBManager_Options::defaults(), DBManager_Options::get() );
	}

	/**
	 * An unknown key reads as null rather than warning.
	 */
	public function test_an_unknown_key_is_null() {
		$this->assertNull( DBManager_Options::get( 'no_such_setting' ) );
	}

	/**
	 * Detection is not run on read.
	 *
	 * The binary paths default to empty because detecting them shells out to
	 * `which`, which is far too expensive to repeat on every option read.
	 * Activation does it once and stores the answer.
	 */
	public function test_binary_paths_default_to_empty() {
		$defaults = DBManager_Options::defaults();

		$this->assertSame( '', $defaults['mysqldumppath'] );
		$this->assertSame( '', $defaults['mysqlpath'] );
	}

	/**
	 * The backup folder defaults inside wp-content.
	 */
	public function test_backup_path_defaults_under_wp_content() {
		$this->assertStringEndsWith( '/backup-db', DBManager_Options::defaults()['path'] );
	}

	/**
	 * The periods list is what both the screen and the sanitizer read.
	 */
	public function test_periods_cover_the_offered_intervals() {
		$periods = DBManager_Options::periods();

		$this->assertArrayHasKey( 0, $periods );

		foreach ( array( 60, 3600, 86400, 604800, 2592000 ) as $seconds ) {
			$this->assertArrayHasKey( $seconds, $periods );
		}
	}

	/**
	 * Writing and reading round-trips.
	 */
	public function test_update_round_trips() {
		$values               = DBManager_Options::get();
		$values['max_backup'] = 42;

		DBManager_Options::update( $values );

		$this->assertSame( 42, DBManager_Options::get( 'max_backup' ) );
	}
}
