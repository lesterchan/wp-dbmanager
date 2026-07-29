<?php
/**
 * The Database menu and the backup folder notice.
 *
 * @package WP-DBManager
 */

/**
 * Menu registration, asset loading and the admin notice.
 *
 * The notice is the plugin's one piece of unsolicited UI, and it reports on
 * whether a copy of the whole database is downloadable, so the conditions it
 * appears under are worth pinning down.
 */
class Test_DBManager_Admin extends WP_DBManager_TestCase {

	/**
	 * Render the admin notice.
	 *
	 * @return string
	 */
	protected function notice() {
		return $this->render( array( 'WP_DBManager_Admin', 'notices' ) );
	}

	/**
	 * Every page has a slug, and none of them carries a file path.
	 *
	 * The legacy form put the plugin's directory name into every admin URL,
	 * which is why the plugin only worked when installed as "wp-dbmanager".
	 */
	public function test_pages_are_slugs_not_file_paths() {
		$pages = WP_DBManager_Admin::pages();

		$this->assertCount( 8, $pages );

		foreach ( $pages as $key => $slug ) {
			$this->assertStringNotContainsString( '.php', $slug, $key );
			$this->assertStringNotContainsString( '/', $slug, $key );
			$this->assertStringStartsWith( 'wp-dbmanager', $slug, $key );
		}

		$this->assertSame( count( $pages ), count( array_unique( $pages ) ), 'Two pages share a slug.' );
	}

	/**
	 * A page URL points at admin.php with the right slug.
	 */
	public function test_page_url_builds_an_admin_url() {
		$url = WP_DBManager_Admin::page_url( 'backup' );

		$this->assertStringContainsString( 'admin.php', $url );
		$this->assertStringContainsString( 'page=wp-dbmanager-backup', $url );
	}

	/**
	 * Extra query arguments survive.
	 */
	public function test_page_url_carries_extra_arguments() {
		$this->assertStringContainsString( 'try_fix=1', WP_DBManager_Admin::page_url( 'backup', array( 'try_fix' => 1 ) ) );
	}

	/**
	 * An unknown key falls back to the top level page rather than a broken URL.
	 */
	public function test_page_url_falls_back_for_an_unknown_key() {
		$this->assertStringContainsString( 'page=wp-dbmanager', WP_DBManager_Admin::page_url( 'no-such-page' ) );
	}

	/**
	 * The menu registers the top level page and all seven sub pages.
	 */
	public function test_menu_registers_every_screen() {
		global $submenu, $menu;

		$menu    = array();
		$submenu = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		WP_DBManager_Admin::menu();

		$pages = WP_DBManager_Admin::pages();

		$this->assertArrayHasKey( $pages['manager'], $submenu );

		$registered = wp_list_pluck( $submenu[ $pages['manager'] ], 2 );

		foreach ( $pages as $key => $slug ) {
			$this->assertContains( $slug, $registered, $key . ' is not in the menu.' );
		}
	}

	/**
	 * Every menu entry demands the same capability.
	 */
	public function test_every_screen_requires_install_plugins() {
		global $submenu, $menu;

		$menu    = array();
		$submenu = array();

		WP_DBManager_Admin::menu();

		foreach ( $submenu[ WP_DBManager_Admin::pages()['manager'] ] as $entry ) {
			$this->assertSame( 'install_plugins', $entry[1] );
		}
	}

	/**
	 * The capability is install_plugins and reaches every check through one filter.
	 *
	 * Section 2.7 keeps this plugin's custom capability rather than downgrading it
	 * to manage_options: these screens restore, empty and drop tables.
	 */
	public function test_the_capability_is_filterable_and_defaults_to_install_plugins() {
		$this->assertSame( 'install_plugins', WP_DBManager_Admin::CAPABILITY );
		$this->assertSame( 'install_plugins', WP_DBManager_Admin::capability( 'manage' ) );

		$seen = array();

		add_filter(
			'wp_dbmanager_capability',
			function ( $capability, $context ) use ( &$seen ) {
				$seen[] = $context;

				return 'manage_options';
			},
			10,
			2
		);

		$this->assertSame( 'manage_options', WP_DBManager_Admin::capability( 'download' ) );
		$this->assertSame( array( 'download' ), $seen, 'The filter is handed the context it is gating.' );
	}

	/**
	 * The menu itself asks the filter rather than the constant.
	 */
	public function test_the_menu_honours_the_capability_filter() {
		global $submenu, $menu;

		$menu    = array();
		$submenu = array();

		add_filter(
			'wp_dbmanager_capability',
			static function () {
				return 'edit_posts';
			}
		);

		WP_DBManager_Admin::menu();

		foreach ( $submenu[ WP_DBManager_Admin::pages()['manager'] ] as $entry ) {
			$this->assertSame( 'edit_posts', $entry[1] );
		}
	}

	/**
	 * The script loads on the plugin's screens and nowhere else.
	 */
	public function test_the_script_loads_only_on_our_screens() {
		wp_dequeue_script( 'wp-dbmanager' );
		WP_DBManager_Admin::enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( 'wp-dbmanager', 'enqueued' ), 'The script loaded on the dashboard.' );

		WP_DBManager_Admin::enqueue( 'database_page_wp-dbmanager-manage' );
		$this->assertTrue( wp_script_is( 'wp-dbmanager', 'enqueued' ) );
	}

	/**
	 * The detected paths are localised only for the screen that uses them.
	 *
	 * Detection shells out to `which`, so it must not run on the other seven.
	 */
	public function test_the_detected_paths_are_localised_only_on_the_options_screen() {
		wp_dequeue_script( 'wp-dbmanager' );
		wp_deregister_script( 'wp-dbmanager' );
		WP_DBManager_Admin::enqueue( 'database_page_wp-dbmanager-repair' );
		$this->assertEmpty( wp_scripts()->get_data( 'wp-dbmanager', 'data' ) );

		wp_dequeue_script( 'wp-dbmanager' );
		wp_deregister_script( 'wp-dbmanager' );
		WP_DBManager_Admin::enqueue( 'database_page_wp-dbmanager-options' );
		$this->assertStringContainsString( 'wpDBManagerL10n', (string) wp_scripts()->get_data( 'wp-dbmanager', 'data' ) );
	}

	/**
	 * Messages are escaped at the point of output.
	 */
	public function test_messages_are_escaped() {
		$html = $this->render(
			function () {
				WP_DBManager_Admin::render_messages(
					array(
						array(
							'type' => 'error',
							'text' => '<script>alert(1)</script>',
						),
					)
				);
			}
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * Each message type gets its colour, and an unknown one is not fatal.
	 */
	public function test_message_types_are_coloured() {
		$html = $this->render(
			function () {
				WP_DBManager_Admin::render_messages(
					array(
						array(
							'type' => 'success',
							'text' => 'good',
						),
						array(
							'type' => 'info',
							'text' => 'note',
						),
						array(
							'type' => 'error',
							'text' => 'bad',
						),
						array(
							'type' => 'nonsense',
							'text' => 'odd',
						),
					)
				);
			}
		);

		$this->assertStringContainsString( 'color: green;', $html );
		$this->assertStringContainsString( 'color: blue;', $html );
		$this->assertStringContainsString( 'color: red;', $html );
		$this->assertScreenIsClean( $html );
	}

	/**
	 * Nothing is rendered when there is nothing to say.
	 */
	public function test_no_messages_renders_nothing() {
		$this->assertSame(
			'',
			$this->render(
				function () {
					WP_DBManager_Admin::render_messages( array() );
				}
			)
		);
	}

	/**
	 * A healthy folder produces no notice at all.
	 */
	public function test_a_healthy_folder_is_quiet() {
		file_put_contents( $this->backup_dir . '/index.php', '<?php // Silence is golden.' );
		set_transient( WP_DBManager_Folder::TRANSIENT, 'protected', HOUR_IN_SECONDS );

		$this->assertSame( '', $this->notice() );
	}

	/**
	 * A missing index.php is reported.
	 */
	public function test_a_missing_index_is_reported() {
		set_transient( WP_DBManager_Folder::TRANSIENT, 'protected', HOUR_IN_SECONDS );

		$html = $this->notice();

		$this->assertStringContainsString( 'index.php', $html );
		$this->assertScreenIsClean( $html );
	}

	/**
	 * A reachable folder is reported in the strongest terms available.
	 */
	public function test_a_public_folder_is_reported() {
		file_put_contents( $this->backup_dir . '/index.php', '<?php // Silence is golden.' );
		set_transient( WP_DBManager_Folder::TRANSIENT, 'public', HOUR_IN_SECONDS );

		$html = $this->notice();

		$this->assertStringContainsString( 'visible to the public', $html );
		$this->assertStringContainsString( 'download your entire database', $html );
	}

	/**
	 * The notice can be turned off.
	 */
	public function test_the_notice_can_be_hidden() {
		set_transient( WP_DBManager_Folder::TRANSIENT, 'public', HOUR_IN_SECONDS );

		$options                       = WP_DBManager_Options::get();
		$options['hide_admin_notices'] = 1;
		update_option( WP_DBManager_Options::OPTION, $options );

		$this->assertSame( '', $this->notice() );
	}

	/**
	 * Users who cannot act on the notice do not see it.
	 *
	 * It names the backup folder and links to a screen they cannot reach.
	 */
	public function test_the_notice_is_hidden_from_users_who_cannot_act() {
		set_transient( WP_DBManager_Folder::TRANSIENT, 'public', HOUR_IN_SECONDS );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->notice() );
	}

	/**
	 * The fix link is nonced and points at the backup screen.
	 */
	public function test_the_fix_link_is_nonced() {
		set_transient( WP_DBManager_Folder::TRANSIENT, 'public', HOUR_IN_SECONDS );

		$html = $this->notice();

		$this->assertStringContainsString( 'try_fix=1', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
		$this->assertStringContainsString( 'page=wp-dbmanager-backup', $html );
	}

	/**
	 * The notice never makes an HTTP request of its own.
	 *
	 * It reads the cached verdict only: an unrelated admin page must not wait on
	 * two round trips to the site.
	 */
	public function test_the_notice_does_not_probe() {
		WP_DBManager_Folder::flush();

		$requests = 0;

		add_filter(
			'pre_http_request',
			function () use ( &$requests ) {
				++$requests;
				return new WP_Error( 'no-http-in-tests' );
			}
		);

		$this->notice();

		$this->assertSame( 0, $requests );
	}

	/**
	 * A user with the capability passes the guard.
	 */
	public function test_check_capability_allows_an_administrator() {
		WP_DBManager_Admin::check_capability();

		$this->assertTrue( current_user_can( 'install_plugins' ) );
	}

	/**
	 * A user without it is stopped.
	 */
	public function test_check_capability_stops_everyone_else() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( 'WPDieException' );

		WP_DBManager_Admin::check_capability();
	}
}
