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
		$this->assertSame( 'wp_dbmanager_options', WP_DBManager_Options::OPTION );
		$this->assertSame( 'wp_dbmanager_version', WP_DBManager_Options::VERSION );
	}

	/**
	 * The pre-4.0.0 settings row is folded into the new one and removed.
	 */
	public function test_the_legacy_settings_row_is_migrated_and_deleted() {
		delete_option( WP_DBManager_Options::OPTION );
		delete_option( WP_DBManager_Options::VERSION );

		update_option( WP_DBManager_Options::LEGACY_OPTION, array( 'max_backup' => 17 ) );

		WP_DBManager_Options::maybe_upgrade();

		$this->assertFalse( get_option( WP_DBManager_Options::LEGACY_OPTION ), 'dbmanager_options must not survive the migration.' );
		$this->assertSame( 17, WP_DBManager_Options::get( 'max_backup' ), 'The migrated value was lost.' );
		$this->assertArrayHasKey( 'backup_period', get_option( WP_DBManager_Options::OPTION ), 'The migrated row must carry the defaults too.' );
	}

	/**
	 * The migration records both markers and then stops doing any work.
	 */
	public function test_the_markers_are_written_once_and_then_left_alone() {
		delete_option( WP_DBManager_Options::VERSION );

		WP_DBManager_Options::maybe_upgrade();

		$this->assertSame(
			array(
				'plugin' => WP_DBMANAGER_VERSION,
				'db'     => WP_DBMANAGER_DB_VERSION,
			),
			get_option( WP_DBManager_Options::VERSION ),
			'The markers row holds the running version and schema counter.'
		);

		update_option( WP_DBManager_Options::LEGACY_OPTION, array( 'max_backup' => 5 ) );

		WP_DBManager_Options::maybe_upgrade();

		$this->assertSame(
			array( 'max_backup' => 5 ),
			get_option( WP_DBManager_Options::LEGACY_OPTION ),
			'A migration that has already run must not touch anything again.'
		);

		delete_option( WP_DBManager_Options::LEGACY_OPTION );
	}

	/**
	 * A row already written under the new name is not overwritten by the old one.
	 */
	public function test_an_existing_new_row_wins_over_the_legacy_one() {
		delete_option( WP_DBManager_Options::VERSION );

		update_option( WP_DBManager_Options::OPTION, array( 'max_backup' => 8 ) );
		update_option( WP_DBManager_Options::LEGACY_OPTION, array( 'max_backup' => 99 ) );

		WP_DBManager_Options::maybe_upgrade();

		$this->assertSame( 8, WP_DBManager_Options::get( 'max_backup' ) );
		$this->assertFalse( get_option( WP_DBManager_Options::LEGACY_OPTION ) );
	}

	/**
	 * The markers row never holds anything but the two markers.
	 */
	public function test_the_markers_row_holds_exactly_two_keys() {
		WP_DBManager_Options::maybe_upgrade();

		$keys = array_keys( WP_DBManager_Options::markers() );
		sort( $keys );

		$this->assertSame( array( 'db', 'plugin' ), $keys );
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
