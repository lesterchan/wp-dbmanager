<?php
/**
 * The backup files on disk.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists, parses, prunes and serves the files in the backup folder.
 *
 * @since 3.0.0
 */
class WP_DBManager_Backups {

	/**
	 * Whether a folder can be used as the backup folder.
	 *
	 * Readable and a directory. The pre-3.0.0 version also tried to reject an
	 * empty folder with `scandir( $folder ) <= 2`, comparing an array to an
	 * integer - which is always false, so the check never once fired. Making it
	 * count() would have been worse than leaving it broken: a backup folder with
	 * nothing in it yet is perfectly valid, and rejecting it would have stopped
	 * scheduled backups on exactly the sites that had never taken one.
	 *
	 * @param string $folder Path to check.
	 * @return bool
	 */
	public static function is_folder_valid( $folder ) {
		if ( '' === $folder || ! is_readable( $folder ) ) {
			return false;
		}

		return is_dir( $folder );
	}

	/**
	 * The extension of a file name, without the dot.
	 *
	 * The false check is not decoration. strrchr() returns false for a name with
	 * no dot in it, and substr( '', 1 ) then returns false on PHP 7.4 but '' on
	 * PHP 8 - so without this the return type depended on the PHP version.
	 *
	 * @param string $file_name File name.
	 * @return string
	 */
	public static function file_ext( $file_name ) {
		$dot = strrchr( $file_name, '.' );

		return false === $dot ? '' : substr( $dot, 1 );
	}

	/**
	 * Format a byte count for display.
	 *
	 * @param int $raw_size Size in bytes.
	 * @return string Localised size with a unit.
	 */
	public static function format_size( $raw_size ) {
		if ( $raw_size / GB_IN_BYTES > 1 ) {
			return number_format_i18n( $raw_size / GB_IN_BYTES, 1 ) . ' ' . __( 'GiB', 'wp-dbmanager' );
		} elseif ( $raw_size / MB_IN_BYTES > 1 ) {
			return number_format_i18n( $raw_size / MB_IN_BYTES, 1 ) . ' ' . __( 'MiB', 'wp-dbmanager' );
		} elseif ( $raw_size / KB_IN_BYTES > 1 ) {
			return number_format_i18n( $raw_size / KB_IN_BYTES, 1 ) . ' ' . __( 'KiB', 'wp-dbmanager' );
		}

		return number_format_i18n( $raw_size, 0 ) . ' ' . __( 'bytes', 'wp-dbmanager' );
	}

	/**
	 * List the backup files in a folder, oldest first.
	 *
	 * Returned as a list rather than keyed by mtime: two files written in the
	 * same second would otherwise collide and one would never be seen.
	 *
	 * @param string|null $path Backup folder path, or null for the configured one.
	 * @return array List of arrays with name and mtime keys.
	 */
	public static function all( $path = null ) {
		$path  = ( null === $path ) ? WP_DBManager_Options::backup_path() : $path;
		$files = array();

		if ( ! self::is_folder_valid( $path ) ) {
			return $files;
		}

		$handle = opendir( $path );

		if ( false === $handle ) {
			return $files;
		}

		$file = readdir( $handle );

		while ( false !== $file ) {
			$ext = self::file_ext( $file );

			if ( '.' !== $file && '..' !== $file && '.htaccess' !== $file && ( 'sql' === $ext || 'gz' === $ext ) ) {
				$files[] = array(
					'name'  => $file,
					'mtime' => filemtime( $path . '/' . $file ),
				);
			}

			$file = readdir( $handle );
		}

		closedir( $handle );

		usort( $files, array( __CLASS__, 'compare' ) );

		return $files;
	}

	/**
	 * Compare two backup files by modification time.
	 *
	 * @param array $a First file, with name and mtime keys.
	 * @param array $b Second file, with name and mtime keys.
	 * @return int
	 */
	public static function compare( $a, $b ) {
		if ( $a['mtime'] === $b['mtime'] ) {
			return strcmp( $a['name'], $b['name'] );
		}

		return ( $a['mtime'] < $b['mtime'] ) ? -1 : 1;
	}

	/**
	 * Break a backup file name into its parts.
	 *
	 * @param string $filename Backup file name.
	 * @return array Checksum, timestamp, database, name and formatted date.
	 */
	public static function parse_filename( $filename ) {
		$parts = explode( '_-_', $filename );

		if ( count( $parts ) > 2 ) {
			$file = array(
				'checksum'  => $parts[0],
				'timestamp' => $parts[1],
				'database'  => $parts[2],
			);
		} else {
			$file = array(
				'checksum'  => '-',
				'timestamp' => $parts[0],
				'database'  => ! empty( $parts[1] ) ? $parts[1] : $filename,
			);
		}

		$file['name'] = $filename;

		if ( is_numeric( $file['timestamp'] ) ) {
			$file['formatted_date'] = WP_DBManager::format_timestamp( $file['timestamp'] );
		} else {
			$file['formatted_date'] = '-';
		}

		return $file;
	}

	/**
	 * The parsed name of a backup file along with its size.
	 *
	 * @param string $filepath Full path to the backup file.
	 * @return array File parts plus path, size and formatted size.
	 */
	public static function parse_file( $filepath ) {
		$file = self::parse_filename( basename( $filepath ) );

		$file['path']           = dirname( $filepath );
		$file['size']           = filesize( $filepath );
		$file['formatted_size'] = self::format_size( $file['size'] );

		return $file;
	}

	/**
	 * Prune the oldest backups so the configured maximum is not exceeded.
	 *
	 * Leaves room for the backup that is about to be written, which is why the
	 * comparison is >= rather than >.
	 *
	 * @return void
	 */
	public static function prune() {
		$options    = WP_DBManager_Options::get();
		$max_backup = (int) $options['max_backup'];

		// A limit below one is treated as no limit. Deleting every backup the
		// site has is never what somebody meant by "maximum 0".
		if ( $max_backup < 1 ) {
			return;
		}

		$files = self::all( $options['path'] );
		$total = count( $files );

		while ( $total >= $max_backup && ! empty( $files ) ) {
			--$total;
			$oldest = array_shift( $files );
			wp_delete_file( $options['path'] . '/' . $oldest['name'] );
		}
	}

	/**
	 * Resolve a submitted file name to a real file inside the backup folder.
	 *
	 * @param string $filename Submitted file name.
	 * @return string|false Absolute path, or false when it is not a backup file.
	 */
	public static function resolve( $filename ) {
		$options = WP_DBManager_Options::get();

		$clean = sanitize_file_name( $filename );
		$clean = str_replace( 'sql_.gz', 'sql.gz', $clean );

		// Check the name that is actually read, not the one that was submitted.
		if ( substr( $clean, -4 ) !== '.sql' && substr( $clean, -7 ) !== '.sql.gz' ) {
			return false;
		}

		// Resolve both ends and confirm the file really sits inside the folder,
		// so a name that climbs out with .. cannot be read.
		$backup_dir = realpath( $options['path'] );
		$file_path  = realpath( $options['path'] . '/' . $clean );

		if ( false === $backup_dir || false === $file_path ) {
			return false;
		}

		if ( strpos( $file_path, $backup_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
			return false;
		}

		return is_file( $file_path ) ? $file_path : false;
	}

	/**
	 * Send a backup file to the browser as a download.
	 *
	 * @return void
	 */
	public static function maybe_download() {
		// The Manage screen posts its bulk action in "action" at the top of the
		// table and "action2" at the bottom, exactly as core's list tables do.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only decides whether this request is ours; check_admin_referer() runs below, before anything is read.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		if ( 'download' !== $action ) {
			$action = isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';
		}

		if ( 'download' !== $action || empty( $_POST['backups'] ) ) {
			return;
		}

		$selected = array_map( 'sanitize_file_name', (array) wp_unslash( $_POST['backups'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Downloading sends one file. A multiple selection is refused by the
		// screen rather than resolved to whichever came first.
		if ( count( $selected ) !== 1 ) {
			return;
		}

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'Access Denied', 'wp-dbmanager' ) );
		}

		check_admin_referer( WP_DBManager_Backups_Table::nonce_action() );

		$file_path = self::resolve( sanitize_file_name( reset( $selected ) ) );

		if ( false !== $file_path ) {
			header( 'Pragma: public' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename=' . basename( $file_path ) . ';' );
			header( 'Content-Transfer-Encoding: binary' );
			header( 'Content-Length: ' . filesize( $file_path ) );

			// Silenced deliberately: a warning printed into the response body
			// would corrupt the dump the user is downloading.
			@readfile( $file_path );
		}

		exit;
	}
}
