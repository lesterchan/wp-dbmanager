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
	 * A site sitting on the shipped settings still gets its row written.
	 *
	 * The fixture above customises max_backup, so its migration produces a value
	 * that differs from the defaults and lands however the row is read first.
	 * That fixture cannot see §7.6.1 at all. This one seeds the settings 3.0.0
	 * shipped, unchanged -- the commonest install there is -- and puts the
	 * fold-in on the far side of register_setting(), which is where an update
	 * through the Plugins screen puts it and where WP-CLI never does.
	 *
	 * With that `default` in force a one-argument get_option() answers for a row
	 * that was never written, so the absent row and a defaults row read alike,
	 * the fold-in is skipped, and dbmanager_options is deleted regardless. The
	 * result is a site silently back on the defaults with its old row gone.
	 *
	 * Asserted on the raw row rather than through get(), which merges over the
	 * defaults and so cannot tell a write that happened from one that did not.
	 */
	public function test_a_legacy_row_equal_to_the_defaults_is_still_written() {
		delete_option( WP_DBManager_Options::OPTION );
		delete_option( WP_DBManager_Options::VERSION );

		// The filter that makes an absent row read back as the defaults. It is
		// live on the admin request every real update takes, and on no other path.
		WP_DBManager_Settings::register();

		update_option( WP_DBManager_Options::LEGACY_OPTION, WP_DBManager_Options::defaults() );

		$this->assertFalse( get_option( WP_DBManager_Options::OPTION, false ), 'The fixture is only pre-migration if the new row is genuinely absent.' );

		WP_DBManager_Options::maybe_upgrade();

		$stored = get_option( WP_DBManager_Options::OPTION, false );

		$this->assertIsArray( $stored, 'The migration must write the row even when its result equals the shipped defaults.' );
		$this->assertSame( WP_DBManager_Options::defaults()['max_backup'], $stored['max_backup'], 'And the value is in the row, not merely returned by the registered default.' );
		$this->assertFalse( get_option( WP_DBManager_Options::LEGACY_OPTION ), 'dbmanager_options must not survive the migration.' );
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

		$this->assertSame( 8, WP_DBManager_Options::get( 'max_backup' ), 'An existing new row wins over the legacy one rather than being overwritten by it.' );
		$this->assertFalse( get_option( WP_DBManager_Options::LEGACY_OPTION ), 'The legacy row is deleted once the new row has won.' );
	}

	/**
	 * The markers row never holds anything but the two markers.
	 */
	public function test_the_markers_row_holds_exactly_two_keys() {
		WP_DBManager_Options::maybe_upgrade();

		$keys = array_keys( WP_DBManager_Options::markers() );
		sort( $keys );

		$this->assertSame( array( 'db', 'plugin' ), $keys, 'The markers row holds exactly these two keys.' );
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
