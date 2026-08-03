<?php
/**
 * The admin screens.
 *
 * @package WP-DBManager
 */

/**
 * Renders every view each screen can produce.
 *
 * Covering one view per screen is not enough: the interesting markup lives in
 * the branches a bare GET never reaches, so the POST outcomes are in the
 * providers below alongside the default views.
 */
class WP_DBManager_Screens_Test extends WP_DBManager_TestCase {

	/**
	 * Set up.
	 *
	 * The settings screen is sections and fields now, and wp-admin registers
	 * those on admin_init before any page callback runs. Nothing fires admin_init
	 * in a test, so the suite has to stand in for it or do_settings_sections()
	 * would render an empty screen and every assertion below would be about
	 * nothing.
	 */
	public function set_up() {
		parent::set_up();

		WP_DBManager_Settings::register();
	}

	/**
	 * Every screen's default view renders cleanly.
	 *
	 * @dataProvider data_screens
	 *
	 * @param string $method Screen renderer.
	 * @param string $expect Text the screen must contain.
	 */
	public function test_default_view_is_clean( $method, $expect ) {
		$html = $this->render( array( 'WP_DBManager_Screens', $method ) );

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( $expect, $html );
	}

	/**
	 * Screens and a string each one must render.
	 *
	 * @return array
	 */
	public function data_screens() {
		return array(
			'information' => array( 'manager', 'Tables Information' ),
			'backup'      => array( 'backup', 'Checking Backup Status' ),
			'manage'      => array( 'manage', 'Manage Backup Database' ),
			'optimize'    => array( 'optimize', 'Optimize Database' ),
			'repair'      => array( 'repair', 'Repair Database' ),
			'empty'       => array( 'empty_tables', 'Empty/Drop Tables' ),
			'run'         => array( 'run', 'Run SQL Query' ),
		);
	}

	/**
	 * The settings screen renders cleanly too.
	 */
	public function test_settings_screen_is_clean() {
		$html = $this->render( array( 'WP_DBManager_Settings', 'render' ) );

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( 'Database Settings', $html );
		// Settings API, not a hand-rolled form posting back to itself.
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( 'wp_dbmanager_options[mysqldumppath]', $html );
	}

	/**
	 * A completed save is confirmed on screen.
	 *
	 * Core stores its "Settings saved." notice in a transient under the
	 * 'general' slug, not under the option being saved, so a settings_errors()
	 * call scoped to the option name renders validation errors and drops the
	 * confirmation. The screen then saves correctly and tells the user nothing,
	 * which is indistinguishable from having done nothing.
	 */
	public function test_a_completed_save_is_confirmed() {
		set_transient(
			'settings_errors',
			array(
				array(
					'setting' => 'general',
					'code'    => 'settings_updated',
					'message' => 'Settings saved.',
					'type'    => 'success',
				),
			),
			30
		);

		$html = $this->render( array( 'WP_DBManager_Settings', 'render' ), array(), array( 'settings-updated' => 'true' ) );

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( 'Settings saved.', $html );
	}

	/**
	 * A validation error is still shown on the screen that caused it.
	 */
	public function test_a_validation_error_is_shown() {
		add_settings_error( WP_DBManager_Options::OPTION, 'path', 'That backup path is no good.' );

		$html = $this->render( array( 'WP_DBManager_Settings', 'render' ) );

		$this->assertStringContainsString( 'That backup path is no good.', $html );
	}

	/**
	 * Every POST branch renders cleanly and says what it did.
	 *
	 * @dataProvider data_post_views
	 *
	 * @param string $method Screen renderer.
	 * @param string $nonce  Nonce action for the screen.
	 * @param array  $post   Request body, minus the nonce.
	 * @param string $expect Text the outcome must contain.
	 */
	public function test_post_view_is_clean( $method, $nonce, $post, $expect ) {
		$post['_wpnonce'] = wp_create_nonce( $nonce );

		$html = $this->render( array( 'WP_DBManager_Screens', $method ), $post );

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( $expect, $html );
	}

	/**
	 * POST branches worth rendering.
	 *
	 * @return array
	 */
	public function data_post_views() {
		return array(
			'restore with nothing selected' => array(
				'manage',
				WP_DBManager_Backups_Table::nonce_action(),
				array( 'action' => 'restore' ),
				'No Backup Database File Selected',
			),
			'delete with nothing selected'  => array(
				'manage',
				WP_DBManager_Backups_Table::nonce_action(),
				array( 'action' => 'delete' ),
				'No Backup Database File Selected',
			),
			'e-mail with nothing selected'  => array(
				'manage',
				WP_DBManager_Backups_Table::nonce_action(),
				array( 'action' => 'email' ),
				'No Backup Database File Selected',
			),
			'optimize with none selected'   => array(
				'optimize',
				WP_DBManager_Tables_Table::nonce_action(),
				array( 'action' => 'optimize' ),
				'No Tables Selected',
			),
			'repair with none selected'     => array(
				'repair',
				WP_DBManager_Tables_Table::nonce_action(),
				array( 'action' => 'repair' ),
				'No Tables Selected',
			),
			'empty with none selected'      => array(
				'empty_tables',
				WP_DBManager_Tables_Table::nonce_action(),
				array( 'action' => 'empty' ),
				'No Tables Selected',
			),
			'drop with none selected'       => array(
				'empty_tables',
				WP_DBManager_Tables_Table::nonce_action(),
				array( 'action' => 'drop' ),
				'No Tables Selected',
			),
			'an empty query'                => array(
				'run',
				'wp-dbmanager_run',
				array(
					'do'        => 'Run',
					'sql_query' => '',
				),
				'Empty Query',
			),
			'a refused statement'           => array(
				'run',
				'wp-dbmanager_run',
				array(
					'do'        => 'Run',
					'sql_query' => 'DROP TABLE wp_posts',
				),
				'0/1',
			),
		);
	}

	/**
	 * The manage screen lists the backups that are there.
	 */
	public function test_the_manage_screen_lists_backups() {
		$name = str_repeat( 'c', 32 ) . '_-_1700000000_-_sitedb.sql';
		self::write_file( $this->backup_dir . '/' . $name, 'SOME SQL' );

		$html = $this->render( array( 'WP_DBManager_Screens', 'manage' ) );

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( str_repeat( 'c', 32 ), $html, 'The checksum column is empty.' );
		$this->assertStringContainsString( 'sitedb.sql', $html );
		$this->assertStringContainsString( '1 backup file', $html );
		$this->assertStringNotContainsString( 'There Are No Database Backup Files Available.', $html );
	}

	/**
	 * An empty folder says so rather than rendering a bare table.
	 */
	public function test_the_manage_screen_reports_an_empty_folder() {
		$html = $this->render( array( 'WP_DBManager_Screens', 'manage' ) );

		$this->assertStringContainsString( 'There Are No Database Backup Files Available.', $html );
	}

	/**
	 * Deleting a selected backup removes it and says so.
	 */
	public function test_deleting_a_backup_removes_the_file() {
		$name = str_repeat( 'd', 32 ) . '_-_1700000000_-_sitedb.sql';
		$path = $this->backup_dir . '/' . $name;
		self::write_file( $path, 'SOME SQL' );

		$html = $this->render(
			array( 'WP_DBManager_Screens', 'manage' ),
			array(
				'action'   => 'delete',
				'backups'  => array( $name ),
				'_wpnonce' => wp_create_nonce( WP_DBManager_Backups_Table::nonce_action() ),
			)
		);

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( 'Deleted Successfully', $html );
		$this->assertFileDoesNotExist( $path, 'Deleting a backup removes the file, not only the row on screen.' );
	}

	/**
	 * Deleting something that is not there reports rather than pretending.
	 */
	public function test_deleting_a_missing_backup_reports_it() {
		$html = $this->render(
			array( 'WP_DBManager_Screens', 'manage' ),
			array(
				'action'   => 'delete',
				'backups'  => array( 'nothing_-_1700000000_-_sitedb.sql' ),
				'_wpnonce' => wp_create_nonce( WP_DBManager_Backups_Table::nonce_action() ),
			)
		);

		$this->assertStringContainsString( 'Invalid Database Backup File', $html );
	}

	/**
	 * A download the init handler refused is explained here.
	 *
	 * WP_DBManager_Backups::maybe_download() answers on init, where there is no
	 * screen to print to, so a name it cannot resolve is handed back and this is
	 * the only place the administrator hears about it. It used to exit instead,
	 * which returned a 200 with an empty body and made this branch unreachable.
	 */
	public function test_a_refused_download_is_explained_on_the_screen() {
		$html = $this->render(
			array( 'WP_DBManager_Screens', 'manage' ),
			array(
				'action'   => 'download',
				'backups'  => array( '../../../wp-config.php' ),
				'_wpnonce' => wp_create_nonce( WP_DBManager_Backups_Table::nonce_action() ),
			)
		);

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( 'Invalid Database Backup File', $html, 'A refused download said nothing at all.' );
		$this->assertStringNotContainsString( 'DB_PASSWORD', $html, 'The screen printed something it read from outside the backup folder.' );
	}

	/**
	 * E-mailing a selected backup reports where it went.
	 */
	public function test_emailing_a_backup_reports_the_recipient() {
		add_filter( 'pre_wp_mail', '__return_true' );

		$name = str_repeat( 'e', 32 ) . '_-_1700000000_-_sitedb.sql';
		self::write_file( $this->backup_dir . '/' . $name, 'SOME SQL' );

		$html = $this->render(
			array( 'WP_DBManager_Screens', 'manage' ),
			array(
				'action'   => 'email',
				'backups'  => array( $name ),
				'email_to' => 'someone@example.com',
				'_wpnonce' => wp_create_nonce( WP_DBManager_Backups_Table::nonce_action() ),
			)
		);

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( 'someone@example.com', $html );
		$this->assertStringContainsString( 'Successfully E-Mailed', $html );
	}

	/**
	 * A submission without a valid nonce is refused.
	 *
	 * These screens drop tables and restore databases, so this matters.
	 *
	 * @dataProvider data_nonce_screens
	 *
	 * @param string $method Screen renderer.
	 * @param array  $post   Request body carrying a wrong nonce.
	 */
	public function test_a_bad_nonce_is_refused( $method, $post ) {
		$post['_wpnonce'] = 'not-a-real-nonce';

		$this->expectException( 'WPDieException' );

		$this->render( array( 'WP_DBManager_Screens', $method ), $post );
	}

	/**
	 * Screens whose POST handler must be nonce-protected.
	 *
	 * @return array
	 */
	public function data_nonce_screens() {
		return array(
			'manage'   => array( 'manage', array( 'action' => 'delete' ) ),
			'optimize' => array( 'optimize', array( 'action' => 'optimize' ) ),
			'repair'   => array( 'repair', array( 'action' => 'repair' ) ),
			'empty'    => array( 'empty_tables', array( 'action' => 'empty' ) ),
			'drop'     => array( 'empty_tables', array( 'action' => 'drop' ) ),
			'run'      => array( 'run', array( 'do' => 'Run' ) ),
			'backup'   => array( 'backup', array( 'do' => 'Backup' ) ),
		);
	}

	/**
	 * The information screen lists the tables and totals them.
	 */
	public function test_the_information_screen_totals_the_tables() {
		global $wpdb;

		$html = $this->render( array( 'WP_DBManager_Screens', 'manager' ) );

		$this->assertStringContainsString( $wpdb->posts, $html );
		$this->assertMatchesRegularExpression( '/[0-9,]+ Tables?/', $html, 'The information screen totals its table count.' );
		$this->assertMatchesRegularExpression( '/[0-9,]+ Records?/', $html, 'The information screen totals its record count.' );
		$this->assertStringContainsString( DB_NAME, $html );
	}

	/**
	 * The confirmation prompts survive as data, not as JavaScript source.
	 *
	 * Before 3.0.0 these were built with esc_js() into an inline onclick, and
	 * the \n sequences did not survive the trip: the dialog read "Database.nThis
	 * Action Is Not Reversible."
	 */
	public function test_confirmations_are_data_attributes() {
		$html = $this->render( array( 'WP_DBManager_Screens', 'manage' ) );

		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringContainsString( 'data-dbmanager-confirm-actions="', $html );

		// The attribute is a JSON map of bulk action to message, so the script
		// can confirm whichever action the dropdown happens to be on. Assert on
		// the decoded value rather than the raw markup: JSON encoding doubles
		// the backslash, so the attribute itself reads "\\n".
		preg_match( '/data-dbmanager-confirm-actions="([^"]+)"/', $html, $m );
		$messages = json_decode( html_entity_decode( $m[1] ), true );

		$this->assertIsArray( $messages, 'The confirmations are an array the screen can render as attributes.' );
		$this->assertArrayHasKey( 'restore', $messages, 'A restore carries its own confirmation message.' );
		$this->assertArrayHasKey( 'delete', $messages, 'A delete carries its own confirmation message.' );

		// The \n survives as two characters, which the script turns into a real
		// line break. Before 3.0.0 esc_js() ate it, so the dialog showed an n
		// where the line break belonged.
		$this->assertStringContainsString( 'Database.\nThis Action Is Not Reversible.', $messages['restore'] );
	}

	/**
	 * The screens link to slugs, not to the plugin's own file paths.
	 *
	 * The legacy "plugin file as menu slug" form put the directory name into
	 * every admin URL, so the plugin only worked under one folder name.
	 */
	public function test_form_targets_carry_no_directory_name() {
		foreach ( array( 'backup', 'manage', 'optimize', 'repair', 'empty_tables', 'run' ) as $method ) {
			$html = $this->render( array( 'WP_DBManager_Screens', $method ) );

			$this->assertStringNotContainsString( 'wp-dbmanager/database-', $html, $method );
			$this->assertStringNotContainsString( '.php&', $html, $method );
		}
	}

	/**
	 * A user without the capability gets nothing.
	 */
	public function test_screens_require_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( 'WPDieException' );

		$this->render( array( 'WP_DBManager_Screens', 'manager' ) );
	}
}
