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
class Test_DBManager_Screens extends DBManager_TestCase {

	/**
	 * Every screen's default view renders cleanly.
	 *
	 * @dataProvider data_screens
	 *
	 * @param string $method Screen renderer.
	 * @param string $expect Text the screen must contain.
	 */
	public function test_default_view_is_clean( $method, $expect ) {
		$html = $this->render( array( 'DBManager_Screens', $method ) );

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
		$html = $this->render( array( 'DBManager_Settings', 'render' ) );

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( 'Database Options', $html );
		// Settings API, not a hand-rolled form posting back to itself.
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( 'dbmanager_options[mysqldumppath]', $html );
	}

	/**
	 * A completed save is confirmed on screen.
	 *
	 * options.php stores its "Settings saved." notice in a transient under the
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

		$html = $this->render( array( 'DBManager_Settings', 'render' ), array(), array( 'settings-updated' => 'true' ) );

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( 'Settings saved.', $html );
	}

	/**
	 * A validation error is still shown on the screen that caused it.
	 */
	public function test_a_validation_error_is_shown() {
		add_settings_error( DBManager_Options::OPTION, 'path', 'That backup path is no good.' );

		$html = $this->render( array( 'DBManager_Settings', 'render' ) );

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

		$html = $this->render( array( 'DBManager_Screens', $method ), $post );

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
				'wp-dbmanager_manage',
				array( 'do' => 'Restore' ),
				'No Backup Database File Selected',
			),
			'delete with nothing selected'  => array(
				'manage',
				'wp-dbmanager_manage',
				array( 'do' => 'Delete' ),
				'No Backup Database File Selected',
			),
			'e-mail with nothing selected'  => array(
				'manage',
				'wp-dbmanager_manage',
				array( 'do' => 'E-Mail' ),
				'No Backup Database File Selected',
			),
			'optimize with none selected'   => array(
				'optimize',
				'wp-dbmanager_optimize',
				array( 'do' => 'Optimize' ),
				'No Tables Selected',
			),
			'repair with none selected'     => array(
				'repair',
				'wp-dbmanager_repair',
				array( 'do' => 'Repair' ),
				'No Tables Selected',
			),
			'empty with none selected'      => array(
				'empty_tables',
				'wp-dbmanager_empty',
				array( 'do' => 'Empty/Drop' ),
				'No Tables Selected.',
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
	 * The confirmation prompts survive as data, not as JavaScript source.
	 *
	 * Before 3.0.0 these were built with esc_js() into an inline onclick, and
	 * the \n sequences did not survive the trip: the dialog read "Database.nThis
	 * Action Is Not Reversible."
	 */
	public function test_confirmations_are_data_attributes() {
		$html = $this->render( array( 'DBManager_Screens', 'manage' ) );

		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringContainsString( 'data-dbmanager-confirm="', $html );
		$this->assertStringContainsString( 'Database.\nThis Action Is Not Reversible.', $html );
	}

	/**
	 * The screens link to slugs, not to the plugin's own file paths.
	 *
	 * The legacy "plugin file as menu slug" form put the directory name into
	 * every admin URL, so the plugin only worked under one folder name.
	 */
	public function test_form_targets_carry_no_directory_name() {
		foreach ( array( 'backup', 'manage', 'optimize', 'repair', 'empty_tables', 'run' ) as $method ) {
			$html = $this->render( array( 'DBManager_Screens', $method ) );

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

		$this->render( array( 'DBManager_Screens', 'manager' ) );
	}
}
