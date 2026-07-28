<?php
/**
 * The plugin bootstrap.
 *
 * @package WP-DBManager
 */

/**
 * Constants, hook registration and the shared helpers on the main class.
 */
class Test_DBManager_Plugin extends DBManager_TestCase {

	/**
	 * The constants the rest of the plugin builds paths from exist.
	 *
	 * Every path used to be built from the literal slug, so the plugin only
	 * worked when installed as "wp-dbmanager".
	 */
	public function test_path_constants_are_defined() {
		$this->assertTrue( defined( 'WP_DBMANAGER_VERSION' ) );
		$this->assertTrue( defined( 'WP_DBMANAGER_MAIN_FILE' ) );
		$this->assertTrue( defined( 'WP_DBMANAGER_DIR' ) );
		$this->assertTrue( defined( 'WP_DBMANAGER_URL' ) );

		$this->assertStringEndsWith( '/', WP_DBMANAGER_DIR );
		$this->assertStringEndsWith( '/', WP_DBMANAGER_URL );
		$this->assertFileExists( WP_DBMANAGER_DIR . 'wp-dbmanager.php' );
		$this->assertFileExists( WP_DBMANAGER_MAIN_FILE );
	}

	/**
	 * The version constant matches the plugin header.
	 *
	 * These drift apart at release time, and nothing else notices.
	 */
	public function test_the_version_constant_matches_the_header() {
		$header = get_file_data( WP_DBMANAGER_MAIN_FILE, array( 'Version' => 'Version' ) );

		$this->assertSame( $header['Version'], WP_DBMANAGER_VERSION );
	}

	/**
	 * The version constant matches the readme's stable tag.
	 */
	public function test_the_version_matches_the_stable_tag() {
		$readme = file_get_contents( WP_DBMANAGER_DIR . 'README.md' );

		$this->assertSame( 1, preg_match( '/^Stable tag: ([0-9.]+)/m', $readme, $m ) );
		$this->assertSame( $m[1], WP_DBMANAGER_VERSION );
	}

	/**
	 * There is one instance.
	 */
	public function test_get_instance_is_a_singleton() {
		$this->assertSame( DBManager::get_instance(), DBManager::get_instance() );
		$this->assertInstanceOf( 'DBManager', DBManager::get_instance() );
	}

	/**
	 * .sql uploads are permitted.
	 */
	public function test_sql_uploads_are_allowed() {
		$mimes = DBManager::get_instance()->upload_mimes( array() );

		$this->assertArrayHasKey( 'sql', $mimes );
		$this->assertSame( 'application/sql', $mimes['sql'] );

		// Whatever was already allowed stays allowed.
		$this->assertArrayHasKey( 'jpg|jpeg|jpe', DBManager::get_instance()->upload_mimes( array( 'jpg|jpeg|jpe' => 'image/jpeg' ) ) );
	}

	/**
	 * The filter is actually wired up, not merely available.
	 */
	public function test_the_hooks_are_registered() {
		$this->assertNotFalse( has_filter( 'upload_mimes' ) );
		$this->assertNotFalse( has_action( 'init', array( 'DBManager_Backups', 'maybe_download' ) ) );
		$this->assertNotFalse( has_action( 'init', array( 'DBManager_Folder', 'maybe_fix' ) ) );
		$this->assertNotFalse( has_filter( 'cron_schedules' ) );
	}

	/**
	 * The admin classes hook themselves up.
	 */
	public function test_the_admin_classes_register_their_hooks() {
		remove_all_actions( 'admin_menu' );
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'admin_enqueue_scripts' );
		remove_all_actions( 'admin_init' );

		DBManager_Admin::init();
		DBManager_Settings::init();

		$this->assertNotFalse( has_action( 'admin_menu', array( 'DBManager_Admin', 'menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( 'DBManager_Admin', 'notices' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( 'DBManager_Admin', 'enqueue' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( 'DBManager_Settings', 'register' ) ) );
	}

	/**
	 * Activating on a single site sets it up without touching the network.
	 */
	public function test_activation_on_a_single_site() {
		delete_option( DBManager_Options::OPTION );

		DBManager::get_instance()->activate( false );

		$this->assertIsArray( get_option( DBManager_Options::OPTION ) );
	}

	/**
	 * Network activation covers every site, not the first hundred.
	 *
	 * WP_Site_Query caps at 100 by default, so a bare get_sites() silently skips
	 * everything past the hundredth and reports success. Building a 101-site
	 * network in a single-site suite is not on, so this is a source-level guard
	 * on the argument that makes the loop correct.
	 */
	public function test_network_activation_lifts_the_site_query_cap() {
		$source = file_get_contents( WP_DBMANAGER_DIR . 'includes/class-dbmanager.php' );

		$this->assertMatchesRegularExpression( "/'number'\s*=>\s*0/", $source );
		$this->assertMatchesRegularExpression( "/'fields'\s*=>\s*'ids'/", $source );

		// Paired inside the loop: switch_to_blog() pushes onto a stack.
		$restore = strpos( $source, 'restore_current_blog(' );
		$closing = strpos( $source, 'return;', $restore );

		$this->assertNotFalse( $restore );
		$this->assertLessThan( $closing, $restore );
	}

	/**
	 * A timestamp is formatted in the site's timezone.
	 *
	 * Backup timestamps are stored as real Unix time, so the offset has to be
	 * applied before formatting or every backup shows the wrong time.
	 */
	public function test_timestamps_honour_the_site_offset() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 0 );
		$utc = DBManager::format_timestamp( 1700000000, 'Y-m-d H:i' );

		update_option( 'gmt_offset', 8 );
		$plus_eight = DBManager::format_timestamp( 1700000000, 'Y-m-d H:i' );

		$this->assertSame( '2023-11-14 22:13', $utc );
		$this->assertSame( '2023-11-15 06:13', $plus_eight );
	}

	/**
	 * A negative offset works too.
	 */
	public function test_a_negative_offset_is_applied() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -5 );

		$this->assertSame( '2023-11-14 17:13', DBManager::format_timestamp( 1700000000, 'Y-m-d H:i' ) );
	}

	/**
	 * With no format the site's date and time formats are combined.
	 */
	public function test_the_default_format_uses_the_site_settings() {
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		update_option( 'gmt_offset', 0 );

		$this->assertStringContainsString( '2023-11-14', DBManager::format_timestamp( 1700000000 ) );
		$this->assertStringContainsString( '22:13', DBManager::format_timestamp( 1700000000 ) );
	}

	/**
	 * The plugin does not load its own textdomain.
	 *
	 * Since WordPress 6.7 an early load_plugin_textdomain() triggers
	 * _doing_it_wrong, and WordPress.org-hosted plugins get language packs
	 * without asking.
	 */
	public function test_no_textdomain_is_loaded() {
		$files = array_merge(
			glob( WP_DBMANAGER_DIR . '*.php' ),
			glob( WP_DBMANAGER_DIR . 'includes/*.php' )
		);

		foreach ( $files as $file ) {
			$this->assertStringNotContainsString(
				'load_plugin_textdomain',
				file_get_contents( $file ),
				basename( $file )
			);
		}
	}

	/**
	 * Every shipped PHP directory has its silence guard.
	 */
	public function test_directories_are_guarded() {
		foreach ( array( '', 'includes/', 'tests/' ) as $dir ) {
			$this->assertFileExists( WP_DBMANAGER_DIR . $dir . 'index.php', '' !== $dir ? $dir : 'plugin root' );
		}
	}

	/**
	 * Every shipped file refuses to run when loaded directly.
	 */
	public function test_every_class_file_guards_against_direct_access() {
		foreach ( glob( WP_DBMANAGER_DIR . 'includes/class-*.php' ) as $file ) {
			$this->assertStringContainsString(
				"defined( 'ABSPATH' ) || exit;",
				file_get_contents( $file ),
				basename( $file )
			);
		}
	}
}
