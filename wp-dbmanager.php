<?php
/**
 * Plugin Name: WP-DBManager
 * Plugin URI: https://lesterchan.net/portfolio/programming/php/
 * Description: Manages your WordPress database. Allows you to optimize database, repair database, backup database, restore database, delete backup database , drop/empty tables and run selected queries. Supports automatic scheduling of backing up, optimizing and repairing of database.
 * Version: 3.0.0
 * Requires at least: 4.6
 * Requires PHP: 7.2
 * Author: Lester 'GaMerZ' Chan
 * Author URI: https://lesterchan.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-dbmanager
 * Domain Path: /languages
 *
 * @package WP-DBManager
 */

/*
	Copyright 2026  Lester Chan  (email : lesterchan@gmail.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


defined( 'ABSPATH' ) || exit;


// Translations come from the WordPress.org language packs, which WordPress loads
// on demand from the Text Domain header. No loader call is needed since 4.6.


// Function: Database Manager Menu.
add_action( 'admin_menu', 'dbmanager_menu' );
/**
 * Registers the Database admin menu and its sub pages.
 */
function dbmanager_menu() {
	add_menu_page( __( 'Database', 'wp-dbmanager' ), __( 'Database', 'wp-dbmanager' ), 'install_plugins', 'wp-dbmanager/database-manager.php', '', 'dashicons-archive' );
	add_submenu_page( 'wp-dbmanager/database-manager.php', __( 'Backup DB', 'wp-dbmanager' ), __( 'Backup DB', 'wp-dbmanager' ), 'install_plugins', 'wp-dbmanager/database-backup.php' );
	add_submenu_page( 'wp-dbmanager/database-manager.php', __( 'Manage Backup DB', 'wp-dbmanager' ), __( 'Manage Backup DB', 'wp-dbmanager' ), 'install_plugins', 'wp-dbmanager/database-manage.php' );
	add_submenu_page( 'wp-dbmanager/database-manager.php', __( 'Optimize DB', 'wp-dbmanager' ), __( 'Optimize DB', 'wp-dbmanager' ), 'install_plugins', 'wp-dbmanager/database-optimize.php' );
	add_submenu_page( 'wp-dbmanager/database-manager.php', __( 'Repair DB', 'wp-dbmanager' ), __( 'Repair DB', 'wp-dbmanager' ), 'install_plugins', 'wp-dbmanager/database-repair.php' );
	add_submenu_page( 'wp-dbmanager/database-manager.php', __( 'Empty/Drop Tables', 'wp-dbmanager' ), __( 'Empty/Drop Tables', 'wp-dbmanager' ), 'install_plugins', 'wp-dbmanager/database-empty.php' );
	add_submenu_page( 'wp-dbmanager/database-manager.php', __( 'Run SQL Query', 'wp-dbmanager' ), __( 'Run SQL Query', 'wp-dbmanager' ), 'install_plugins', 'wp-dbmanager/database-run.php' );
	add_submenu_page( 'wp-dbmanager/database-manager.php', __( 'DB Options', 'wp-dbmanager' ), __( 'DB Options', 'wp-dbmanager' ), 'install_plugins', 'wp-dbmanager/wp-dbmanager.php', 'dbmanager_options' );
}


// Function: Append to the allowed mime types so .sql uploads are permitted.
add_filter( 'upload_mimes', 'dbmanager_upload_mimes' );
/**
 * Allows .sql files to be uploaded to the media library.
 *
 * @param array $mime_types Allowed mime types keyed by extension.
 * @return array Allowed mime types with sql added.
 */
function dbmanager_upload_mimes( $mime_types ) {
	$mime_types['sql'] = 'application/sql';
	return $mime_types;
}

// Function: Database Manager Cron.
add_filter( 'cron_schedules', 'cron_dbmanager_reccurences' );
add_action( 'dbmanager_cron_backup', 'cron_dbmanager_backup' );
add_action( 'dbmanager_cron_optimize', 'cron_dbmanager_optimize' );
add_action( 'dbmanager_cron_repair', 'cron_dbmanager_repair' );
/**
 * Runs the scheduled database backup.
 */
function cron_dbmanager_backup() {
	$backup_options = get_option( 'dbmanager_options' );

	// Enesure that backup path is valid before proceeding.
	if ( empty( $backup_options['path'] ) || ! dbmanager_is_folder_valid( $backup_options['path'] ) ) {
		return;
	}

	if ( (int) $backup_options['backup_period'] > 0 ) {
		$backup                  = array();
		$backup['date']          = time();
		$backup['mysqldumppath'] = $backup_options['mysqldumppath'];
		$backup['mysqlpath']     = $backup_options['mysqlpath'];
		$backup['path']          = $backup_options['path'];
		$backup['charset']       = ' --default-character-set="utf8mb4"';
		$backup['host']          = DB_HOST;
		$backup['port']          = '';
		$backup['sock']          = '';
		if ( strpos( DB_HOST, ':' ) !== false ) {
			$db_host        = explode( ':', DB_HOST );
			$backup['host'] = $db_host[0];
			if ( 0 !== (int) $db_host[1] ) {
				$backup['port'] = ' --port=' . escapeshellarg( (int) $db_host[1] );
			} else {
				$backup['sock'] = ' --socket=' . escapeshellarg( $db_host[1] );
			}
		}
		$backup['command']  = '';
		$backup['filename'] = $backup['date'] . '_-_' . DB_NAME . '.sql';
		$defaults_file      = dbmanager_write_defaults_file();
		if ( 1 === (int) $backup_options['backup_gzip'] ) {
			$backup['filename'] .= '.gz';
			$backup['filepath']  = $backup['path'] . '/' . $backup['filename'];
			do_action( 'wp_dbmanager_before_escapeshellcmd' );
			$backup['command'] = escapeshellarg( $backup['mysqldumppath'] ) . dbmanager_credential_args( $defaults_file ) . ' --force --host=' . escapeshellarg( $backup['host'] ) . ' --user=' . escapeshellarg( DB_USER ) . $backup['port'] . $backup['sock'] . $backup['charset'] . ' --add-drop-table --skip-lock-tables ' . escapeshellarg( DB_NAME ) . ' | gzip > ' . escapeshellarg( $backup['filepath'] );
		} else {
			$backup['filepath'] = $backup['path'] . '/' . $backup['filename'];
			do_action( 'wp_dbmanager_before_escapeshellcmd' );
			$backup['command'] = escapeshellarg( $backup['mysqldumppath'] ) . dbmanager_credential_args( $defaults_file ) . ' --force --host=' . escapeshellarg( $backup['host'] ) . ' --user=' . escapeshellarg( DB_USER ) . $backup['port'] . $backup['sock'] . $backup['charset'] . ' --add-drop-table --skip-lock-tables ' . escapeshellarg( DB_NAME ) . ' > ' . escapeshellarg( $backup['filepath'] );
		}
		$error = execute_backup( $backup['command'] );
		dbmanager_delete_defaults_file( $defaults_file );

		// Never rename or e-mail a dump that did not happen. An empty file is worse
		// than no file, because it looks like a backup until it is needed.
		if ( ! empty( $error ) || ! is_file( $backup['filepath'] ) || filesize( $backup['filepath'] ) === 0 ) {
			if ( is_file( $backup['filepath'] ) ) {
				wp_delete_file( $backup['filepath'] );
			}
			return;
		}

		$new_filepath = $backup['path'] . '/' . md5_file( $backup['filepath'] ) . '_-_' . $backup['filename'];
		rename( $backup['filepath'], $new_filepath );
		$backup_email = $backup_options['backup_email'];
		if ( ! empty( $backup_email ) ) {
			$attach = isset( $backup_options['backup_email_attach'] ) ? 1 === (int) $backup_options['backup_email_attach'] : (bool) dbmanager_default_options( 'backup_email_attach' );
			dbmanager_email_backup( $backup_email, $new_filepath, $attach );
		}
	}
}

/**
 * Runs the scheduled table optimisation.
 */
function cron_dbmanager_optimize() {
	global $wpdb;
	$backup_options  = get_option( 'dbmanager_options' );
	$optimize_period = (int) $backup_options['optimize_period'];
	if ( $optimize_period > 0 ) {
		$optimize_tables = array();
		$tables          = $wpdb->get_col( 'SHOW TABLES' );
		foreach ( $tables as $table_name ) {
			$optimize_tables[] = '`' . $table_name . '`';
		}
		$wpdb->query( 'OPTIMIZE TABLE ' . implode( ',', $optimize_tables ) );
	}
}

/**
 * Runs the scheduled table repair.
 */
function cron_dbmanager_repair() {
	global $wpdb;
	$backup_options = get_option( 'dbmanager_options' );
	$repair_period  = (int) $backup_options['repair_period'];
	if ( $repair_period > 0 ) {
		$repair_tables = array();
		$tables        = $wpdb->get_col( 'SHOW TABLES' );
		foreach ( $tables as $table_name ) {
			$repair_tables[] = '`' . $table_name . '`';
		}
		$wpdb->query( 'REPAIR TABLE ' . implode( ',', $repair_tables ) );
	}
}

/**
 * Registers the plugin cron schedules from the configured intervals.
 *
 * @param array $schedules Existing cron schedules.
 * @return array Schedules with the plugin intervals added.
 */
function cron_dbmanager_reccurences( $schedules ) {
	$backup_options = get_option( 'dbmanager_options' );

	if ( isset( $backup_options['backup'] ) && isset( $backup_options['backup_period'] ) ) {
		$backup = (int) $backup_options['backup'] * (int) $backup_options['backup_period'];
	} else {
		$backup = 0;
	}
	if ( isset( $backup_options['optimize'] ) && isset( $backup_options['optimize_period'] ) ) {
		$optimize = (int) $backup_options['optimize'] * (int) $backup_options['optimize_period'];
	} else {
		$optimize = 0;
	}
	if ( isset( $backup_options['repair'] ) && isset( $backup_options['repair_period'] ) ) {
		$repair = (int) $backup_options['repair'] * (int) $backup_options['repair_period'];
	} else {
		$repair = 0;
	}

	if ( 0 === $backup ) {
		$backup = 31536000;
	}
	if ( 0 === $optimize ) {
		$optimize = 31536000;
	}
	if ( 0 === $repair ) {
		$repair = 31536000;
	}
	$schedules['dbmanager_backup']   = array(
		'interval' => $backup,
		'display'  => __( 'WP-DBManager Backup Schedule', 'wp-dbmanager' ),
	);
	$schedules['dbmanager_optimize'] = array(
		'interval' => $optimize,
		'display'  => __( 'WP-DBManager Optimize Schedule', 'wp-dbmanager' ),
	);
	$schedules['dbmanager_repair']   = array(
		'interval' => $repair,
		'display'  => __( 'WP-DBManager Repair Schedule', 'wp-dbmanager' ),
	);
	return $schedules;
}


// Function: Ensure .htaccess Is In The Backup Folder.
add_action( 'admin_notices', 'dbmanager_admin_notices' );
/**
 * Warns about a backup folder that is not writable or is publicly reachable.
 */
function dbmanager_admin_notices() {
	// The notice exposes the backup folder path and links to a page only these users can reach.
	if ( ! current_user_can( 'install_plugins' ) ) {
		return;
	}

	$backup_options = get_option( 'dbmanager_options' );

	if ( empty( $backup_options['path'] ) ) {
		return;
	}

	if ( isset( $backup_options['hide_admin_notices'] ) && 0 !== (int) $backup_options['hide_admin_notices'] ) {
		return;
	}

	$backup_folder_writable = ( is_dir( $backup_options['path'] ) && wp_is_writable( $backup_options['path'] ) );
	$index_exists           = file_exists( $backup_options['path'] . '/index.php' );

	// Read the cached answer only. The Backup Database page does the actual
	// request, no reason to hold up an unrelated admin page for it.
	$is_public = dbmanager_is_backup_folder_public( false );

	if ( $backup_folder_writable && $index_exists && true !== $is_public ) {
		return;
	}

	echo '<div class="error">';

	if ( ! $backup_folder_writable ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static translated string wrapped in fixed markup.
		echo '<p style="font-weight: bold;">' . __( 'Your backup folder is NOT writable', 'wp-dbmanager' ) . '</p>';
		/* translators: %s: backup folder path. */
		echo '<p>' . sprintf( __( 'To correct this issue, make the folder <strong>%s</strong> writable.', 'wp-dbmanager' ), esc_html( $backup_options['path'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is part of the translated string, substitutions are escaped at the call.
	}

	if ( true === $is_public ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static translated string wrapped in fixed markup.
		echo '<p style="font-weight: bold;">' . __( 'Your backup folder is visible to the public', 'wp-dbmanager' ) . '</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static translated string wrapped in fixed markup.
		echo '<p>' . __( 'Anyone who guesses a backup file name can download your entire database. Move the backup folder outside your web root under DB Options.', 'wp-dbmanager' ) . '</p>';
	}

	if ( ! $index_exists ) {
		/* translators: 1: source file path, 2: destination file path. */
		echo '<p>' . sprintf( __( 'To correct this issue, move the file from <strong>%1$s</strong> to <strong>%2$s</strong>', 'wp-dbmanager' ), esc_html( plugin_dir_path( __FILE__ ) . 'index.php' ), esc_html( $backup_options['path'] . '/index.php' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is part of the translated string, substitutions are escaped at the call.
	}

	/* translators: %s: URL that asks the plugin to fix the folder. */
	echo '<p>' . sprintf( __( '<a href="%s">Click here</a> to let WP-DBManager try to fix it', 'wp-dbmanager' ), wp_nonce_url( admin_url( 'admin.php?page=wp-dbmanager/database-backup.php&try_fix=1' ), 'wp-dbmanager_fix' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The link markup is part of the translated string and the URL comes from wp_nonce_url().
	echo '</div>';
}


/**
 * Attempts to locate the mysql and mysqldump binaries.
 *
 * @return array Paths keyed by mysql and mysqldump.
 */
function detect_mysql() {
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
	} elseif ( function_exists( 'exec' ) ) {
			$paths['mysql']     = @exec( 'which mysql' );
			$paths['mysqldump'] = @exec( 'which mysqldump' );
	} else {
		$paths['mysql']     = 'mysql';
		$paths['mysqldump'] = 'mysqldump';
	}
	return $paths;
}

/**
 * Identifies the web server.
 *
 * Only 'apache' honours a dropped in .htaccess, which is the whole point of
 * telling nginx apart rather than lumping it in with Apache.
 *
 * @return string One of iis, nginx or apache.
 */
function dbmanager_get_server_type() {
	// Not set under WP-CLI or when cron runs outside a web request.
	$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

	if ( strpos( $server_software, 'microsoft-iis' ) !== false ) {
		return 'iis';
	}

	if ( strpos( $server_software, 'nginx' ) !== false ) {
		return 'nginx';
	}

	return 'apache';
}


/**
 * Whether the site runs on IIS.
 *
 * @return bool True when the server is IIS.
 */
function is_iis() {
	return dbmanager_get_server_type() === 'iis';
}


/**
 * Public URL of the backup folder.
 *
 * @return string|false The URL, or false when the folder is outside the web root.
 */
function dbmanager_get_backup_url() {
	$backup_options = get_option( 'dbmanager_options' );

	if ( empty( $backup_options['path'] ) ) {
		return false;
	}

	$backup_path = realpath( $backup_options['path'] );
	if ( false === $backup_path ) {
		return false;
	}

	$backup_path = str_replace( '\\', '/', $backup_path );

	// Content directory first, it is the more specific of the two and may well
	// sit outside ABSPATH.
	$roots       = array();
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
 * Returns true, false, or null when it could not be determined. Asks the server
 * rather than trusting that a dropped in file did anything, because on nginx it
 * did not. Pass false to read the cached answer without making a request.
 *
 * @param bool $probe Whether to make a request. Pass false to read the cached answer only.
 * @return bool|null True when reachable, false when not, null when undetermined.
 */
function dbmanager_is_backup_folder_public( $probe = true ) {
	$transient_name = 'dbmanager_backup_folder_public';
	$cached         = get_transient( $transient_name );

	if ( false !== $cached ) {
		if ( 'unknown' === $cached ) {
			return null;
		}
		return 'public' === $cached;
	}

	if ( ! $probe ) {
		return null;
	}

	$result         = 'unknown';
	$backup_url     = dbmanager_get_backup_url();
	$backup_options = get_option( 'dbmanager_options' );

	if ( false === $backup_url ) {
		// Outside the web root, there is nothing for the server to hand out.
		$result = 'protected';
	} elseif ( ! empty( $backup_options['path'] ) ) {
		// Probe a real backup file when there is one. That is the exact question:
		// can somebody download the dump? Fall back to index.php on a fresh install.
		// Not .htaccess, plenty of nginx configs deny dotfiles while serving .sql,
		// which would read as a false all clear.
		$backup_files = dbmanager_list_backup_files( $backup_options['path'] );
		if ( ! empty( $backup_files ) ) {
			$target = end( $backup_files );
			$target = $target['name'];
		} elseif ( is_file( $backup_options['path'] . '/index.php' ) ) {
			$target = 'index.php';
		} else {
			$target = '';
		}

		if ( '' !== $target ) {
			// Redirects are followed, an http to https hop is not protection. A second
			// request for a name that cannot exist tells a real 200 apart from a catch
			// all redirect that answers 200 for everything.
			$args    = array(
				'timeout'     => 5,
				'redirection' => 3,
				'sslverify'   => false,
			);
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
				// Both answered 200, the server answers 200 for anything. Inconclusive.
			}
		}
	}

	set_transient( $transient_name, $result, HOUR_IN_SECONDS );

	if ( 'unknown' === $result ) {
		return null;
	}

	return 'public' === $result;
}


/**
 * Forgets the cached reachability answer.
 */
function dbmanager_flush_backup_folder_public() {
	delete_transient( 'dbmanager_backup_folder_public' );
}

/**
 * Escapes a value for a MySQL option file.
 *
 * @param string $value Raw value.
 * @return string Quoted and escaped value.
 */
function dbmanager_escape_option_file_value( $value ) {
	// Backslash is an escape character inside option files, so it has to be doubled
	// before the value is wrapped in quotes.
	$value = str_replace( '\\', '\\\\', $value );
	$value = str_replace( '"', '\\"', $value );

	return '"' . $value . '"';
}


/**
 * Writes the database password to a temporary option file.
 *
 * Keeps the password off the command line where 'ps' would expose it.
 *
 * @return string|false Path to the file, or false when it could not be written.
 */
function dbmanager_write_defaults_file() {
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

	$contents = "[client]\npassword=" . dbmanager_escape_option_file_value( DB_PASSWORD ) . "\n";
	if ( @file_put_contents( $defaults_file, $contents ) === false ) {
		wp_delete_file( $defaults_file );
		return false;
	}

	return $defaults_file;
}


/**
 * Builds the credential argument for a mysql or mysqldump command.
 *
 * Must be placed immediately after the binary, --defaults-extra-file has to come first.
 *
 * @param string|false $defaults_file Path to the option file, or false to fall back to --password.
 * @return string Argument to place immediately after the binary.
 */
function dbmanager_credential_args( $defaults_file ) {
	if ( false !== $defaults_file ) {
		return ' --defaults-extra-file=' . escapeshellarg( $defaults_file );
	}

	// No option file could be written, fall back to the old command line password.
	return ' --password=' . escapeshellarg( DB_PASSWORD );
}


/**
 * Removes the temporary option file.
 *
 * @param string|false $defaults_file Path to the file, or false when none was written.
 */
function dbmanager_delete_defaults_file( $defaults_file ) {
	if ( false !== $defaults_file && is_file( $defaults_file ) ) {
		wp_delete_file( $defaults_file );
	}
}


/**
 * Executes the backup command for the current platform.
 *
 * Executes OS-Dependent mysqldump Command (By: Vlad Sharanhovich).
 *
 * @param string $command Shell command to run.
 * @return int|string Exit code, or an error message when a configured path is invalid.
 */
function execute_backup( $command ) {
	$backup_options = get_option( 'dbmanager_options' );
	check_backup_files();

	if ( realpath( $backup_options['path'] ) === false ) {
		/* translators: %s: configured backup folder path. */
		return sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), $backup_options['path'] );
	} elseif ( dbmanager_is_valid_path( $backup_options['mysqldumppath'] ) === 0 ) {
		/* translators: %s: configured mysqldump path. */
		return sprintf( __( '%s is not a valid mysqldump path', 'wp-dbmanager' ), $backup_options['mysqldumppath'] );
	} elseif ( dbmanager_is_valid_path( $backup_options['mysqlpath'] ) === 0 ) {
		/* translators: %s: configured mysql path. */
		return sprintf( __( '%s is not a valid mysql path', 'wp-dbmanager' ), $backup_options['mysqlpath'] );
	}

	if ( substr( PHP_OS, 0, 3 ) === 'WIN' ) {
		$writable_dir = $backup_options['path'];
		$tmpnam       = $writable_dir . '/wp-dbmanager.bat';
		$fp           = fopen( $tmpnam, 'w' );
		fwrite( $fp, $command );
		fclose( $fp );
		system( $tmpnam . ' > NUL', $error );
		wp_delete_file( $tmpnam );
	} else {
		passthru( $command, $error );
	}
	return $error;
}

/**
 * Checks a path for characters that are not allowed.
 *
 * @param string $path Path to check.
 * @return int 1 when the path is acceptable, 0 when it is not.
 */
function dbmanager_is_valid_path( $path ) {
	return preg_match( '/^[^*?"<>|;]*$/', $path );
}

/**
 * Renders the result messages for an admin page.
 *
 * Messages are collected as plain text and escaped here, at the point of
 * output, rather than being assembled into an HTML string by each caller.
 *
 * @param array $messages List of arrays with a type of success, info or error, and a text key.
 */
function dbmanager_render_messages( $messages ) {
	if ( empty( $messages ) ) {
		return;
	}

	$colors = array(
		'success' => 'green',
		'info'    => 'blue',
		'error'   => 'red',
	);

	echo '<div id="message" class="updated fade">';
	foreach ( $messages as $message ) {
		$type  = isset( $message['type'] ) ? $message['type'] : 'error';
		$color = isset( $colors[ $type ] ) ? $colors[ $type ] : 'red';
		printf( '<p style="color: %1$s;">%2$s</p>', esc_attr( $color ), esc_html( $message['text'] ) );
	}
	echo '</div>';
}

/**
 * Formats a Unix timestamp in the site's timezone.
 *
 * Timestamps are stored as real Unix time, so the site's GMT offset has to be
 * applied before formatting.
 *
 * @param int         $timestamp Unix timestamp.
 * @param string|null $format    Date format, defaults to the site date and time formats combined.
 * @return string Formatted date.
 */
function dbmanager_format_timestamp( $timestamp, $format = null ) {
	if ( null === $format ) {
		/* translators: 1: date, 2: time. */
		$format = sprintf( __( '%1$s @ %2$s', 'wp-dbmanager' ), get_option( 'date_format' ), get_option( 'time_format' ) );
	}

	$offset = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;

	return mysql2date( $format, gmdate( 'Y-m-d H:i:s', (int) ( $timestamp + $offset ) ) );
}

/**
 * Breaks a backup file name into its parts.
 *
 * @param string $filename Backup file name.
 * @return array Checksum, timestamp, database, name and formatted date.
 */
function dbmanager_parse_filename( $filename ) {
	$file_parts = explode( '_-_', $filename );
	if ( count( $file_parts ) > 2 ) {
		$file = array(
			'checksum'  => $file_parts[0],
			'timestamp' => $file_parts[1],
			'database'  => $file_parts[2],
		);
	} else {
		$file = array(
			'checksum'  => '-',
			'timestamp' => $file_parts[0],
			'database'  => ! empty( $file_parts[1] ) ? $file_parts[1] : $filename,
		);
	}

	$file['name'] = $filename;

	if ( is_numeric( $file['timestamp'] ) ) {
		/* translators: 1: date, 2: time. */
		$file['formatted_date'] = dbmanager_format_timestamp( $file['timestamp'] );
	} else {
		$file['formatted_date'] = '-';
	}

	return $file;
}

/**
 * Returns the parsed name of a backup file along with its size.
 *
 * @param string $filepath Full path to the backup file.
 * @return array File parts plus path, size and formatted size.
 */
function dbmanager_parse_file( $filepath ) {
	$filename                     = basename( $filepath );
	$file_parts                   = dbmanager_parse_filename( $filename );
	$file_parts['path']           = dirname( $filepath );
	$file_parts['size']           = filesize( $filepath );
	$file_parts['formatted_size'] = format_size( $file_parts['size'] );

	return $file_parts;
}

/**
 * E-mails the details of a backup, optionally attaching the file.
 *
 * $attach controls whether the dump itself is attached. The details in the
 * message body are useful on their own when it is not.
 *
 * @param string $to Recipient address.
 * @param string $backup_file_path Full path to the backup file.
 * @param bool   $attach Whether to attach the backup file itself.
 * @return bool Whether the mail was handed off successfully.
 */
function dbmanager_email_backup( $to, $backup_file_path, $attach = true ) {
	$to = ( ! empty( $to ) ? $to : get_option( 'admin_email' ) );

	if ( is_email( $to ) && file_exists( $backup_file_path ) ) {
		$backup_options = get_option( 'dbmanager_options' );

		$file = dbmanager_parse_file( $backup_file_path );

		$subject = ( ! empty( $backup_options['backup_email_subject'] ) ? $backup_options['backup_email_subject'] : dbmanager_default_options( 'backup_email_subject' ) );
		$subject = str_replace(
			array(
				'%SITE_NAME%',
				'%POST_DATE%',
				'%POST_TIME%',
			),
			array(
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				dbmanager_format_timestamp( $file['timestamp'], get_option( 'date_format' ) ),
				dbmanager_format_timestamp( $file['timestamp'], get_option( 'time_format' ) ),
			),
			$subject
		);
		$message = __( 'Website Name:', 'wp-dbmanager' ) . ' ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . "\n" .
			__( 'Website URL:', 'wp-dbmanager' ) . ' ' . get_bloginfo( 'url' ) . "\n\n" .
			__( 'Backup File Name:', 'wp-dbmanager' ) . ' ' . $file['name'] . "\n" .
			__( 'Backup File MD5 Checksum:', 'wp-dbmanager' ) . ' ' . $file['checksum'] . "\n" .
			__( 'Backup File Date:', 'wp-dbmanager' ) . ' ' . $file['formatted_date'] . "\n" .
			__( 'Backup File Size:', 'wp-dbmanager' ) . ' ' . $file['formatted_size'] . "\n\n" .
			__( 'With Regards,', 'wp-dbmanager' ) . "\n" .
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' ' . __( 'Administrator', 'wp-dbmanager' ) . "\n" .
			get_bloginfo( 'url' );

		$from      = ( ! empty( $backup_options['backup_email_from'] ) ? $backup_options['backup_email_from'] : dbmanager_default_options( 'backup_email_from' ) );
		$from_name = ( ! empty( $backup_options['backup_email_from_name'] ) ? $backup_options['backup_email_from_name'] : dbmanager_default_options( 'backup_email_from_name' ) );
		$headers[] = 'From: "' . wp_specialchars_decode( $from_name, ENT_QUOTES ) . '" <' . $from . '>';

		$attachments = $attach ? $backup_file_path : array();

		return wp_mail( $to, $subject, $message, $headers, $attachments );
	}

	return false;
}


// Function: Format Bytes Into KB/MB.
if ( ! function_exists( 'format_size' ) ) {
	/**
	 * Formats a byte count for display.
	 *
	 * @param int $raw_size Size in bytes.
	 * @return string Localised size with a unit.
	 */
	function format_size( $raw_size ) {
		if ( $raw_size / 1073741824 > 1 ) {
			return number_format_i18n( $raw_size / 1048576, 1 ) . ' ' . __( 'GiB', 'wp-dbmanager' );
		} elseif ( $raw_size / 1048576 > 1 ) {
			return number_format_i18n( $raw_size / 1048576, 1 ) . ' ' . __( 'MiB', 'wp-dbmanager' );
		} elseif ( $raw_size / 1024 > 1 ) {
			return number_format_i18n( $raw_size / 1024, 1 ) . ' ' . __( 'KiB', 'wp-dbmanager' );
		} else {
			return number_format_i18n( $raw_size, 0 ) . ' ' . __( 'bytes', 'wp-dbmanager' );
		}
	}
}


// Function: Get File Extension.
if ( ! function_exists( 'file_ext' ) ) {
	/**
	 * Returns the extension of a file name.
	 *
	 * @param string $file_name File name.
	 * @return string Extension without the dot.
	 */
	function file_ext( $file_name ) {
		return substr( strrchr( $file_name, '.' ), 1 );
	}
}


/**
 * Checks that a folder is readable, a directory, and not empty.
 *
 * @param string $folder Path to check.
 * @return bool Whether the folder can be used.
 */
function dbmanager_is_folder_valid( $folder ) {
	// Check folder whether it is readable.
	if ( ! is_readable( $folder ) ) {
		return false;
	}

	// Ensure folder is a directory.
	if ( ! is_dir( $folder ) ) {
		return false;
	}

	// Ensure folder is not empty. 2 because it includes '.' and '..'.
	if ( scandir( $folder ) <= 2 ) {
		return false;
	}

	return true;
}


/**
 * Prunes the oldest backups so the configured maximum is not exceeded.
 */
function check_backup_files() {
	$backup_options = get_option( 'dbmanager_options' );
	$max_backup     = isset( $backup_options['max_backup'] ) ? (int) $backup_options['max_backup'] : 0;

	// A limit below one is treated as no limit. Deleting every backup is never the intent.
	if ( $max_backup < 1 ) {
		return;
	}

	$database_files = dbmanager_list_backup_files( $backup_options['path'] );

	// Prune down to the limit, leaving room for the backup about to be written.
	$total = count( $database_files );
	while ( $total >= $max_backup ) {
		--$total;
		$oldest = array_shift( $database_files );
		wp_delete_file( $backup_options['path'] . '/' . $oldest['name'] );
	}
}


/**
 * Lists the backup files in a folder, oldest first.
 *
 * Returned as a list rather than keyed by mtime, two files written in the same
 * second would otherwise collide and one would never be seen.
 *
 * @param string $path Backup folder path.
 * @return array List of arrays with name and mtime keys.
 */
function dbmanager_list_backup_files( $path ) {
	$database_files = array();

	if ( ! dbmanager_is_folder_valid( $path ) ) {
		return $database_files;
	}

	$handle = opendir( $path );
	if ( false !== $handle ) {
		$file = readdir( $handle );
		while ( false !== $file ) {
			if ( '.' !== $file && '..' !== $file && '.htaccess' !== $file && ( 'sql' === file_ext( $file ) || 'gz' === file_ext( $file ) ) ) {
				$database_files[] = array(
					'name'  => $file,
					'mtime' => filemtime( $path . '/' . $file ),
				);
			}
			$file = readdir( $handle );
		}
		closedir( $handle );
	}

	usort( $database_files, 'dbmanager_sort_backup_files' );

	return $database_files;
}


/**
 * Compares two backup files by modification time.
 *
 * @param array $a First file, with name and mtime keys.
 * @param array $b Second file, with name and mtime keys.
 * @return int Negative, zero or positive per usort().
 */
function dbmanager_sort_backup_files( $a, $b ) {
	if ( $a['mtime'] === $b['mtime'] ) {
		return strcmp( $a['name'], $b['name'] );
	}

	return ( $a['mtime'] < $b['mtime'] ) ? -1 : 1;
}


/**
 * Returns the default value for an option.
 *
 * @param string $option_name Option key.
 * @return mixed Default value, or null for an unknown key.
 */
function dbmanager_default_options( $option_name ) {
	switch ( $option_name ) {
		case 'backup_email_from':
			return get_option( 'admin_email' );
		case 'backup_email_from_name':
			return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' ' . __( 'Administrator', 'wp-dbmanager' );
		case 'backup_email_subject':
			return __( '%SITE_NAME% Database Backup File For %POST_DATE% @ %POST_TIME%', 'wp-dbmanager' );
		case 'hide_admin_notices':
			return 0;
		case 'backup_gzip':
			return 1;
		case 'backup_email_attach':
			return 1;
	}
}

// Function: Acticate Plugin.
register_activation_hook( __FILE__, 'dbmanager_activation' );
/**
 * Creates the default options and the backup folder on activation.
 *
 * @param bool $network_wide Whether the plugin is being activated network wide.
 */
function dbmanager_activation( $network_wide ) {
	$auto = detect_mysql();
	// Add Options.
	$option_name = 'dbmanager_options';
	$option      = array(
		'mysqldumppath'          => $auto['mysqldump'],
		'mysqlpath'              => $auto['mysql'],
		'path'                   => str_replace( '\\', '/', WP_CONTENT_DIR ) . '/backup-db',
		'max_backup'             => 10,
		'backup'                 => 1,
		'backup_gzip'            => dbmanager_default_options( 'backup_gzip' ),
		'backup_period'          => 604800,
		'backup_email'           => '',
		'backup_email_attach'    => dbmanager_default_options( 'backup_email_attach' ),
		'backup_email_from'      => dbmanager_default_options( 'backup_email_from' ),
		'backup_email_from_name' => dbmanager_default_options( 'backup_email_from_name' ),
		'backup_email_subject'   => dbmanager_default_options( 'backup_email_subject' ),
		'optimize'               => 3,
		'optimize_period'        => 86400,
		'repair'                 => 2,
		'repair_period'          => 604800,
		'hide_admin_notices'     => 0,
	);

	if ( is_multisite() && $network_wide ) {
		// number => 0 lifts the default limit of 100, a network can be larger.
		$ms_sites = get_sites( array( 'number' => 0 ) );

		if ( 0 < count( $ms_sites ) ) {
			foreach ( $ms_sites as $ms_site ) {
				switch_to_blog( $ms_site->blog_id );
				add_option( $option_name, $option );
				dbmanager_activate();
				// Paired inside the loop, switch_to_blog() stacks.
				restore_current_blog();
			}
		}
	} else {
		add_option( $option_name, $option );
		dbmanager_activate();
	}
}

/**
 * Prepares a single site on activation.
 */
function dbmanager_activate() {
	dbmanager_create_backup_folder();

	// Remove 'manage_database', we use 'install_plugins' from 2.80.6.
	$role = get_role( 'administrator' );
	if ( $role->has_cap( 'manage_database' ) ) {
		$role->remove_cap( 'manage_database' );
	}
}

/**
 * Creates the backup folder and drops the protection files into it.
 */
function dbmanager_create_backup_folder() {
	$plugin_path    = plugin_dir_path( __FILE__ );
	$backup_path    = WP_CONTENT_DIR . '/backup-db';
	$backup_options = get_option( 'dbmanager_options' );

	if ( ! empty( $backup_options['path'] ) ) {
		$backup_path = $backup_options['path'];
	}

	// Create Backup Folder.
	wp_mkdir_p( $backup_path );
	if ( is_dir( $backup_path ) && wp_is_writable( $backup_path ) ) {
		if ( is_iis() ) {
			if ( ! is_file( $backup_path . '/Web.config' ) ) {
				@copy( $plugin_path . 'Web.config.txt', $backup_path . '/Web.config' );
			}
		} elseif ( ! is_file( $backup_path . '/.htaccess' ) ) {
				@copy( $plugin_path . 'htaccess.txt', $backup_path . '/.htaccess' );
		}
		if ( ! is_file( $backup_path . '/index.php' ) ) {
			@copy( $plugin_path . 'index.php', $backup_path . '/index.php' );
		}
		@chmod( $backup_path, 0750 );
	}
}

add_action( 'init', 'dbmanager_try_fix' );
/**
 * Recreates the backup folder when asked to from the admin notice.
 */
function dbmanager_try_fix() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether this request is ours, check_admin_referer() runs immediately below.
	if ( ! empty( $_GET['try_fix'] ) && 1 === (int) $_GET['try_fix'] ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( 'Access Denied' );
		}
		check_admin_referer( 'wp-dbmanager_fix' );
		dbmanager_create_backup_folder();
		dbmanager_flush_backup_folder_public();
	}
}


// Function: Download Database.
add_action( 'init', 'download_database' );
/**
 * Sends a backup file to the browser as a download.
 */
function download_database() {
	if ( isset( $_POST['do'] ) && __( 'Download', 'wp-dbmanager' ) === $_POST['do'] && ! empty( $_POST['database_file'] ) ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( 'Access Denied' );
		}
		check_admin_referer( 'wp-dbmanager_manage' );
		$backup_options  = get_option( 'dbmanager_options' );
		$clean_file_name = sanitize_file_name( wp_unslash( $_POST['database_file'] ) );
		$clean_file_name = str_replace( 'sql_.gz', 'sql.gz', $clean_file_name );

		// Check the name that is actually read, not the one that was submitted.
		$has_valid_extension = ( substr( $clean_file_name, -4 ) === '.sql' || substr( $clean_file_name, -7 ) === '.sql.gz' );

		// Resolve both paths and confirm the file really sits inside the backup folder.
		$backup_dir           = realpath( $backup_options['path'] );
		$file_path            = realpath( $backup_options['path'] . '/' . $clean_file_name );
		$is_inside_backup_dir = ( false !== $backup_dir && false !== $file_path && strpos( $file_path, $backup_dir . DIRECTORY_SEPARATOR ) === 0 );

		if ( $has_valid_extension && $is_inside_backup_dir && is_file( $file_path ) ) {
			header( 'Pragma: public' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
			header( 'Content-Type: application/force-download' );
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Type: application/download' );
			header( 'Content-Disposition: attachment; filename=' . basename( $file_path ) . ';' );
			header( 'Content-Transfer-Encoding: binary' );
			header( 'Content-Length: ' . filesize( $file_path ) );
			@readfile( $file_path );
		}
		exit();
	}
}

/**
 * Whether a PHP function has been disabled in the configuration.
 *
 * @param string $function_name Function to check.
 * @return bool Whether the function is disabled.
 */
function dbmanager_is_function_disabled( $function_name ) {
	return in_array( $function_name, array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );
}

// Function: Polyfill array_key_first() for PHP < 7.3.
if ( ! function_exists( 'array_key_first' ) ) {
	/**
	 * Returns the first key of an array.
	 *
	 * @param array $arr Array to read.
	 * @return mixed|null First key, or null when the array is empty.
	 */
	function array_key_first( $arr ) {
		foreach ( $arr as $key => $unused ) {
			return $key;
		}
		return null;
	}
}

/**
 * Renders and saves the DB Options page.
 */
function dbmanager_options() {
	$text               = '';
	$backup_options     = get_option( 'dbmanager_options' );
	$old_backup_options = $backup_options;
	if ( ! empty( $_POST['Submit'] ) ) {
		check_admin_referer( 'wp-dbmanager_options' );
		$backup_options['mysqldumppath']          = ! empty( $_POST['db_mysqldumppath'] ) ? sanitize_text_field( wp_unslash( $_POST['db_mysqldumppath'] ) ) : '';
		$backup_options['mysqlpath']              = ! empty( $_POST['db_mysqlpath'] ) ? sanitize_text_field( wp_unslash( $_POST['db_mysqlpath'] ) ) : '';
		$backup_options['path']                   = ! empty( $_POST['db_path'] ) ? sanitize_text_field( wp_unslash( $_POST['db_path'] ) ) : '';
		$backup_options['max_backup']             = ! empty( $_POST['db_max_backup'] ) ? (int) $_POST['db_max_backup'] : 0;
		$backup_options['backup']                 = ! empty( $_POST['db_backup'] ) ? (int) $_POST['db_backup'] : 0;
		$backup_options['backup_gzip']            = ! empty( $_POST['db_backup_gzip'] ) ? (int) $_POST['db_backup_gzip'] : 0;
		$backup_options['backup_period']          = ! empty( $_POST['db_backup_period'] ) ? (int) $_POST['db_backup_period'] : 0;
		$backup_options['backup_email']           = ! empty( $_POST['db_backup_email'] ) ? sanitize_email( wp_unslash( $_POST['db_backup_email'] ) ) : '';
		$backup_options['backup_email_attach']    = ! empty( $_POST['db_backup_email_attach'] ) ? (int) $_POST['db_backup_email_attach'] : 0;
		$backup_options['backup_email_from']      = ! empty( $_POST['db_backup_email_from'] ) ? sanitize_email( wp_unslash( $_POST['db_backup_email_from'] ) ) : '';
		$backup_options['backup_email_from_name'] = ! empty( $_POST['db_backup_email_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['db_backup_email_from_name'] ) ) : '';
		$backup_options['backup_email_subject']   = ! empty( $_POST['db_backup_email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['db_backup_email_subject'] ) ) : '';
		$backup_options['optimize']               = ! empty( $_POST['db_optimize'] ) ? (int) $_POST['db_optimize'] : 0;
		$backup_options['optimize_period']        = ! empty( $_POST['db_optimize_period'] ) ? (int) $_POST['db_optimize_period'] : 0;
		$backup_options['repair']                 = ! empty( $_POST['db_repair'] ) ? (int) $_POST['db_repair'] : 0;
		$backup_options['repair_period']          = ! empty( $_POST['db_repair_period'] ) ? (int) $_POST['db_repair_period'] : 0;
		$backup_options['hide_admin_notices']     = ! empty( $_POST['db_hide_admin_notices'] ) ? (int) $_POST['db_hide_admin_notices'] : 0;

		// Each path is validated on its own, a failure on one must not skip the checks on the others.
		$errors = array();

		if ( realpath( $backup_options['path'] ) === false ) {
			/* translators: %s: configured backup folder path. */
			$errors[]               = sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), $backup_options['path'] );
			$backup_options['path'] = $old_backup_options['path'];
		}
		if ( dbmanager_is_valid_path( $backup_options['mysqldumppath'] ) === 0 ) {
			/* translators: %s: configured mysqldump path. */
			$errors[]                        = sprintf( __( '%s is not a valid mysqldump path', 'wp-dbmanager' ), $backup_options['mysqldumppath'] );
			$backup_options['mysqldumppath'] = $old_backup_options['mysqldumppath'];
		}
		if ( dbmanager_is_valid_path( $backup_options['mysqlpath'] ) === 0 ) {
			/* translators: %s: configured mysql path. */
			$errors[]                    = sprintf( __( '%s is not a valid mysql path', 'wp-dbmanager' ), $backup_options['mysqlpath'] );
			$backup_options['mysqlpath'] = $old_backup_options['mysqlpath'];
		}
		if ( ! empty( $errors ) ) {
			$text = '<div id="message" class="error"><p>' . implode( '</p><p>', array_map( 'esc_html', $errors ) ) . '</p></div>';
		}

		$update_db_options = update_option( 'dbmanager_options', $backup_options );

		// The backup path may have moved, the cached answer is about the old one.
		dbmanager_flush_backup_folder_public();
		if ( $update_db_options ) {
			$text .= '<div id="message" class="updated"><p>' . __( 'Database Options Updated', 'wp-dbmanager' ) . '</p></div>';
		}
		if ( empty( $text ) ) {
			$text = '<div id="message" class="error"><p>' . __( 'No Database Option Updated', 'wp-dbmanager' ) . '</p></div>';
		}
		wp_clear_scheduled_hook( 'dbmanager_cron_backup' );
		if ( $backup_options['backup_period'] > 0 ) {
			if ( ! wp_next_scheduled( 'dbmanager_cron_backup' ) ) {
				wp_schedule_event( time(), 'dbmanager_backup', 'dbmanager_cron_backup' );
			}
		}
		wp_clear_scheduled_hook( 'dbmanager_cron_optimize' );
		if ( $backup_options['optimize_period'] > 0 ) {
			if ( ! wp_next_scheduled( 'dbmanager_cron_optimize' ) ) {
				wp_schedule_event( time(), 'dbmanager_optimize', 'dbmanager_cron_optimize' );
			}
		}
		wp_clear_scheduled_hook( 'dbmanager_cron_repair' );
		if ( $backup_options['repair_period'] > 0 ) {
			if ( ! wp_next_scheduled( 'dbmanager_cron_repair' ) ) {
				wp_schedule_event( time(), 'dbmanager_repair', 'dbmanager_cron_repair' );
			}
		}
	}
	$path = detect_mysql();

	// Default Options.
	if ( ! isset( $backup_options['backup_email_from'] ) ) {
		$backup_options['backup_email_from'] = dbmanager_default_options( 'backup_email_from' );
	}
	if ( ! isset( $backup_options['backup_email_from_name'] ) ) {
		$backup_options['backup_email_from_name'] = dbmanager_default_options( 'backup_email_from_name' );
	}
	if ( ! isset( $backup_options['backup_email_subject'] ) ) {
		$backup_options['backup_email_subject'] = dbmanager_default_options( 'backup_email_subject' );
	}
	if ( ! isset( $backup_options['hide_admin_notices'] ) ) {
		$backup_options['hide_admin_notices'] = dbmanager_default_options( 'hide_admin_notices' );
	}
	if ( ! isset( $backup_options['backup_gzip'] ) ) {
		$backup_options['backup_gzip'] = dbmanager_default_options( 'backup_gzip' );
	}
	if ( ! isset( $backup_options['backup_email_attach'] ) ) {
		$backup_options['backup_email_attach'] = dbmanager_default_options( 'backup_email_attach' );
	}

	?>
<script type="text/javascript">
/* <![CDATA[*/
	function mysqlpath() {
		document.getElementById("db_mysqlpath").value = <?php echo wp_json_encode( $path['mysql'], JSON_HEX_TAG ); ?>;
	}
	function mysqldumppath() {
		document.getElementById("db_mysqldumppath").value = <?php echo wp_json_encode( $path['mysqldump'], JSON_HEX_TAG ); ?>;
	}
/* ]]> */
</script>
	<?php
	if ( ! empty( $text ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled above from values escaped at interpolation.
		echo $text; }
	?>
<!-- Database Options -->
<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . plugin_basename( __FILE__ ) ) ); ?>">
	<?php wp_nonce_field( 'wp-dbmanager_options' ); ?>
	<div class="wrap">
		<h2><?php esc_html_e( 'Database Options', 'wp-dbmanager' ); ?></h2>
		<h3><?php esc_html_e( 'Paths', 'wp-dbmanager' ); ?></h3>
		<table class="form-table">
			<tr>
				<td width="20%" valign="top"><strong><?php esc_html_e( 'Path To mysqldump:', 'wp-dbmanager' ); ?></strong></td>
				<td width="80%">
					<input type="text" id="db_mysqldumppath" name="db_mysqldumppath" size="60" maxlength="100" value="<?php echo esc_attr( $backup_options['mysqldumppath'] ); ?>" dir="ltr" />&nbsp;&nbsp;<input type="button" value="<?php esc_html_e( 'Auto Detect', 'wp-dbmanager' ); ?>" onclick="mysqldumppath();" />
					<p><?php esc_html_e( 'The absolute path to mysqldump without trailing slash. If unsure, please email your server administrator about this.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Path To mysql:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<input type="text" id="db_mysqlpath" name="db_mysqlpath" size="60" maxlength="100" value="<?php echo esc_attr( $backup_options['mysqlpath'] ); ?>" dir="ltr" />&nbsp;&nbsp;<input type="button" value="<?php esc_html_e( 'Auto Detect', 'wp-dbmanager' ); ?>" onclick="mysqlpath();" />
					<p><?php esc_html_e( 'The absolute path to mysql without trailing slash. If unsure, please email your server administrator about this.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Path To Backup:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<input type="text" name="db_path" size="60" maxlength="200" value="<?php echo esc_attr( $backup_options['path'] ); ?>" dir="ltr" />
					<p><?php esc_html_e( 'The absolute path to your database backup folder without trailing slash. Make sure the folder is writable.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Maximum Backup Files:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<input type="text" name="db_max_backup" size="5" maxlength="5" value="<?php echo esc_attr( $backup_options['max_backup'] ); ?>" />
					<p><?php esc_html_e( 'The maximum number of database backup files that is allowed in the backup folder as stated above. The oldest database backup file is always deleted in order to maintain this value. This is to prevent the backup folder from getting too large.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Note', 'wp-dbmanager' ); ?></h3>
		<table class="form-table">
			<tr>
				<td>
					<strong><?php esc_html_e( 'Windows Server', 'wp-dbmanager' ); ?></strong><br />
					<?php esc_html_e( 'For mysqldump path, you can try \'<strong>mysqldump.exe</strong>\'.', 'wp-dbmanager' ); ?><br />
					<?php esc_html_e( 'For mysql path, you can try \'<strong>mysql.exe</strong>\'.', 'wp-dbmanager' ); ?>
				</td>
			</tr>
			<tr>
				<td>
					<strong><?php esc_html_e( 'Linux Server', 'wp-dbmanager' ); ?></strong><br />
					<?php esc_html_e( 'For mysqldump path, normally is just \'<strong>mysqldump</strong>\'.', 'wp-dbmanager' ); ?><br />
					<?php esc_html_e( 'For mysql path, normally is just \'<strong>mysql</strong>\'.', 'wp-dbmanager' ); ?>
				</td>
			</tr>
			<tr>
				<td>
					<strong><?php esc_html_e( 'Note', 'wp-dbmanager' ); ?></strong><br />
					<?php esc_html_e( 'The \'Auto Detect\' function does not work for some servers. If it does not work for you, please contact your server administrator for the MYSQL and MYSQL DUMP paths.', 'wp-dbmanager' ); ?>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Automatic Scheduling', 'wp-dbmanager' ); ?></h3>
		<table class="form-table">
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Automatic Backing Up Of DB:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<?php
						esc_html_e( 'Next backup date: ', 'wp-dbmanager' );
					if ( wp_next_scheduled( 'dbmanager_cron_backup' ) ) {
						/* translators: 1: date, 2: time. */
						echo '<strong>' . dbmanager_format_timestamp( wp_next_scheduled( 'dbmanager_cron_backup' ) ) . '</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mysql2date() formats a timestamp, there is no user input in it.
					} else {
						esc_html_e( 'N/A', 'wp-dbmanager' );
					}
					?>
					<p>
						<?php esc_html_e( 'Every', 'wp-dbmanager' ); ?>&nbsp;<input type="text" name="db_backup" size="3" maxlength="5" value="<?php echo esc_attr( $backup_options['backup'] ); ?>" />&nbsp;
						<select name="db_backup_period" size="1">
							<option value="0"<?php selected( '0', $backup_options['backup_period'] ); ?>><?php esc_html_e( 'Disable', 'wp-dbmanager' ); ?></option>
							<option value="60"<?php selected( '60', $backup_options['backup_period'] ); ?>><?php esc_html_e( 'Minutes(s)', 'wp-dbmanager' ); ?></option>
							<option value="3600"<?php selected( '3600', $backup_options['backup_period'] ); ?>><?php esc_html_e( 'Hour(s)', 'wp-dbmanager' ); ?></option>
							<option value="86400"<?php selected( '86400', $backup_options['backup_period'] ); ?>><?php esc_html_e( 'Day(s)', 'wp-dbmanager' ); ?></option>
							<option value="604800"<?php selected( '604800', $backup_options['backup_period'] ); ?>><?php esc_html_e( 'Week(s)', 'wp-dbmanager' ); ?></option>
							<option value="2592000"<?php selected( '2592000', $backup_options['backup_period'] ); ?>><?php esc_html_e( 'Month(s)', 'wp-dbmanager' ); ?></option>
						</select>&nbsp;&nbsp;&nbsp;
						<?php esc_html_e( 'Gzip', 'wp-dbmanager' ); ?>
						<select name="db_backup_gzip" size="1">
							<option value="0"<?php selected( '0', $backup_options['backup_gzip'] ); ?>><?php esc_html_e( 'No', 'wp-dbmanager' ); ?></option>
							<option value="1"<?php selected( '1', $backup_options['backup_gzip'] ); ?>><?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?></option>
						</select>
					</p>
					<p><?php esc_html_e( 'WP-DBManager can automatically backup your database after a certain period.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Automatic Optimizing Of DB:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<?php
						esc_html_e( 'Next optimize date: ', 'wp-dbmanager' );
					if ( wp_next_scheduled( 'dbmanager_cron_optimize' ) ) {
						/* translators: 1: date, 2: time. */
						echo '<strong>' . dbmanager_format_timestamp( wp_next_scheduled( 'dbmanager_cron_optimize' ) ) . '</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mysql2date() formats a timestamp, there is no user input in it.
					} else {
						esc_html_e( 'N/A', 'wp-dbmanager' );
					}
					?>
					<p>
					<?php esc_html_e( 'Every', 'wp-dbmanager' ); ?>&nbsp;<input type="text" name="db_optimize" size="3" maxlength="5" value="<?php echo esc_attr( $backup_options['optimize'] ); ?>" />&nbsp;
					<select name="db_optimize_period" size="1">
						<option value="0"<?php selected( '0', $backup_options['optimize_period'] ); ?>><?php esc_html_e( 'Disable', 'wp-dbmanager' ); ?></option>
						<option value="60"<?php selected( '60', $backup_options['optimize_period'] ); ?>><?php esc_html_e( 'Minutes(s)', 'wp-dbmanager' ); ?></option>
						<option value="3600"<?php selected( '3600', $backup_options['optimize_period'] ); ?>><?php esc_html_e( 'Hour(s)', 'wp-dbmanager' ); ?></option>
						<option value="86400"<?php selected( '86400', $backup_options['optimize_period'] ); ?>><?php esc_html_e( 'Day(s)', 'wp-dbmanager' ); ?></option>
						<option value="604800"<?php selected( '604800', $backup_options['optimize_period'] ); ?>><?php esc_html_e( 'Week(s)', 'wp-dbmanager' ); ?></option>
						<option value="2592000"<?php selected( '2592000', $backup_options['optimize_period'] ); ?>><?php esc_html_e( 'Month(s)', 'wp-dbmanager' ); ?></option>
					</select>
					</p>
					<p><?php esc_html_e( 'WP-DBManager can automatically optimize your database after a certain period.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Automatic Repairing Of DB:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<?php
						esc_html_e( 'Next repair date: ', 'wp-dbmanager' );
					if ( wp_next_scheduled( 'dbmanager_cron_repair' ) ) {
						/* translators: 1: date, 2: time. */
						echo '<strong>' . dbmanager_format_timestamp( wp_next_scheduled( 'dbmanager_cron_repair' ) ) . '</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mysql2date() formats a timestamp, there is no user input in it.
					} else {
						esc_html_e( 'N/A', 'wp-dbmanager' );
					}
					?>
					<p>
					<?php esc_html_e( 'Every', 'wp-dbmanager' ); ?>&nbsp;<input type="text" name="db_repair" size="3" maxlength="5" value="<?php echo esc_attr( $backup_options['repair'] ); ?>" />&nbsp;
					<select name="db_repair_period" size="1">
						<option value="0"<?php selected( '0', $backup_options['repair_period'] ); ?>><?php esc_html_e( 'Disable', 'wp-dbmanager' ); ?></option>
						<option value="60"<?php selected( '60', $backup_options['repair_period'] ); ?>><?php esc_html_e( 'Minutes(s)', 'wp-dbmanager' ); ?></option>
						<option value="3600"<?php selected( '3600', $backup_options['repair_period'] ); ?>><?php esc_html_e( 'Hour(s)', 'wp-dbmanager' ); ?></option>
						<option value="86400"<?php selected( '86400', $backup_options['repair_period'] ); ?>><?php esc_html_e( 'Day(s)', 'wp-dbmanager' ); ?></option>
						<option value="604800"<?php selected( '604800', $backup_options['repair_period'] ); ?>><?php esc_html_e( 'Week(s)', 'wp-dbmanager' ); ?></option>
						<option value="2592000"<?php selected( '2592000', $backup_options['repair_period'] ); ?>><?php esc_html_e( 'Month(s)', 'wp-dbmanager' ); ?></option>
					</select>
					</p>
					<p><?php esc_html_e( 'WP-DBManager can automatically repair your database after a certain period.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Backup Email Options', 'wp-dbmanager' ); ?></h3>
		<table class="form-table">
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'To', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<p>
						<input type="text" name="db_backup_email" size="30" maxlength="250" placeholder="<?php esc_html_e( 'To E-mail', 'wp-dbmanager' ); ?>"  value="<?php echo esc_attr( $backup_options['backup_email'] ); ?>" dir="ltr" />
					</p>
					<p><?php esc_html_e( '(Leave blank to disable this feature)', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Attach Backup File', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<p>
						<input type="radio" id="db_backup_email_attach-yes" name="db_backup_email_attach" value="1"<?php checked( 1, (int) $backup_options['backup_email_attach'] ); ?> />&nbsp;<label for="db_backup_email_attach-yes"><?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?></label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="db_backup_email_attach-no" name="db_backup_email_attach" value="0"<?php checked( 0, (int) $backup_options['backup_email_attach'] ); ?> />&nbsp;<label for="db_backup_email_attach-no"><?php esc_html_e( 'No', 'wp-dbmanager' ); ?></label>
					</p>
					<p><?php esc_html_e( 'Attaches the database backup file to the scheduled backup e-mail. The e-mail always includes the file name, checksum, date and size. Choose \'No\' to keep a copy of your database out of your mailbox.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'From', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<p>
						<input type="text" name="db_backup_email_from_name" size="60" maxlength="250" placeholder="<?php esc_html_e( 'From Name', 'wp-dbmanager' ); ?>" value="<?php echo esc_attr( $backup_options['backup_email_from_name'] ); ?>" dir="ltr" />&nbsp;
						&lt;<input type="text" name="db_backup_email_from" size="30" maxlength="250" placeholder="<?php esc_html_e( 'From E-mail', 'wp-dbmanager' ); ?>"  value="<?php echo esc_attr( $backup_options['backup_email_from'] ); ?>" dir="ltr" />&gt;
					</p>
					<p><?php esc_html_e( '(Leave blank to use the default)', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Subject:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<p>
						<input type="text" name="db_backup_email_subject" size="90" maxlength="255" placeholder="<?php esc_html_e( 'Subject', 'wp-dbmanager' ); ?>"  value="<?php echo esc_attr( $backup_options['backup_email_subject'] ); ?>" dir="ltr" />
					</p>
					<p><?php esc_html_e( '(Leave blank to use the default)', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Miscellaneous Options', 'wp-dbmanager' ); ?></h3>
		<table class="form-table">
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Hide Admin Notices', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<p>
						<input type="radio" name="db_hide_admin_notices" value="1"<?php echo 1 === (int) $backup_options['hide_admin_notices'] ? ' checked="checked"' : ''; ?> />&nbsp;<?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?>
						<input type="radio" name="db_hide_admin_notices" value="0"<?php echo 0 === (int) $backup_options['hide_admin_notices'] ? ' checked="checked"' : ''; ?> />&nbsp;<?php esc_html_e( 'No', 'wp-dbmanager' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<input type="submit" name="Submit" class="button" value="<?php esc_html_e( 'Save Changes', 'wp-dbmanager' ); ?>" />
		</p>
	</div>
</form>
	<?php
}
?>
