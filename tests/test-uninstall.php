<?php
/**
 * Uninstall and activation.
 *
 * @package WP-DBManager
 */

/**
 * The multisite loop in uninstall.php is the one thing here that cannot be
 * driven from a single-site suite - building a 101-site network to prove the
 * hundred-and-first is reached is not on - so it is asserted against the source
 * instead. That is deliberately a source-level guard: it cannot prove the loop
 * works, only that the argument which makes it correct has not been dropped.
 */
class Test_DBManager_Uninstall extends WP_DBManager_TestCase {

	/**
	 * The uninstall script's source.
	 *
	 * @return string
	 */
	protected function uninstall_source() {
		return file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	}

	/**
	 * Make dbmanager_uninstall_site() available.
	 *
	 * The uninstall script guards itself with a bare exit() when WP_UNINSTALL_PLUGIN
	 * is not defined - which is correct, and which silently takes the whole test
	 * runner with it if the file is required without the constant. Defining it
	 * first is the only way to load the file at all.
	 *
	 * @return void
	 */
	protected function load_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-dbmanager/wp-dbmanager.php' );
		}

		require_once dirname( __DIR__ ) . '/uninstall.php';
	}

	/**
	 * The site query is not left at WP_Site_Query's default cap of 100.
	 *
	 * Without this a network larger than a hundred sites keeps its options and
	 * its cron events on every site past the hundredth, and uninstall still
	 * reports success.
	 */
	public function test_uninstall_lifts_the_site_query_cap() {
		$this->assertMatchesRegularExpression( "/'number'\s*=>\s*0/", $this->uninstall_source() );
	}

	/**
	 * The blog is restored inside the loop, not after it.
	 *
	 * Switching pushes onto a stack, so restoring once after the loop leaves it
	 * unwound by exactly one.
	 */
	public function test_uninstall_restores_inside_the_loop() {
		$source = $this->uninstall_source();

		$switch  = strpos( $source, 'switch_to_blog(' );
		$restore = strpos( $source, 'restore_current_blog(' );
		$closing = strpos( $source, '} else {' );

		$this->assertNotFalse( $switch );
		$this->assertNotFalse( $restore );
		$this->assertLessThan( $closing, $restore, 'restore_current_blog() is outside the foreach.' );
	}

	/**
	 * The function removed from WordPress in 5.1 is not called.
	 */
	public function test_uninstall_does_not_call_wp_get_sites() {
		$this->assertStringNotContainsString( 'wp_get_sites', $this->uninstall_source() );
	}

	/**
	 * Only site ids are fetched, not a hydrated WP_Site each.
	 */
	public function test_uninstall_asks_for_ids_only() {
		$this->assertMatchesRegularExpression( "/'fields'\s*=>\s*'ids'/", $this->uninstall_source() );
	}

	/**
	 * Uninstalling clears the option, the transient and the cron events.
	 */
	public function test_uninstall_clears_everything_it_owns() {
		$this->load_uninstall();

		update_option( WP_DBManager_Options::OPTION, WP_DBManager_Options::defaults() );
		set_transient( WP_DBManager_Folder::TRANSIENT, 'protected', HOUR_IN_SECONDS );
		wp_schedule_event( time(), 'daily', 'dbmanager_cron_backup' );

		dbmanager_uninstall_site();

		$this->assertFalse( get_option( WP_DBManager_Options::OPTION ) );
		$this->assertFalse( get_transient( WP_DBManager_Folder::TRANSIENT ) );
		$this->assertFalse( wp_next_scheduled( 'dbmanager_cron_backup' ) );
	}

	/**
	 * Uninstalling leaves the user's backups alone.
	 *
	 * They are the user's data, and removing a plugin should not destroy the
	 * only copy of their database.
	 */
	public function test_uninstall_keeps_the_backup_files() {
		$this->load_uninstall();

		$backup = $this->backup_dir . '/a_-_1700000000_-_db.sql';
		file_put_contents( $backup, 'dump' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		dbmanager_uninstall_site();

		$this->assertFileExists( $backup );
	}

	/**
	 * Activation stores the detected binary paths.
	 */
	public function test_activation_stores_the_detected_binaries() {
		delete_option( WP_DBManager_Options::OPTION );

		WP_DBManager::activate_site();

		$stored = get_option( WP_DBManager_Options::OPTION );

		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'mysqldumppath', $stored );
		$this->assertArrayHasKey( 'path', $stored );
	}

	/**
	 * Activation does not overwrite an existing configuration.
	 */
	public function test_activation_leaves_existing_settings_alone() {
		$options               = WP_DBManager_Options::get();
		$options['max_backup'] = 99;
		update_option( WP_DBManager_Options::OPTION, $options );

		WP_DBManager::activate_site();

		$this->assertSame( 99, WP_DBManager_Options::get( 'max_backup' ) );
	}

	/**
	 * The capability retired in 2.80.6 is taken off the administrator role.
	 */
	public function test_activation_drops_the_retired_capability() {
		$role = get_role( 'administrator' );
		$role->add_cap( 'manage_database' );

		WP_DBManager::activate_site();

		$this->assertFalse( get_role( 'administrator' )->has_cap( 'manage_database' ) );
	}
}
