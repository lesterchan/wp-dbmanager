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
class WP_DBManager_Database_Test extends WP_DBManager_TestCase {

	/**
	 * The password never appears on the command line.
	 *
	 * It goes into a 0600 option file instead, because anything on the command
	 * line is visible to every other user on the host through `ps`.
	 */
	public function test_password_is_not_in_the_command() {
		$defaults_file = WP_DBManager_Database::write_defaults_file();

		$this->assertNotFalse( $defaults_file, 'No option file could be written.' );

		$command = WP_DBManager_Database::dump_command( $this->backup_dir . '/out.sql', false, $defaults_file );

		$this->assertStringContainsString( '--defaults-extra-file=', $command, 'The credentials go in a defaults file.' );
		$this->assertStringNotContainsString( '--password=', $command, 'So the command line carries no password flag.' );

		if ( '' !== DB_PASSWORD ) {
			$this->assertStringNotContainsString( DB_PASSWORD, $command, 'And the password itself never appears in the process list.' );
		}

		WP_DBManager_Database::delete_defaults_file( $defaults_file );
	}

	/**
	 * The option file is written unreadable to anyone else, then cleaned up.
	 */
	public function test_defaults_file_is_private_and_removed() {
		$defaults_file = WP_DBManager_Database::write_defaults_file();

		$this->assertFileExists( $defaults_file, 'The defaults file is written, or the removal below proves nothing.' );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $defaults_file ) ), -4 ), 'The defaults file is readable only by its owner.' );
		$this->assertStringContainsString( '[client]', file_get_contents( $defaults_file ), 'And is a MySQL option file, which is what the flag expects.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		WP_DBManager_Database::delete_defaults_file( $defaults_file );

		$this->assertFileDoesNotExist( $defaults_file, 'The defaults file carrying the password is removed after use.' );
	}

	/**
	 * With no option file the command falls back to --password.
	 */
	public function test_credential_args_fall_back_to_the_command_line() {
		$this->assertStringContainsString( '--defaults-extra-file=', WP_DBManager_Database::credential_args( '/tmp/x' ), 'Given a defaults file, that is what is passed.' );
		$this->assertStringContainsString( '--password=', WP_DBManager_Database::credential_args( false ), 'Without one it falls back to the command line rather than connecting anonymously.' );
	}

	/**
	 * Values in the option file are quoted and their backslashes doubled.
	 */
	public function test_option_file_values_are_escaped() {
		$this->assertSame( '"plain"', WP_DBManager_Database::escape_option_file_value( 'plain' ), 'A plain value is quoted.' );
		$this->assertSame( '"a\\\\b"', WP_DBManager_Database::escape_option_file_value( 'a\\b' ), 'A backslash is doubled.' );
		$this->assertSame( '"a\\"b"', WP_DBManager_Database::escape_option_file_value( 'a"b' ), 'And a quote is escaped, so it cannot close the value early.' );
	}

	/**
	 * The gzip branch pipes, the plain branch redirects.
	 */
	public function test_dump_command_branches() {
		$plain = WP_DBManager_Database::dump_command( $this->backup_dir . '/out.sql', false, false );
		$gzip  = WP_DBManager_Database::dump_command( $this->backup_dir . '/out.sql.gz', true, false );

		$this->assertStringNotContainsString( '| gzip', $plain, 'A plain dump is not piped through gzip.' );
		$this->assertStringContainsString( '| gzip >', $gzip, 'While a compressed one is.' );

		foreach ( array( $plain, $gzip ) as $command ) {
			$this->assertStringContainsString( '--add-drop-table', $command, 'The dump command drops tables before recreating them.' );
			$this->assertStringContainsString( '--skip-lock-tables', $command, 'The dump command skips table locks, which a shared host may refuse.' );
			$this->assertStringContainsString( 'utf8mb4', $command, 'The dump command names the character set rather than relying on the server default.' );
		}
	}

	/**
	 * A gzipped backup is decompressed into the client, not read by it.
	 */
	public function test_restore_command_branches() {
		$plain = WP_DBManager_Database::restore_command( $this->backup_dir . '/in.sql', false );
		$gzip  = WP_DBManager_Database::restore_command( $this->backup_dir . '/in.sql.gz', false );

		$this->assertStringStartsWith( 'gunzip <', $gzip, 'A compressed restore is decompressed first.' );
		$this->assertStringNotContainsString( 'gunzip', $plain, 'A plain one is not.' );
		$this->assertStringContainsString( ' < ', $plain, 'It is redirected in as it stands.' );
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

		WP_DBManager_Database::dump_command( $this->backup_dir . '/out.sql', false, false );
		WP_DBManager_Database::restore_command( $this->backup_dir . '/in.sql', false );

		$this->assertSame( 2, $fired, 'The action fires for each command, so a filter can see both.' );
	}

	/**
	 * A host with a port is split into --port, a socket into --socket.
	 */
	public function test_connection_args_are_derived_from_db_host() {
		$args = WP_DBManager_Database::connection_args();

		$this->assertArrayHasKey( 'host', $args, 'The connection arguments carry the host.' );
		$this->assertArrayHasKey( 'port', $args, 'The connection arguments carry the port.' );
		$this->assertArrayHasKey( 'sock', $args, 'The connection arguments carry the socket.' );

		if ( false !== strpos( DB_HOST, ':' ) ) {
			$parts = explode( ':', DB_HOST );

			$this->assertSame( $parts[0], $args['host'], 'The host is the part of DB_HOST before the separator.' );
			$this->assertNotSame( '', $args['port'] . $args['sock'], 'And the part after it becomes a port or a socket.' );
		} else {
			$this->assertSame( DB_HOST, $args['host'], 'A plain host is the host.' );
			$this->assertSame( '', $args['port'], 'With no port to derive.' );
			$this->assertSame( '', $args['sock'], 'And no socket.' );
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
		$this->assertSame( $expected, WP_DBManager_Database::is_valid_path( $path ), 'A path is only valid when it names a binary that can be run.' );
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
		$options                  = WP_DBManager_Options::get();
		$options['path']          = '/no/such/place';
		$options['mysqldumppath'] = 'mysqldump; id';
		$options['mysqlpath']     = 'mysql | id';
		update_option( WP_DBManager_Options::OPTION, $options );

		$this->assertCount( 3, WP_DBManager_Database::path_errors(), 'Each path problem is reported separately rather than as one message.' );
	}

	/**
	 * A healthy configuration reports nothing.
	 */
	public function test_path_errors_is_empty_when_everything_is_fine() {
		$this->assertSame( array(), WP_DBManager_Database::path_errors(), 'With everything in place there is nothing to report.' );
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

		$result = WP_DBManager_Database::backup( false );

		$this->assertTrue( $result['success'], 'The backup reported failure.' );
		$this->assertFileExists( $result['filepath'], 'The backup wrote the file it reported.' );
		$this->assertGreaterThan( 0, filesize( $result['filepath'] ), 'The dump has content rather than being an empty file.' );

		// The name carries the file's own md5, so it cannot be guessed.
		$this->assertSame(
			md5_file( $result['filepath'] ),
			substr( basename( $result['filepath'] ), 0, 32 ),
			'The checksum in the name is the checksum of the file.'
		);

		$this->assertStringContainsString( 'CREATE TABLE', file_get_contents( $result['filepath'] ), 'And the file is a dump rather than an empty one.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
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

		$result = WP_DBManager_Database::backup( true );

		$this->assertTrue( $result['success'], 'A gzip backup reports success.' );
		$this->assertStringEndsWith( '.sql.gz', $result['filepath'], 'A compressed backup is named as one.' );

		// The gzip magic number.
		$handle = fopen( $result['filepath'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$magic  = fread( $handle, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$this->assertSame( "\x1f\x8b", $magic, 'And really is gzip, rather than a plain dump under a gz name.' );
	}

	/**
	 * A dump that fails leaves no file behind pretending to be a backup.
	 *
	 * An empty file is worse than no file: it looks like a backup right up
	 * until somebody needs it.
	 */
	public function test_a_failed_dump_leaves_nothing_behind() {
		$options                  = WP_DBManager_Options::get();
		$options['mysqldumppath'] = '/nonexistent/mysqldump';
		update_option( WP_DBManager_Options::OPTION, $options );

		$result = WP_DBManager_Database::backup( false );

		$this->assertFalse( $result['success'], 'A failed dump reports failure rather than a false success.' );
		$this->assertSame( '', $result['filepath'], 'A failed dump reports no path.' );
		$this->assertSame( array(), WP_DBManager_Backups::all( $this->backup_dir ), 'And leaves no half written file behind.' );
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
		$options                  = WP_DBManager_Options::get();
		$options['mysqldumppath'] = '/nonexistent/mysqldump';
		update_option( WP_DBManager_Options::OPTION, $options );

		$result = WP_DBManager_Database::backup( true );

		$this->assertFalse( $result['success'], 'An empty gzip stream was accepted as a backup.' );
		$this->assertSame( 'empty', $result['reason'], 'A gzip dump that produced nothing is reported as empty.' );
		$this->assertSame( array(), WP_DBManager_Backups::all( $this->backup_dir ), 'And leaves no half written file behind.' );
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

		$result = WP_DBManager_Database::backup( true );

		$this->assertTrue( $result['success'], 'A real gzip dump is accepted.' );
		$this->assertStringContainsString( 'CREATE TABLE', (string) gzdecode( file_get_contents( $result['filepath'] ) ), 'A real gzip dump decompresses to the dump it wrapped.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	}

	/**
	 * Detection returns both binaries without throwing.
	 */
	public function test_detect_binaries_returns_both_keys() {
		$paths = WP_DBManager_Database::detect_binaries();

		$this->assertArrayHasKey( 'mysql', $paths, 'Detection answers for the mysql binary.' );
		$this->assertArrayHasKey( 'mysqldump', $paths, 'Detection answers for the mysqldump binary.' );
		$this->assertIsString( $paths['mysql'], 'The mysql path is a string even when nothing was found.' );
		$this->assertIsString( $paths['mysqldump'], 'The mysqldump path is a string even when nothing was found.' );
	}

	/**
	 * A function that exists is not reported as disabled.
	 */
	public function test_is_function_disabled() {
		$this->assertFalse( WP_DBManager_Database::is_function_disabled( 'strlen' ), 'A function that is not disabled reads as available.' );

		$disabled = array_filter( array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ) );

		if ( empty( $disabled ) ) {
			$this->assertFalse( WP_DBManager_Database::is_function_disabled( 'passthru' ), 'A function absent from disable_functions reads as available.' );
			return;
		}

		$this->assertTrue( WP_DBManager_Database::is_function_disabled( reset( $disabled ) ), 'A function named in disable_functions reads as disabled.' );
	}

	/**
	 * An empty gzip stream is recognised as containing nothing.
	 *
	 * Gzip produces exactly this from a mysqldump that wrote nothing, and it is
	 * a valid, non-empty file - which is how it used to get through.
	 */
	public function test_an_empty_gzip_stream_has_no_content() {
		$path = $this->backup_dir . '/empty.sql.gz';
		self::write_file( $path, gzencode( '' ) );

		$this->assertGreaterThan( 0, filesize( $path ), 'An empty gzip stream is still a non-empty file.' );

		$method = new ReflectionMethod( 'WP_DBManager_Database', 'dump_has_content' );

		$this->assertFalse( $method->invoke( null, $path, true ), 'An empty gzip stream is judged to have no content.' );
	}

	/**
	 * A gzip stream with SQL in it is recognised as real.
	 */
	public function test_a_real_gzip_stream_has_content() {
		$path = $this->backup_dir . '/real.sql.gz';
		self::write_file( $path, gzencode( "-- MariaDB dump\nCREATE TABLE x (id INT);\n" ) );

		$method = new ReflectionMethod( 'WP_DBManager_Database', 'dump_has_content' );

		$this->assertTrue( $method->invoke( null, $path, true ), 'A real gzip stream is judged to have content.' );
	}

	/**
	 * Whitespace alone does not count as a dump.
	 */
	public function test_a_whitespace_only_dump_has_no_content() {
		$path = $this->backup_dir . '/blank.sql.gz';
		self::write_file( $path, gzencode( "\n\n   \n" ) );

		$method = new ReflectionMethod( 'WP_DBManager_Database', 'dump_has_content' );

		$this->assertFalse( $method->invoke( null, $path, true ), 'A whitespace-only dump is judged to have no content.' );
	}

	/**
	 * The uncompressed case still goes on size, and a missing file is not one.
	 */
	public function test_plain_dump_content_is_judged_on_size() {
		$method = new ReflectionMethod( 'WP_DBManager_Database', 'dump_has_content' );

		$empty = $this->backup_dir . '/empty.sql';
		self::write_file( $empty, '' );
		$this->assertFalse( $method->invoke( null, $empty, false ), 'An empty plain dump is judged to have no content.' );

		$real = $this->backup_dir . '/real.sql';
		self::write_file( $real, 'CREATE TABLE x (id INT);' );
		$this->assertTrue( $method->invoke( null, $real, false ), 'A real plain dump is judged to have content.' );

		$this->assertFalse( $method->invoke( null, $this->backup_dir . '/missing.sql', false ), 'A dump that is not there is judged to have no content rather than warning.' );
	}

	/**
	 * Nothing is executed when a configured path is unusable.
	 */
	public function test_execute_refuses_an_invalid_configuration() {
		$options                  = WP_DBManager_Options::get();
		$options['mysqldumppath'] = 'mysqldump; id';
		update_option( WP_DBManager_Options::OPTION, $options );

		$result = WP_DBManager_Database::execute( 'true' );

		$this->assertIsString( $result, 'A path error should come back as a message, not an exit code.' );
		$this->assertStringContainsString( 'not a valid mysqldump path', $result, 'An invalid configuration is refused with the reason, rather than run.' );
	}

	/**
	 * A restore against an unusable path reports rather than pretending.
	 */
	public function test_restore_refuses_an_invalid_configuration() {
		$options              = WP_DBManager_Options::get();
		$options['mysqlpath'] = 'mysql; id';
		update_option( WP_DBManager_Options::OPTION, $options );

		$result = WP_DBManager_Database::restore( 'whatever.sql' );

		$this->assertFalse( $result['ran'], 'Restore refuses an invalid configuration rather than running it.' );
		$this->assertNotEmpty( $result['errors'], 'The refusal comes with a reason.' );
	}
}
