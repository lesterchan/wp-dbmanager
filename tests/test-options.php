<?php
/**
 * Settings storage.
 *
 * @package WP-DBManager
 */

/**
 * Reading, writing and defaulting the one option row.
 */
class WP_DBManager_Options_Test extends WP_DBManager_TestCase {

	/**
	 * The plugin owns one settings row and one markers row, both prefixed.
	 */
	public function test_the_two_row_names_are_prefixed() {
		$this->assertSame( 'wp_dbmanager_options', WP_DBManager_Options::OPTION, 'The options row is prefixed.' );
		$this->assertSame( 'wp_dbmanager_version', WP_DBManager_Options::VERSION, 'And so is the version row.' );
	}

	/**
	 * A key that was never written still comes back with a value.
	 *
	 * Merging over the defaults is what stops every call site having to guard,
	 * which is where the PHP 8 warnings used to come from.
	 */
	public function test_missing_keys_fall_back_to_defaults() {
		update_option( WP_DBManager_Options::OPTION, array( 'max_backup' => 3 ) );

		$this->assertSame( 3, WP_DBManager_Options::get( 'max_backup' ), 'A missing key falls back to its default.' );
		$this->assertSame( WP_DBManager_Options::defaults()['backup_period'], WP_DBManager_Options::get( 'backup_period' ), 'Whatever the default happens to be.' );
		$this->assertArrayHasKey( 'hide_admin_notices', WP_DBManager_Options::get(), 'A key absent from the stored row falls back to its shipped default.' );
	}

	/**
	 * A row that is not an array at all does not take the plugin down.
	 */
	public function test_a_corrupt_row_falls_back_to_defaults() {
		update_option( WP_DBManager_Options::OPTION, 'not an array' );

		$this->assertSame( WP_DBManager_Options::defaults(), WP_DBManager_Options::get(), 'A corrupt row falls back to the defaults rather than to nothing.' );
	}

	/**
	 * An unknown key reads as null rather than warning.
	 */
	public function test_an_unknown_key_is_null() {
		$this->assertNull( WP_DBManager_Options::get( 'no_such_setting' ), 'An unknown key reads back null rather than raising a notice.' );
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

		$this->assertSame( '', $defaults['mysqldumppath'], 'The mysqldump path defaults to empty, so it has to be found or configured.' );
		$this->assertSame( '', $defaults['mysqlpath'], 'And so does the mysql path.' );
	}

	/**
	 * The backup folder defaults inside wp-content.
	 */
	public function test_backup_path_defaults_under_wp_content() {
		$this->assertStringEndsWith( '/backup-db', WP_DBManager_Options::defaults()['path'], 'The backup path defaults to a folder under wp-content.' );
	}

	/**
	 * The periods list is what both the screen and the sanitizer read.
	 */
	public function test_periods_cover_the_offered_intervals() {
		$periods = WP_DBManager_Options::periods();

		$this->assertArrayHasKey( 0, $periods, 'The periods list offers a zero, which is how a job is disabled.' );

		foreach ( array( 60, 3600, 86400, 604800, 2592000 ) as $seconds ) {
			$this->assertArrayHasKey( $seconds, $periods, 'The periods list is missing the ' . $seconds . ' second interval it offers.' );
		}
	}

	/**
	 * The backup path helper always hands back a string.
	 *
	 * Callers concatenate it straight into a file path, so a null from a
	 * half-written option row would become "/index.php" at the filesystem root.
	 */
	public function test_backup_path_is_always_a_string() {
		$this->assertIsString( WP_DBManager_Options::backup_path(), 'The backup path is a string even before anything is stored.' );
		$this->assertSame( WP_DBManager_Options::get( 'path' ), WP_DBManager_Options::backup_path(), 'The accessor answers with the stored path.' );

		update_option( WP_DBManager_Options::OPTION, array( 'path' => '/somewhere/else' ) );
		$this->assertSame( '/somewhere/else', WP_DBManager_Options::backup_path(), 'And follows it when it changes.' );

		update_option( WP_DBManager_Options::OPTION, 'not an array' );
		$this->assertIsString( WP_DBManager_Options::backup_path(), 'The backup path is a string even when the stored value is junk.' );
	}

	/**
	 * Writing and reading round-trips.
	 */
	public function test_update_round_trips() {
		$values               = WP_DBManager_Options::get();
		$values['max_backup'] = 42;

		WP_DBManager_Options::update( $values );

		$this->assertSame( 42, WP_DBManager_Options::get( 'max_backup' ), 'An update round trips.' );
	}
}
