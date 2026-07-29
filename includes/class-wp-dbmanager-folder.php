<?php
/**
 * Keeping the backup folder off the public web.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the backup folder, drops the protection files in, and asks the server
 * whether any of that actually worked.
 *
 * A dump contains the users table. Anyone who can guess a file name owns the
 * site, so "we copied an .htaccess in" is not evidence of anything - on nginx
 * it is evidence of nothing at all.
 *
 * @since 3.0.0
 */
class WP_DBManager_Folder {

	/**
	 * Transient holding the cached reachability answer.
	 */
	const TRANSIENT = 'wp_dbmanager_backup_folder_public';

	/**
	 * Identify the web server.
	 *
	 * Only 'apache' honours a dropped in .htaccess, which is the whole point of
	 * telling nginx apart rather than lumping the two together.
	 *
	 * @return string One of iis, nginx or apache.
	 */
	public static function server_type() {
		// Not set under WP-CLI, or when cron runs outside a web request.
		$software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
			: '';

		if ( strpos( $software, 'microsoft-iis' ) !== false ) {
			return 'iis';
		}

		if ( strpos( $software, 'nginx' ) !== false ) {
			return 'nginx';
		}

		return 'apache';
	}

	/**
	 * Whether the site runs on IIS.
	 *
	 * @return bool
	 */
	public static function is_iis() {
		return 'iis' === self::server_type();
	}

	/**
	 * Public URL of the backup folder.
	 *
	 * @return string|false The URL, or false when the folder is outside the web root.
	 */
	public static function url() {
		$path = WP_DBManager_Options::backup_path();

		if ( '' === $path ) {
			return false;
		}

		$backup_path = realpath( $path );

		if ( false === $backup_path ) {
			return false;
		}

		$backup_path = str_replace( '\\', '/', $backup_path );
		$roots       = array();

		// The content directory first: it is the more specific of the two, and it
		// may well sit outside ABSPATH.
		$content_dir = realpath( WP_CONTENT_DIR );

		if ( false !== $content_dir ) {
			$roots[] = array(
				'dir' => str_replace( '\\', '/', $content_dir ),
				'url' => content_url(),
			);
		}

		$abspath = realpath( ABSPATH );

		if ( false !== $abspath ) {
			$roots[] = array(
				'dir' => str_replace( '\\', '/', $abspath ),
				'url' => site_url(),
			);
		}

		foreach ( $roots as $root ) {
			if ( $backup_path === $root['dir'] ) {
				return $root['url'];
			}

			if ( strpos( $backup_path, $root['dir'] . '/' ) === 0 ) {
				return $root['url'] . substr( $backup_path, strlen( $root['dir'] ) );
			}
		}

		return false;
	}

	/**
	 * Whether the backup folder can be reached over HTTP.
	 *
	 * @param bool $probe Whether to make a request. Pass false to read the cached answer only.
	 * @return bool|null True when reachable, false when not, null when undetermined.
	 */
	public static function is_public( $probe = true ) {
		$cached = get_transient( self::TRANSIENT );

		if ( false !== $cached ) {
			return 'unknown' === $cached ? null : ( 'public' === $cached );
		}

		if ( ! $probe ) {
			return null;
		}

		$result     = 'unknown';
		$backup_url = self::url();
		$path       = WP_DBManager_Options::backup_path();

		if ( false === $backup_url ) {
			// Outside the web root, so there is nothing for the server to hand out.
			$result = 'protected';
		} elseif ( '' !== $path ) {
			$target = self::probe_target( $path );

			if ( '' !== $target ) {
				$args = array(
					'timeout'     => 5,
					'redirection' => 3,
					'sslverify'   => false,
				);

				// A second request for a name that cannot exist is what tells a real
				// 200 apart from a catch-all that answers 200 for everything.
				$missing = 'wp-dbmanager-probe-' . md5( uniqid( '', true ) ) . '.sql';

				$real_response    = wp_remote_head( $backup_url . '/' . $target, $args );
				$missing_response = wp_remote_head( $backup_url . '/' . $missing, $args );

				if ( ! is_wp_error( $real_response ) && ! is_wp_error( $missing_response ) ) {
					$real_code    = (int) wp_remote_retrieve_response_code( $real_response );
					$missing_code = (int) wp_remote_retrieve_response_code( $missing_response );

					if ( 200 !== $real_code ) {
						$result = 'protected';
					} elseif ( 200 !== $missing_code ) {
						$result = 'public';
					}
					// Both answered 200: the server answers 200 for anything. Inconclusive.
				}
			}
		}

		set_transient( self::TRANSIENT, $result, HOUR_IN_SECONDS );

		return 'unknown' === $result ? null : ( 'public' === $result );
	}

	/**
	 * Pick the file to ask the server for.
	 *
	 * A real backup when there is one, because that is the exact question: can
	 * somebody download the dump? Not .htaccess - plenty of nginx configs deny
	 * dotfiles while happily serving .sql, which reads as a false all-clear.
	 *
	 * @param string $path Backup folder path.
	 * @return string File name, or an empty string when there is nothing to ask for.
	 */
	protected static function probe_target( $path ) {
		$files = WP_DBManager_Backups::all( $path );

		if ( ! empty( $files ) ) {
			$target = end( $files );
			return $target['name'];
		}

		if ( is_file( $path . '/index.php' ) ) {
			return 'index.php';
		}

		return '';
	}

	/**
	 * Forget the cached reachability answer.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Create the backup folder and drop the protection files into it.
	 *
	 * @return void
	 */
	public static function create() {
		$path = WP_DBManager_Options::backup_path();

		if ( '' === $path ) {
			return;
		}

		wp_mkdir_p( $path );

		if ( ! is_dir( $path ) || ! wp_is_writable( $path ) ) {
			return;
		}

		if ( self::is_iis() ) {
			self::install_guard( 'Web.config.txt', $path . '/Web.config' );
		} else {
			self::install_guard( 'htaccess.txt', $path . '/.htaccess' );
		}

		self::install_guard( 'index.php', $path . '/index.php' );

		// Owner and group only. The folder holds complete database dumps, so it
		// has no business being world-readable even on a host where the web
		// server never serves it.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- WP_Filesystem is not initialised when this runs from the activation hook or from cron on a host whose method is not direct, and failing to tighten the mode is worse than the sniff. The path is the site owner's own backup folder.
		chmod( $path, 0750 );
	}

	/**
	 * Copy one of the shipped protection files into the backup folder.
	 *
	 * The two .txt files at the plugin root are payloads rather than
	 * configuration: they are copied in under the name the server actually reads
	 * -- .htaccess on Apache, Web.config on IIS. index.php goes with them so the
	 * folder cannot be listed.
	 *
	 * The copy is guarded rather than silenced -- a missing source or an
	 * unwritable destination is checked for, so there is no warning to suppress.
	 *
	 * @param string $source      File name at the plugin root.
	 * @param string $destination Absolute path to write.
	 * @return bool Whether the guard is in place afterwards.
	 */
	protected static function install_guard( $source, $destination ) {
		if ( is_file( $destination ) ) {
			return true;
		}

		$from = WP_DBMANAGER_DIR . $source;

		if ( ! is_readable( $from ) || ! wp_is_writable( dirname( $destination ) ) ) {
			return false;
		}

		return copy( $from, $destination );
	}

	/**
	 * Recreate the backup folder when asked to from the admin notice.
	 *
	 * @return void
	 */
	public static function maybe_fix() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether this request is ours, check_admin_referer() runs immediately below.
		if ( empty( $_GET['try_fix'] ) || 1 !== (int) $_GET['try_fix'] ) {
			return;
		}

		WP_DBManager_Admin::check_capability( 'fix' );

		check_admin_referer( 'wp-dbmanager_fix' );

		self::create();
		self::flush();
	}
}
