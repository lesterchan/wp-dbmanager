<?php
/**
 * Shared base for the WP-DBManager tests.
 *
 * @package WP-DBManager
 */

/**
 * Sets up an administrator, a scratch backup folder, and a way to render a
 * screen while watching for PHP diagnostics.
 */
abstract class DBManager_TestCase extends WP_UnitTestCase {

	/**
	 * Diagnostics raised while a screen was rendering.
	 *
	 * @var array
	 */
	protected $notices = array();

	/**
	 * Scratch backup folder for this test.
	 *
	 * @var string
	 */
	protected $backup_dir = '';

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		// Every screen calls wp_die() without the capability, which would take
		// the runner down with it.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->backup_dir = get_temp_dir() . 'wp-dbmanager-tests-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->backup_dir );

		update_option(
			DBManager_Options::OPTION,
			array_merge(
				DBManager_Options::defaults(),
				array(
					'path'          => $this->backup_dir,
					'mysqldumppath' => $this->mysqldump_path(),
					'mysqlpath'     => $this->mysql_path(),
				)
			)
		);

		// Rendering must not depend on whichever answer a previous test cached.
		DBManager_Folder::flush();

		// WP_List_Table resolves a screen in its constructor, so the list table
		// screens need one to exist before they can be rendered at all.
		//
		// $hook_suffix has to be set by hand as well. wp-admin/admin.php always
		// sets it before a page callback runs, so this only matters here - but
		// WP_Screen::get() reads it unguarded on WordPress 6.0, and without it
		// every list table test raises "Undefined index: hook_suffix" against
		// the floor while passing happily on current WordPress.
		$GLOBALS['hook_suffix'] = 'database_page_wp-dbmanager';
		set_current_screen( 'database_page_wp-dbmanager' );

		$this->notices = array();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		self::remove_directory( $this->backup_dir );

		DBManager_Cron::clear();

		parent::tear_down();
	}

	/**
	 * Delete a scratch directory and everything in it.
	 *
	 * Uses scandir() rather than glob(): the plugin drops a .htaccess into every
	 * folder it creates, glob() does not match dotfiles, and the leftover then
	 * fails the rmdir with "Directory not empty" in whichever unrelated test
	 * happened to create the folder.
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	protected static function remove_directory( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) ) {
				self::remove_directory( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}

	/**
	 * Where mysqldump lives in this environment.
	 *
	 * @return string
	 */
	protected function mysqldump_path() {
		return is_executable( '/usr/bin/mysqldump' ) ? '/usr/bin/mysqldump' : 'mysqldump';
	}

	/**
	 * Where the mysql client lives in this environment.
	 *
	 * @return string
	 */
	protected function mysql_path() {
		return is_executable( '/usr/bin/mysql' ) ? '/usr/bin/mysql' : 'mysql';
	}

	/**
	 * Render a screen, capturing its markup and any diagnostics it raised.
	 *
	 * Collecting the diagnostics is what makes this worth more than a smoke
	 * test: an assertion that the screen produced output passes just as happily
	 * on a page that emitted three PHP 8 warnings on the way.
	 *
	 * @param callable $callback Screen renderer.
	 * @param array    $post     $_POST for the request.
	 * @param array    $get      $_GET for the request.
	 * @return string
	 */
	protected function render( $callback, $post = array(), $get = array() ) {
		$_POST    = $post;
		$_GET     = $get;
		$_REQUEST = array_merge( $get, $post );

		$this->notices = array();

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler
			function ( $errno, $errstr, $errfile, $errline ) {
				// Core's own noise is not this plugin's problem.
				if ( false !== strpos( $errfile, 'wp-dbmanager' ) ) {
					$this->notices[] = $errstr . ' in ' . basename( $errfile ) . ':' . $errline;
				}

				return true;
			}
		);

		// Screens call wp_die() when the capability is missing, and that arrives
		// here as an exception straight through the ob_start() below. Without
		// unwinding to the level we came in at, the buffer is left open and
		// PHPUnit marks the test risky rather than passing.
		$level = ob_get_level();

		try {
			ob_start();
			call_user_func( $callback );

			return ob_get_clean();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}

			restore_error_handler();

			$_POST    = array();
			$_GET     = array();
			$_REQUEST = array();
		}
	}

	/**
	 * Assert a rendered screen is free of the damage no PHP-level tool reports.
	 *
	 * Each of these has actually shipped in a WordPress plugin: a translators
	 * comment that drifted into HTML context and printed on screen, an unclosed
	 * tag, a PHP 8 warning from an unguarded superglobal, and a double-encoded
	 * entity from escaping twice.
	 *
	 * @param string $html Rendered markup.
	 * @return void
	 */
	protected function assertScreenIsClean( $html ) {
		$this->assertNotEmpty( $html, 'The screen rendered nothing at all.' );
		$this->assertSame( array(), $this->notices, 'The screen raised PHP diagnostics.' );
		$this->assertStringNotContainsString( 'translators:', $html );
		$this->assertStringNotContainsString( '<?php', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
		$this->assertStringNotContainsString( '&amp;nbsp;', $html );
		$this->assertStringNotContainsString( 'Fatal error', $html );
	}
}
