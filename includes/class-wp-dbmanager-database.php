<?php
/**
 * Talking to the mysql and mysqldump binaries.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Locates the client binaries and builds the dump and restore commands.
 *
 * Before 3.0.0 the connection arguments and the mysqldump invocation were
 * written out three separate times - once in the cron callback, once on the
 * Backup screen and once, in mirror image, on the Manage screen for restores.
 * They had already drifted: only two of the three checked whether the dump had
 * actually produced a file. Everything that shells out now goes through here.
 *
 * @since 3.0.0
 */
class WP_DBManager_Database {

	/**
	 * Character set passed to both binaries.
	 */
	const CHARSET = ' --default-character-set="utf8mb4"';

	/**
	 * Attempt to locate the mysql and mysqldump binaries.
	 *
	 * @return array Paths keyed by mysql and mysqldump.
	 */
	public static function detect_binaries() {
		global $wpdb;

		$paths = array(
			'mysql'     => '',
			'mysqldump' => '',
		);

		if ( substr( PHP_OS, 0, 3 ) === 'WIN' ) {
			$mysql_install = $wpdb->get_row( "SHOW VARIABLES LIKE 'basedir'" );

			if ( $mysql_install ) {
				$install_path       = trailingslashit( str_replace( '\\', '/', $mysql_install->Value ) );
				$paths['mysql']     = $install_path . 'bin/mysql.exe';
				$paths['mysqldump'] = $install_path . 'bin/mysqldump.exe';
			} else {
				$paths['mysql']     = 'mysql.exe';
				$paths['mysqldump'] = 'mysqldump.exe';
			}
		} elseif ( function_exists( 'exec' ) && ! self::is_function_disabled( 'exec' ) ) {
			$paths['mysql']     = exec( 'which mysql' );
			$paths['mysqldump'] = exec( 'which mysqldump' );
		} else {
			$paths['mysql']     = 'mysql';
			$paths['mysqldump'] = 'mysqldump';
		}

		return $paths;
	}

	/**
	 * Whether a PHP function has been disabled in the configuration.
	 *
	 * @param string $function_name Function to check.
	 * @return bool
	 */
	public static function is_function_disabled( $function_name ) {
		return in_array( $function_name, array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );
	}

	/**
	 * Check a path for characters that are not allowed.
	 *
	 * @param string $path Path to check.
	 * @return int 1 when the path is acceptable, 0 when it is not.
	 */
	public static function is_valid_path( $path ) {
		return preg_match( '/^[^*?"<>|;]*$/', $path );
	}

	/**
	 * Host, port and socket arguments derived from DB_HOST.
	 *
	 * @return array Keys host, port and sock. Port and sock are ready to
	 *               concatenate and are empty when they do not apply.
	 */
	public static function connection_args() {
		$args = array(
			'host' => DB_HOST,
			'port' => '',
			'sock' => '',
		);

		if ( strpos( DB_HOST, ':' ) !== false ) {
			$db_host      = explode( ':', DB_HOST );
			$args['host'] = $db_host[0];

			if ( 0 !== (int) $db_host[1] ) {
				$args['port'] = ' --port=' . escapeshellarg( (int) $db_host[1] );
			} else {
				$args['sock'] = ' --socket=' . escapeshellarg( $db_host[1] );
			}
		}

		return $args;
	}

	/**
	 * Escape a value for a MySQL option file.
	 *
	 * @param string $value Raw value.
	 * @return string Quoted and escaped value.
	 */
	public static function escape_option_file_value( $value ) {
		// Backslash is an escape character inside option files, so it has to be
		// doubled before the value is wrapped in quotes.
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( '"', '\\"', $value );

		return '"' . $value . '"';
	}

	/**
	 * Write the database password to a temporary option file.
	 *
	 * Keeps the password off the command line, where `ps` would expose it to
	 * every other user on the host.
	 *
	 * @return string|false Path to the file, or false when it could not be written.
	 */
	public static function write_defaults_file() {
		$temp_dir = get_temp_dir();

		if ( ! is_dir( $temp_dir ) || ! wp_is_writable( $temp_dir ) ) {
			return false;
		}

		$defaults_file = @tempnam( $temp_dir, 'wp-dbmanager-' );

		if ( false === $defaults_file ) {
			return false;
		}

		// Readable only by the user running PHP, before anything is written to it.
		@chmod( $defaults_file, 0600 );

		$contents = "[client]\npassword=" . self::escape_option_file_value( DB_PASSWORD ) . "\n";

		if ( @file_put_contents( $defaults_file, $contents ) === false ) {
			wp_delete_file( $defaults_file );
			return false;
		}

		return $defaults_file;
	}

	/**
	 * Build the credential argument for a mysql or mysqldump command.
	 *
	 * Must be placed immediately after the binary: --defaults-extra-file has to
	 * come first or the client ignores it.
	 *
	 * @param string|false $defaults_file Path to the option file, or false to fall back to --password.
	 * @return string
	 */
	public static function credential_args( $defaults_file ) {
		if ( false !== $defaults_file ) {
			return ' --defaults-extra-file=' . escapeshellarg( $defaults_file );
		}

		// No option file could be written, fall back to the old command line password.
		return ' --password=' . escapeshellarg( DB_PASSWORD );
	}

	/**
	 * Remove the temporary option file.
	 *
	 * @param string|false $defaults_file Path to the file, or false when none was written.
	 * @return void
	 */
	public static function delete_defaults_file( $defaults_file ) {
		if ( false !== $defaults_file && is_file( $defaults_file ) ) {
			wp_delete_file( $defaults_file );
		}
	}

	/**
	 * Build a mysqldump command.
	 *
	 * @param string       $filepath      Where the dump should be written.
	 * @param bool         $gzip          Whether to pipe through gzip.
	 * @param string|false $defaults_file Option file holding the password.
	 * @return string
	 */
	public static function dump_command( $filepath, $gzip, $defaults_file ) {
		$options = WP_DBManager_Options::get();
		$args    = self::connection_args();

		/**
		 * Fires immediately before a shell command is assembled.
		 *
		 * Kept from 2.76, where it existed so a site could adjust the
		 * environment (locale, PATH) that the command inherits.
		 *
		 * @since 2.76
		 */
		do_action( 'wp_dbmanager_before_escapeshellcmd' );

		$command = escapeshellarg( $options['mysqldumppath'] )
			. self::credential_args( $defaults_file )
			. ' --force --host=' . escapeshellarg( $args['host'] )
			. ' --user=' . escapeshellarg( DB_USER )
			. $args['port'] . $args['sock'] . self::CHARSET
			. ' --add-drop-table --skip-lock-tables '
			. escapeshellarg( DB_NAME );

		if ( $gzip ) {
			return $command . ' | gzip > ' . escapeshellarg( $filepath );
		}

		return $command . ' > ' . escapeshellarg( $filepath );
	}

	/**
	 * Build a mysql restore command.
	 *
	 * @param string       $filepath      Backup file to feed in.
	 * @param string|false $defaults_file Option file holding the password.
	 * @return string
	 */
	public static function restore_command( $filepath, $defaults_file ) {
		$options = WP_DBManager_Options::get();
		$args    = self::connection_args();

		/** This action is documented in includes/class-wp-dbmanager-database.php */
		do_action( 'wp_dbmanager_before_escapeshellcmd' );

		$command = escapeshellarg( $options['mysqlpath'] )
			. self::credential_args( $defaults_file )
			. ' --host=' . escapeshellarg( $args['host'] )
			. ' --user=' . escapeshellarg( DB_USER )
			. $args['port'] . $args['sock'] . self::CHARSET
			. ' ' . escapeshellarg( DB_NAME );

		// gunzip streams into the client rather than the client reading the file,
		// so the redirection moves to the front of the pipeline.
		if ( false !== stripos( $filepath, '.gz' ) ) {
			return 'gunzip < ' . escapeshellarg( $filepath ) . ' | ' . $command;
		}

		return $command . ' < ' . escapeshellarg( $filepath );
	}

	/**
	 * Validate the three configured paths.
	 *
	 * Each is checked on its own: a failure on one must not skip the checks on
	 * the others, or the user fixes one path per save.
	 *
	 * @return array List of human readable errors, empty when all three are usable.
	 */
	public static function path_errors() {
		$options = WP_DBManager_Options::get();
		$errors  = array();

		if ( realpath( $options['path'] ) === false ) {
			/* translators: %s: configured backup folder path. */
			$errors[] = sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), $options['path'] );
		}

		if ( self::is_valid_path( $options['mysqldumppath'] ) === 0 ) {
			/* translators: %s: configured mysqldump path. */
			$errors[] = sprintf( __( '%s is not a valid mysqldump path', 'wp-dbmanager' ), $options['mysqldumppath'] );
		}

		if ( self::is_valid_path( $options['mysqlpath'] ) === 0 ) {
			/* translators: %s: configured mysql path. */
			$errors[] = sprintf( __( '%s is not a valid mysql path', 'wp-dbmanager' ), $options['mysqlpath'] );
		}

		return $errors;
	}

	/**
	 * Execute a shell command for the current platform.
	 *
	 * Executes OS-Dependent mysqldump Command (By: Vlad Sharanhovich).
	 *
	 * @param string $command Shell command to run.
	 * @return int|string Exit code, or an error message when a configured path is invalid.
	 */
	public static function execute( $command ) {
		$options = WP_DBManager_Options::get();

		WP_DBManager_Backups::prune();

		$errors = self::path_errors();

		if ( ! empty( $errors ) ) {
			return $errors[0];
		}

		$error = 0;

		if ( substr( PHP_OS, 0, 3 ) === 'WIN' ) {
			// cmd.exe cannot be handed a pipeline on stdin the way sh can, so the
			// command goes into a batch file inside the backup folder and that is
			// what gets run.
			$tmpnam = $options['path'] . '/wp-dbmanager.bat';
			$handle = fopen( $tmpnam, 'w' );

			if ( false === $handle ) {
				return __( 'Unable to write the temporary batch file', 'wp-dbmanager' );
			}

			fwrite( $handle, $command );
			fclose( $handle );
			system( $tmpnam . ' > NUL', $error );
			wp_delete_file( $tmpnam );
		} else {
			passthru( $command, $error );
		}

		return $error;
	}

	/**
	 * Whether a finished dump actually contains anything.
	 *
	 * Size is not a good enough test once gzip is involved, and neither is the
	 * command's exit status. `mysqldump ... | gzip > file` reports the status of
	 * the *last* command in the pipeline, and gzip is perfectly happy to turn
	 * empty input into a valid twenty byte stream - so a mysqldump that failed
	 * outright produced a non-empty file and an exit status of zero, and the
	 * result was renamed with a checksum and e-mailed out as a backup.
	 *
	 * POSIX sh has no pipefail, so the pipeline cannot be made to report the
	 * failure. Reading the dump back is what settles it.
	 *
	 * @param string $filepath Finished dump.
	 * @param bool   $gzip     Whether it is compressed.
	 * @return bool
	 */
	protected static function dump_has_content( $filepath, $gzip ) {
		if ( ! is_file( $filepath ) ) {
			return false;
		}

		if ( ! $gzip ) {
			return filesize( $filepath ) > 0;
		}

		// zlib ships with essentially every WordPress-capable PHP, but fall back
		// to a size floor rather than accepting anything if it is missing. An
		// empty gzip stream is twenty bytes.
		if ( ! function_exists( 'gzopen' ) ) {
			return filesize( $filepath ) > 64;
		}

		$handle = gzopen( $filepath, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		$head = gzread( $handle, 1024 );
		gzclose( $handle );

		return is_string( $head ) && '' !== trim( $head );
	}

	/**
	 * Take a backup.
	 *
	 * Writes the dump, checks that it is real, then renames it to carry its own
	 * md5 so the file name cannot be guessed.
	 *
	 * @param bool $gzip Whether to gzip the dump.
	 * @return array {
	 *     @type bool   $success  Whether a usable dump was produced.
	 *     @type string $filepath Full path to the finished file, empty on failure.
	 *     @type string $reason   One of path, empty, missing or command when unsuccessful.
	 * }
	 */
	public static function backup( $gzip ) {
		$options  = WP_DBManager_Options::get();
		$path     = $options['path'];
		$filename = time() . '_-_' . DB_NAME . '.sql' . ( $gzip ? '.gz' : '' );
		$filepath = $path . '/' . $filename;

		$defaults_file = self::write_defaults_file();
		$error         = self::execute( self::dump_command( $filepath, $gzip, $defaults_file ) );
		self::delete_defaults_file( $defaults_file );

		$result = array(
			'success'  => false,
			'filepath' => '',
			'reason'   => '',
		);

		if ( ! wp_is_writable( $path ) ) {
			$result['reason'] = 'path';
			return $result;
		}

		if ( ! is_file( $filepath ) ) {
			$result['reason'] = 'missing';
			return $result;
		}

		// An empty dump is worse than no dump: it looks like a backup right up
		// until somebody needs it. This has to be a content check rather than a
		// size check, and it has to come before $error is consulted, because
		// neither of those catches the gzip case - see dump_has_content().
		if ( ! self::dump_has_content( $filepath, $gzip ) ) {
			wp_delete_file( $filepath );
			$result['reason'] = 'empty';
			return $result;
		}

		if ( $error ) {
			wp_delete_file( $filepath );
			$result['reason'] = 'command';
			return $result;
		}

		$renamed = $path . '/' . md5_file( $filepath ) . '_-_' . $filename;
		rename( $filepath, $renamed );

		$result['success']  = true;
		$result['filepath'] = $renamed;

		return $result;
	}

	/**
	 * Restore a backup file.
	 *
	 * @param string $filename Backup file name, already sanitized.
	 * @return array {
	 *     @type bool  $ran    Whether the restore was attempted at all.
	 *     @type int   $error  Exit code from the client.
	 *     @type array $errors Path problems that stopped it running.
	 * }
	 */
	public static function restore( $filename ) {
		$options = WP_DBManager_Options::get();
		$errors  = array();

		if ( realpath( $options['path'] ) === false ) {
			/* translators: %s: configured backup folder path. */
			$errors[] = sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), $options['path'] );
		} elseif ( self::is_valid_path( $options['mysqlpath'] ) === 0 ) {
			/* translators: %s: configured mysql path. */
			$errors[] = sprintf( __( '%s is not a valid mysql path', 'wp-dbmanager' ), $options['mysqlpath'] );
		}

		if ( ! empty( $errors ) ) {
			return array(
				'ran'    => false,
				'error'  => 0,
				'errors' => $errors,
			);
		}

		$defaults_file = self::write_defaults_file();
		$command       = self::restore_command( $options['path'] . '/' . $filename, $defaults_file );
		$error         = 0;

		passthru( $command, $error );

		self::delete_defaults_file( $defaults_file );

		return array(
			'ran'    => true,
			'error'  => $error,
			'errors' => array(),
		);
	}
}
