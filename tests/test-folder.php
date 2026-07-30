<?php
/**
 * Backup folder protection.
 *
 * @package WP-DBManager
 */

/**
 * A dump contains the users table, so whether the folder answers over HTTP is
 * the single most consequential thing this plugin reports.
 */
class WP_DBManager_Folder_Test extends WP_DBManager_TestCase {

	/**
	 * Pretend to be a particular server.
	 *
	 * @param string $software SERVER_SOFTWARE value.
	 * @return void
	 */
	protected function pretend_server( $software ) {
		$_SERVER['SERVER_SOFTWARE'] = $software;
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_SERVER['SERVER_SOFTWARE'] );

		parent::tear_down();
	}

	/**
	 * Nginx is told apart from Apache, because it ignores .htaccess entirely.
	 *
	 * @dataProvider data_servers
	 *
	 * @param string $software SERVER_SOFTWARE value.
	 * @param string $expected Detected type.
	 */
	public function test_server_type( $software, $expected ) {
		$this->pretend_server( $software );

		$this->assertSame( $expected, WP_DBManager_Folder::server_type() );
	}

	/**
	 * Server banners and what they mean.
	 *
	 * @return array
	 */
	public function data_servers() {
		return array(
			array( 'nginx/1.24.0', 'nginx' ),
			array( 'Apache/2.4.57 (Debian)', 'apache' ),
			array( 'Microsoft-IIS/10.0', 'iis' ),
			array( 'openresty/1.21.4.1', 'apache' ),
			array( '', 'apache' ),
		);
	}

	/**
	 * A folder outside the web root has no URL to serve.
	 */
	public function test_a_folder_outside_the_web_root_has_no_url() {
		$outside = get_temp_dir() . 'wp-dbmanager-outside-' . wp_generate_password( 8, false );
		wp_mkdir_p( $outside );

		$options         = WP_DBManager_Options::get();
		$options['path'] = $outside;
		update_option( WP_DBManager_Options::OPTION, $options );

		$this->assertFalse( WP_DBManager_Folder::url() );

		self::remove_directory( $outside );
	}

	/**
	 * A folder inside wp-content resolves to a content URL.
	 */
	public function test_a_folder_inside_wp_content_has_a_url() {
		$inside = WP_CONTENT_DIR . '/wp-dbmanager-inside-' . wp_generate_password( 8, false );
		wp_mkdir_p( $inside );

		$options         = WP_DBManager_Options::get();
		$options['path'] = $inside;
		update_option( WP_DBManager_Options::OPTION, $options );

		$url = WP_DBManager_Folder::url();

		$this->assertNotFalse( $url );
		$this->assertStringStartsWith( content_url(), $url );

		self::remove_directory( $inside );
	}

	/**
	 * A folder that is outside the web root is reported as protected.
	 */
	public function test_a_folder_outside_the_web_root_is_not_public() {
		$outside = get_temp_dir() . 'wp-dbmanager-outside-' . wp_generate_password( 8, false );
		wp_mkdir_p( $outside );

		$options         = WP_DBManager_Options::get();
		$options['path'] = $outside;
		update_option( WP_DBManager_Options::OPTION, $options );

		WP_DBManager_Folder::flush();

		$this->assertFalse( WP_DBManager_Folder::is_public() );

		self::remove_directory( $outside );
	}

	/**
	 * The cached answer is read without making a request.
	 */
	public function test_the_cached_answer_is_reused() {
		set_transient( WP_DBManager_Folder::TRANSIENT, 'public', HOUR_IN_SECONDS );
		$this->assertTrue( WP_DBManager_Folder::is_public( false ) );

		set_transient( WP_DBManager_Folder::TRANSIENT, 'protected', HOUR_IN_SECONDS );
		$this->assertFalse( WP_DBManager_Folder::is_public( false ) );

		set_transient( WP_DBManager_Folder::TRANSIENT, 'unknown', HOUR_IN_SECONDS );
		$this->assertNull( WP_DBManager_Folder::is_public( false ) );
	}

	/**
	 * With nothing cached and no probe allowed, the answer is "do not know".
	 *
	 * The admin notice reads it this way so an unrelated admin page never waits
	 * on two HTTP requests.
	 */
	public function test_no_cache_and_no_probe_is_undetermined() {
		WP_DBManager_Folder::flush();

		$this->assertNull( WP_DBManager_Folder::is_public( false ) );
	}

	/**
	 * Creating the folder drops the protection files in.
	 */
	public function test_create_adds_the_protection_files() {
		$fresh = WP_CONTENT_DIR . '/wp-dbmanager-fresh-' . wp_generate_password( 8, false );

		$options         = WP_DBManager_Options::get();
		$options['path'] = $fresh;
		update_option( WP_DBManager_Options::OPTION, $options );

		$this->pretend_server( 'Apache/2.4.57' );
		WP_DBManager_Folder::create();

		$this->assertDirectoryExists( $fresh );
		$this->assertFileExists( $fresh . '/index.php' );
		$this->assertFileExists( $fresh . '/.htaccess' );

		self::remove_directory( $fresh );
	}

	/**
	 * IIS is recognised, everything else is not.
	 */
	public function test_is_iis() {
		$this->pretend_server( 'Microsoft-IIS/10.0' );
		$this->assertTrue( WP_DBManager_Folder::is_iis() );

		$this->pretend_server( 'nginx/1.24.0' );
		$this->assertFalse( WP_DBManager_Folder::is_iis() );
	}

	/**
	 * The probe asks for a real backup when there is one.
	 *
	 * That is the exact question worth answering: can somebody download the
	 * dump? Asking for .htaccess instead reads as a false all-clear on the many
	 * nginx configs that deny dotfiles while happily serving .sql.
	 */
	public function test_the_probe_prefers_a_real_backup() {
		$method = new ReflectionMethod( 'WP_DBManager_Folder', 'probe_target' );

		// Nothing at all to ask for.
		$this->assertSame( '', $method->invoke( null, $this->backup_dir ) );

		// Only the silence guard.
		self::write_file( $this->backup_dir . '/index.php', '<?php' );
		$this->assertSame( 'index.php', $method->invoke( null, $this->backup_dir ) );

		// A real dump wins.
		$name = str_repeat( 'f', 32 ) . '_-_1700000000_-_db.sql';
		self::write_file( $this->backup_dir . '/' . $name, 'SOME SQL' );
		$this->assertSame( $name, $method->invoke( null, $this->backup_dir ) );
	}

	/**
	 * The fix link ignores requests that are not asking for a fix.
	 */
	public function test_maybe_fix_ignores_other_requests() {
		$_GET = array();
		$this->assertNull( WP_DBManager_Folder::maybe_fix() );

		$_GET = array( 'try_fix' => '0' );
		$this->assertNull( WP_DBManager_Folder::maybe_fix() );

		$_GET = array();
	}

	/**
	 * The fix link needs the capability.
	 */
	public function test_maybe_fix_requires_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_GET = array( 'try_fix' => '1' );

		try {
			$this->expectException( 'WPDieException' );
			WP_DBManager_Folder::maybe_fix();
		} finally {
			$_GET = array();
		}
	}

	/**
	 * The fix link needs a valid nonce even from an administrator.
	 */
	public function test_maybe_fix_requires_a_nonce() {
		$_GET = array(
			'try_fix'  => '1',
			'_wpnonce' => 'not-a-real-nonce',
		);

		try {
			$this->expectException( 'WPDieException' );
			WP_DBManager_Folder::maybe_fix();
		} finally {
			$_GET = array();
		}
	}

	/**
	 * A proper request recreates the folder and forgets the cached verdict.
	 */
	public function test_maybe_fix_recreates_the_folder() {
		$fresh = WP_CONTENT_DIR . '/wp-dbmanager-fix-' . wp_generate_password( 8, false );

		$options         = WP_DBManager_Options::get();
		$options['path'] = $fresh;
		update_option( WP_DBManager_Options::OPTION, $options );

		set_transient( WP_DBManager_Folder::TRANSIENT, 'public', HOUR_IN_SECONDS );

		$this->pretend_server( 'Apache/2.4.57' );

		// check_admin_referer() reads the nonce out of $_REQUEST, not $_GET.
		$_GET     = array(
			'try_fix'  => '1',
			'_wpnonce' => wp_create_nonce( 'wp-dbmanager_fix' ),
		);
		$_REQUEST = $_GET;

		WP_DBManager_Folder::maybe_fix();
		$_GET     = array();
		$_REQUEST = array();

		$this->assertDirectoryExists( $fresh );
		$this->assertFileExists( $fresh . '/index.php' );
		$this->assertFalse( get_transient( WP_DBManager_Folder::TRANSIENT ), 'The stale verdict survived the fix.' );

		self::remove_directory( $fresh );
	}

	/**
	 * On IIS the Web.config goes in instead of the .htaccess.
	 */
	public function test_create_uses_web_config_on_iis() {
		$fresh = WP_CONTENT_DIR . '/wp-dbmanager-iis-' . wp_generate_password( 8, false );

		$options         = WP_DBManager_Options::get();
		$options['path'] = $fresh;
		update_option( WP_DBManager_Options::OPTION, $options );

		$this->pretend_server( 'Microsoft-IIS/10.0' );
		WP_DBManager_Folder::create();

		$this->assertFileExists( $fresh . '/Web.config' );
		$this->assertFileDoesNotExist( $fresh . '/.htaccess' );

		self::remove_directory( $fresh );
	}
}
