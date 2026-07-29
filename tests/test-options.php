<?php
/**
 * Settings storage.
 *
 * @package WP-DBManager
 */

/**
 * Reading, writing and defaulting the one option row.
 */
class Test_DBManager_Options extends WP_DBManager_TestCase {

	/**
	 * The plugin owns exactly one row.
	 */
	public function test_there_is_one_option_row() {
		$this->assertSame( 'dbmanager_options', WP_DBManager_Options::OPTION );
	}

	/**
	 * A key that was never written still comes back with a value.
	 *
	 * Merging over the defaults is what stops every call site having to guard,
	 * which is where the PHP 8 warnings used to come from.
	 */
	public function test_missing_keys_fall_back_to_defaults() {
		update_option( WP_DBManager_Options::OPTION, array( 'max_backup' => 3 ) );

		$this->assertSame( 3, WP_DBManager_Options::get( 'max_backup' ) );
		$this->assertSame( WP_DBManager_Options::defaults()['backup_period'], WP_DBManager_Options::get( 'backup_period' ) );
		$this->assertArrayHasKey( 'hide_admin_notices', WP_DBManager_Options::get() );
	}

	/**
	 * A row that is not an array at all does not take the plugin down.
	 */
	public function test_a_corrupt_row_falls_back_to_defaults() {
		update_option( WP_DBManager_Options::OPTION, 'not an array' );

		$this->assertSame( WP_DBManager_Options::defaults(), WP_DBManager_Options::get() );
	}

	/**
	 * An unknown key reads as null rather than warning.
	 */
	public function test_an_unknown_key_is_null() {
		$this->assertNull( WP_DBManager_Options::get( 'no_such_setting' ) );
	}

	/**
	 * Detection is not run on read.
	 *
	 * The binary paths default to empty because detecting them shells out to
	 * `which`, which is far too expensive to repeat on every option read.
	 * Activation does it once and stores the answer.
	 */
	public function test_binary_paths_default_to_empty() {
		$defaults = WP_DBManager_Options::defaults();

		$this->assertSame( '', $defaults['mysqldumppath'] );
		$this->assertSame( '', $defaults['mysqlpath'] );
	}

	/**
	 * The backup folder defaults inside wp-content.
	 */
	public function test_backup_path_defaults_under_wp_content() {
		$this->assertStringEndsWith( '/backup-db', WP_DBManager_Options::defaults()['path'] );
	}

	/**
	 * The periods list is what both the screen and the sanitizer read.
	 */
	public function test_periods_cover_the_offered_intervals() {
		$periods = WP_DBManager_Options::periods();

		$this->assertArrayHasKey( 0, $periods );

		foreach ( array( 60, 3600, 86400, 604800, 2592000 ) as $seconds ) {
			$this->assertArrayHasKey( $seconds, $periods );
		}
	}

	/**
	 * The backup path helper always hands back a string.
	 *
	 * Callers concatenate it straight into a file path, so a null from a
	 * half-written option row would become "/index.php" at the filesystem root.
	 */
	public function test_backup_path_is_always_a_string() {
		$this->assertIsString( WP_DBManager_Options::backup_path() );
		$this->assertSame( WP_DBManager_Options::get( 'path' ), WP_DBManager_Options::backup_path() );

		update_option( WP_DBManager_Options::OPTION, array( 'path' => '/somewhere/else' ) );
		$this->assertSame( '/somewhere/else', WP_DBManager_Options::backup_path() );

		update_option( WP_DBManager_Options::OPTION, 'not an array' );
		$this->assertIsString( WP_DBManager_Options::backup_path() );
	}

	/**
	 * Writing and reading round-trips.
	 */
	public function test_update_round_trips() {
		$values               = WP_DBManager_Options::get();
		$values['max_backup'] = 42;

		WP_DBManager_Options::update( $values );

		$this->assertSame( 42, WP_DBManager_Options::get( 'max_backup' ) );
	}
}
