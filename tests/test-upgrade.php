<?php
/**
 * The settings migration and the version markers.
 *
 * @package WP-DBManager
 */

/**
 * Folding the pre-4.0.0 row into the prefixed one, and the markers that gate it.
 */
class WP_DBManager_Upgrade_Test extends WP_DBManager_TestCase {

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
}
