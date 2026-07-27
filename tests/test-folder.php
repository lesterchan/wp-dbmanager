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
class Test_DBManager_Folder extends DBManager_TestCase {

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

		$this->assertSame( $expected, DBManager_Folder::server_type() );
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

		$options         = DBManager_Options::get();
		$options['path'] = $outside;
		update_option( DBManager_Options::OPTION, $options );

		$this->assertFalse( DBManager_Folder::url() );

		rmdir( $outside );
	}

	/**
	 * A folder inside wp-content resolves to a content URL.
	 */
	public function test_a_folder_inside_wp_content_has_a_url() {
		$inside = WP_CONTENT_DIR . '/wp-dbmanager-inside-' . wp_generate_password( 8, false );
		wp_mkdir_p( $inside );

		$options         = DBManager_Options::get();
		$options['path'] = $inside;
		update_option( DBManager_Options::OPTION, $options );

		$url = DBManager_Folder::url();

		$this->assertNotFalse( $url );
		$this->assertStringStartsWith( content_url(), $url );

		rmdir( $inside );
	}

	/**
	 * A folder that is outside the web root is reported as protected.
	 */
	public function test_a_folder_outside_the_web_root_is_not_public() {
		$outside = get_temp_dir() . 'wp-dbmanager-outside-' . wp_generate_password( 8, false );
		wp_mkdir_p( $outside );

		$options         = DBManager_Options::get();
		$options['path'] = $outside;
		update_option( DBManager_Options::OPTION, $options );

		DBManager_Folder::flush();

		$this->assertFalse( DBManager_Folder::is_public() );

		rmdir( $outside );
	}

	/**
	 * The cached answer is read without making a request.
	 */
	public function test_the_cached_answer_is_reused() {
		set_transient( DBManager_Folder::TRANSIENT, 'public', HOUR_IN_SECONDS );
		$this->assertTrue( DBManager_Folder::is_public( false ) );

		set_transient( DBManager_Folder::TRANSIENT, 'protected', HOUR_IN_SECONDS );
		$this->assertFalse( DBManager_Folder::is_public( false ) );

		set_transient( DBManager_Folder::TRANSIENT, 'unknown', HOUR_IN_SECONDS );
		$this->assertNull( DBManager_Folder::is_public( false ) );
	}

	/**
	 * With nothing cached and no probe allowed, the answer is "do not know".
	 *
	 * The admin notice reads it this way so an unrelated admin page never waits
	 * on two HTTP requests.
	 */
	public function test_no_cache_and_no_probe_is_undetermined() {
		DBManager_Folder::flush();

		$this->assertNull( DBManager_Folder::is_public( false ) );
	}

	/**
	 * Creating the folder drops the protection files in.
	 */
	public function test_create_adds_the_protection_files() {
		$fresh = WP_CONTENT_DIR . '/wp-dbmanager-fresh-' . wp_generate_password( 8, false );

		$options         = DBManager_Options::get();
		$options['path'] = $fresh;
		update_option( DBManager_Options::OPTION, $options );

		$this->pretend_server( 'Apache/2.4.57' );
		DBManager_Folder::create();

		$this->assertDirectoryExists( $fresh );
		$this->assertFileExists( $fresh . '/index.php' );
		$this->assertFileExists( $fresh . '/.htaccess' );

		self::remove_directory( $fresh );
	}

	/**
	 * On IIS the Web.config goes in instead of the .htaccess.
	 */
	public function test_create_uses_web_config_on_iis() {
		$fresh = WP_CONTENT_DIR . '/wp-dbmanager-iis-' . wp_generate_password( 8, false );

		$options         = DBManager_Options::get();
		$options['path'] = $fresh;
		update_option( DBManager_Options::OPTION, $options );

		$this->pretend_server( 'Microsoft-IIS/10.0' );
		DBManager_Folder::create();

		$this->assertFileExists( $fresh . '/Web.config' );
		$this->assertFileDoesNotExist( $fresh . '/.htaccess' );

		self::remove_directory( $fresh );
	}
}
