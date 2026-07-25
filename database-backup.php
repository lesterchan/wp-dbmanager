<?php
/**
 * Backup Database admin page.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

// Check Whether User Can Manage Database.
if ( ! current_user_can( 'install_plugins' ) ) {
	die( 'Access Denied' );
}

dbmanager_page_backup();

/**
 * Renders the page.
 *
 * Wrapped in a function because admin.php includes this file at global scope,
 * which would otherwise leak every variable below into the global namespace.
 */
function dbmanager_page_backup() {

	// Variables Variables Variables.
	$base_name = plugin_basename( 'wp-dbmanager/database-manager.php' );
	$base_page = 'admin.php?page=' . $base_name;
	/* translators: 1: date, 2: time. */
	$current_date            = dbmanager_format_timestamp( time() );
	$backup                  = array();
	$backup_options          = get_option( 'dbmanager_options' );
	$backup['date']          = time();
	$backup['mysqldumppath'] = $backup_options['mysqldumppath'];
	$backup['mysqlpath']     = $backup_options['mysqlpath'];
	$backup['path']          = $backup_options['path'];
	$backup['charset']       = ' --default-character-set="utf8mb4"';

	$messages = array();

	// Form Processing.
	if ( ! empty( $_POST['do'] ) ) {
		// Verified before any request data is read, not part way down the switch.
		check_admin_referer( 'wp-dbmanager_backup' );

		// Decide What To Do.
		switch ( $_POST['do'] ) {
			case __( 'Backup', 'wp-dbmanager' ):
				$backup['host'] = DB_HOST;
				$backup['port'] = '';
				$backup['sock'] = '';
				if ( strpos( DB_HOST, ':' ) !== false ) {
					$db_host        = explode( ':', DB_HOST );
					$backup['host'] = $db_host[0];
					if ( 0 !== (int) $db_host[1] ) {
						$backup['port'] = ' --port=' . escapeshellarg( (int) $db_host[1] );
					} else {
						$backup['sock'] = ' --socket=' . escapeshellarg( $db_host[1] );
					}
				}
				$gzip               = isset( $_POST['gzip'] ) ? (int) $_POST['gzip'] : 0;
				$backup['filename'] = $backup['date'] . '_-_' . DB_NAME . '.sql';
				$defaults_file      = dbmanager_write_defaults_file();
				if ( 1 === $gzip ) {
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
				if ( ! is_writable( $backup['path'] ) ) {
					$messages[] = array(
						'type' => 'error',
						/* translators: %s: date and time of the attempt. */
						'text' => sprintf( __( 'Database Failed To Backup On \'%s\'. Backup Folder Not Writable.', 'wp-dbmanager' ), $current_date ),
					);
				} elseif ( is_file( $backup['filepath'] ) && filesize( $backup['filepath'] ) === 0 ) {
					$messages[] = array(
						'type' => 'error',
						/* translators: %s: date and time of the attempt. */
						'text' => sprintf( __( 'Database Failed To Backup On \'%s\'. Backup File Size Is 0KB.', 'wp-dbmanager' ), $current_date ),
					);
				} elseif ( ! is_file( $backup['filepath'] ) ) {
					$messages[] = array(
						'type' => 'error',
						/* translators: %s: date and time of the attempt. */
						'text' => sprintf( __( 'Database Failed To Backup On \'%s\'. Invalid Backup File Path.', 'wp-dbmanager' ), $current_date ),
					);
				} elseif ( $error ) {
					$messages[] = array(
						'type' => 'error',
						/* translators: %s: date and time of the attempt. */
						'text' => sprintf( __( 'Database Failed To Backup On \'%s\'.', 'wp-dbmanager' ), $current_date ),
					);
				} else {
					rename( $backup['filepath'], $backup['path'] . '/' . md5_file( $backup['filepath'] ) . '_-_' . $backup['filename'] );
					$messages[] = array(
						'type' => 'success',
						/* translators: %s: date and time of the backup. */
						'text' => sprintf( __( 'Database Backed Up Successfully On \'%s\'.', 'wp-dbmanager' ), $current_date ),
					);
				}
				break;
		}
	}

	// Backup File Name.
	$backup['filename'] = $backup['date'] . '_-_' . DB_NAME . '.sql';
	$backup_path        = $backup['path'];
	$backup_gzip        = isset( $backup_options['backup_gzip'] ) ? (int) $backup_options['backup_gzip'] : dbmanager_default_options( 'backup_gzip' );

	// MYSQL Base Dir.
	$has_error         = false;
	$disabled_function = false;
	?>
	<?php
	dbmanager_render_messages( $messages );
	?>
<!-- Checking Backup Status -->
<div class="wrap">
	<h2><?php esc_html_e( 'Backup Database', 'wp-dbmanager' ); ?></h2>
	<h3><?php esc_html_e( 'Checking Security Status', 'wp-dbmanager' ); ?></h3>
	<p>
		<?php
			$server_type = dbmanager_get_server_type();
			$backup_url  = dbmanager_get_backup_url();

			// Ask the server whether the folder is reachable rather than assuming a
			// dropped in file did the job. On nginx it did not.
			$is_public = dbmanager_is_backup_folder_public();

		if ( false === $backup_url ) {
			printf( '<p style="color: green;">%s</p>', esc_html__( 'Your backup folder is outside the web root, so it cannot be downloaded over HTTP.', 'wp-dbmanager' ) );
		} else {
			/* translators: %s: public URL of the backup folder. */
			printf(
				'<p>%s</p>',
				sprintf(
						/* translators: %s: public URL of the backup folder. */
					esc_html__( 'Your backup folder is inside the web root, at %s', 'wp-dbmanager' ),
					'<strong>' . esc_html( $backup_url ) . '</strong>'
				)
			);

			if ( true === $is_public ) {
				printf( '<p style="color: red; font-weight: bold;">%s</p>', esc_html__( 'The backup folder responds over HTTP. Anyone who guesses a backup file name can download your entire database.', 'wp-dbmanager' ) );
				$has_error = true;
			} elseif ( false === $is_public ) {
				printf( '<p style="color: green;">%s</p>', esc_html__( 'The backup folder does not respond over HTTP.', 'wp-dbmanager' ) );
			} else {
				printf( '<p style="color: red;">%s</p>', esc_html__( 'Could not determine whether the backup folder responds over HTTP. Check it yourself, or move the folder outside the web root.', 'wp-dbmanager' ) );
				$has_error = true;
			}

			if ( 'nginx' === $server_type ) {
				printf( '<p style="color: red;">%s</p>', esc_html__( 'This site runs on nginx, which ignores .htaccess files. No file placed in the backup folder can protect it.', 'wp-dbmanager' ) );
				printf( '<p>%s</p>', esc_html__( 'Move the backup folder outside your web root under DB Options, or add this to your nginx server block:', 'wp-dbmanager' ) );
				$backup_uri = parse_url( $backup_url, PHP_URL_PATH );
				echo '<pre style="background: #f6f7f7; border: 1px solid #dcdcde; padding: 8px; display: inline-block;" dir="ltr">location ^~ ' . esc_html( trailingslashit( $backup_uri ) ) . ' { deny all; }</pre>';
			}

			if ( 'iis' === $server_type ) {
				if ( ! is_file( $backup_path . '/Web.config' ) ) {
					/* translators: %s: backup folder path. */
					printf( '<p style="color: red;">%s</p>', esc_html( sprintf( __( 'Web.config is missing from %s', 'wp-dbmanager' ), $backup_path ) ) );
					$has_error = true;
				} else {
					/* translators: %s: backup folder path. */
					printf( '<p style="color: green;">%s</p>', esc_html( sprintf( __( 'Web.config is present in %s', 'wp-dbmanager' ), $backup_path ) ) );
				}
			} elseif ( 'apache' === $server_type ) {
				if ( ! is_file( $backup_path . '/.htaccess' ) ) {
					/* translators: %s: backup folder path. */
					printf( '<p style="color: red;">%s</p>', esc_html( sprintf( __( '.htaccess is missing from %s', 'wp-dbmanager' ), $backup_path ) ) );
					$has_error = true;
				} else {
					/* translators: %s: backup folder path. */
					printf( '<p style="color: green;">%s</p>', esc_html( sprintf( __( '.htaccess is present in %s', 'wp-dbmanager' ), $backup_path ) ) );
				}
			}
		}

		if ( ! is_file( $backup_path . '/index.php' ) ) {
			/* translators: %s: backup folder path. */
			printf( '<p style="color: red;">%s</p>', esc_html( sprintf( __( 'index.php is missing from %s', 'wp-dbmanager' ), $backup_path ) ) );
			$has_error = true;
		} else {
			/* translators: %s: backup folder path. */
			printf( '<p style="color: green;">%s</p>', esc_html( sprintf( __( 'index.php is present in %s', 'wp-dbmanager' ), $backup_path ) ) );
		}
		?>
	</p>
	<h3><?php esc_html_e( 'Checking Backup Status', 'wp-dbmanager' ); ?></h3>
	<p>
		<?php esc_html_e( 'Checking Backup Folder', 'wp-dbmanager' ); ?> <span dir="ltr">(<strong><?php echo esc_html( $backup_path ); ?></strong>)</span> ...<br />
		<?php
		if ( realpath( $backup_path ) === false ) {
			/* translators: %s: configured backup folder path. */
			printf( '<p style="color: red;">%s</p>', esc_html( sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), $backup_path ) ) );
			$has_error = true;
		} else {
			if ( @is_dir( $backup_path ) ) {
				printf( '<p style="color: green;">%s</p>', esc_html__( 'Backup folder exists', 'wp-dbmanager' ) );
			} else {
				/* translators: %s: wp-content directory path. */
				printf( '<p style="color: red;">%s</p>', esc_html( sprintf( __( 'Backup folder does NOT exist. Please create \'backup-db\' folder in \'%s\' folder and CHMOD it to \'777\' or change the location of the backup folder under DB Option.', 'wp-dbmanager' ), WP_CONTENT_DIR ) ) );
				$has_error = true;
			}
			if ( @is_writable( $backup_path ) ) {
				printf( '<p style="color: green;">%s</p>', esc_html__( 'Backup folder is writable', 'wp-dbmanager' ) );
			} else {
				printf( '<p style="color: red;">%s</p>', esc_html__( 'Backup folder is NOT writable. Please CHMOD it to \'777\'.', 'wp-dbmanager' ) );
				$has_error = true;
			}
		}
		?>
	</p>
	<p>
		<?php
		if ( dbmanager_is_valid_path( $backup['mysqldumppath'] ) === 0 ) {
			/* translators: %s: configured mysqldump path. */
			printf( '<p style="color: red;">%s</p>', esc_html( sprintf( __( '%s is not a valid backup mysqldump path', 'wp-dbmanager' ), $backup['mysqldumppath'] ) ) );
			$has_error = true;
		} elseif ( @file_exists( $backup['mysqldumppath'] ) ) {
				printf(
					'%1$s <span dir="ltr">(<strong>%2$s</strong>)</span> ...<br />',
					esc_html__( 'Checking MYSQL Dump Path', 'wp-dbmanager' ),
					esc_html( $backup['mysqldumppath'] )
				);
				printf( '<p style="color: green;">%s</p>', esc_html__( 'MYSQL dump path exists.', 'wp-dbmanager' ) );
		} else {
			printf( '%s ...<br />', esc_html__( 'Checking MYSQL Dump Path', 'wp-dbmanager' ) );
			printf( '<p style="color: red;">%s</p>', esc_html__( 'MYSQL dump path does NOT exist. Please check your mysqldump path under DB Options. If uncertain, contact your server administrator.', 'wp-dbmanager' ) );
			$has_error = true;
		}
		?>
	</p>
	<p>
		<?php
		if ( dbmanager_is_valid_path( $backup['mysqlpath'] ) === 0 ) {
			/* translators: %s: configured mysql path. */
			printf( '<p style="color: red;">%s</p>', esc_html( sprintf( __( '%s is not a valid backup mysql path', 'wp-dbmanager' ), $backup['mysqlpath'] ) ) );
			$has_error = true;
		} elseif ( @file_exists( $backup['mysqlpath'] ) ) {
				printf(
					'%1$s <span dir="ltr">(<strong>%2$s</strong>)</span> ...<br />',
					esc_html__( 'Checking MYSQL Path', 'wp-dbmanager' ),
					esc_html( $backup['mysqlpath'] )
				);
				printf( '<p style="color: green;">%s</p>', esc_html__( 'MYSQL path exists.', 'wp-dbmanager' ) );
		} else {
			printf( '%s ...<br />', esc_html__( 'Checking MYSQL Path', 'wp-dbmanager' ) );
			printf( '<p style="color: red;">%s</p>', esc_html__( 'MYSQL path does NOT exist. Please check your mysql path under DB Options. If uncertain, contact your server administrator.', 'wp-dbmanager' ) );
			$has_error = true;
		}
		?>
	</p>
	<p>
		<?php esc_html_e( 'Checking PHP Functions', 'wp-dbmanager' ); ?> <span dir="ltr">(<strong>passthru()</strong>, <strong>system()</strong> <?php esc_html_e( 'and', 'wp-dbmanager' ); ?> <strong>exec()</strong>)</span> ...<br />
		<?php
		if ( dbmanager_is_function_disabled( 'passthru' ) ) {
			printf( '<p style="color: red;"><span dir="ltr">passthru()</span> %s.</p>', esc_html__( 'disabled', 'wp-dbmanager' ) );
			$disabled_function = true;
		} elseif ( ! function_exists( 'passthru' ) ) {
			printf( '<p style="color: red;"><span dir="ltr">passthru()</span> %s.</p>', esc_html__( 'missing', 'wp-dbmanager' ) );
			$disabled_function = true;
		} else {
			printf( '<p style="color: green;"><span dir="ltr">passthru()</span> %s.</p>', esc_html__( 'enabled', 'wp-dbmanager' ) );
		}
		if ( dbmanager_is_function_disabled( 'system' ) ) {
			printf( '<p style="color: red;"><span dir="ltr">system()</span> %s.</p>', esc_html__( 'disabled', 'wp-dbmanager' ) );
			$disabled_function = true;
		} elseif ( ! function_exists( 'system' ) ) {
			printf( '<p style="color: red;"><span dir="ltr">system()</span> %s.</p>', esc_html__( 'missing', 'wp-dbmanager' ) );
			$disabled_function = true;
		} else {
			printf( '<p style="color: green;"><span dir="ltr">system()</span> %s.</p>', esc_html__( 'enabled', 'wp-dbmanager' ) );
		}
		if ( dbmanager_is_function_disabled( 'exec' ) ) {
			printf( '<p style="color: red;"><span dir="ltr">exec()</span> %s.</p>', esc_html__( 'disabled', 'wp-dbmanager' ) );
			$disabled_function = true;
		} elseif ( ! function_exists( 'exec' ) ) {
			printf( '<p style="color: red;"><span dir="ltr">exec()</span> %s.</p>', esc_html__( 'missing', 'wp-dbmanager' ) );
			$disabled_function = true;
		} else {
			printf( '<p style="color: green;"><span dir="ltr">exec()</span> %s.</p>', esc_html__( 'enabled', 'wp-dbmanager' ) );
		}
		?>
	</p>
	<p>
		<?php
		if ( $disabled_function ) {
			printf( '<strong><p style="color: red;">%s</p></strong>', esc_html__( 'I\'m sorry, your server administrator has disabled passthru(), system() and/or exec(), thus you cannot use this plugin. Please find an alternative plugin.', 'wp-dbmanager' ) );
		} elseif ( ! $has_error ) {
			printf( '<strong><p style="color: green;">%s</p></strong>', esc_html__( 'Excellent. You Are Good To Go.', 'wp-dbmanager' ) );
		} else {
			printf( '<strong><p style="color: red;">%s</p></strong>', esc_html__( 'Please Rectify The Error Highlighted In Red Before Proceeding On.', 'wp-dbmanager' ) );
		}
		?>
	</p>
	<p><i><?php esc_html_e( 'Note: The checking of backup status is still undergoing testing, it may not be accurate.', 'wp-dbmanager' ); ?></i></p>
</div>
<!-- Backup Database -->
<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . plugin_basename( __FILE__ ) ) ); ?>">
	<?php wp_nonce_field( 'wp-dbmanager_backup' ); ?>
	<div class="wrap">
		<h3><?php esc_html_e( 'Backup Database', 'wp-dbmanager' ); ?></h3>
		<br style="clear" />
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Option', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'Value', 'wp-dbmanager' ); ?></th>
				</tr>
			</thead>
			<tr>
				<th><?php esc_html_e( 'Database Name:', 'wp-dbmanager' ); ?></th>
				<td><?php echo esc_html( DB_NAME ); ?></td>
			</tr>
			<tr style="background-color: #eee;">
				<th><?php esc_html_e( 'Database Backup To:', 'wp-dbmanager' ); ?></th>
				<td><span dir="ltr"><?php echo esc_html( $backup_path ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Database Backup Date:', 'wp-dbmanager' ); ?></th>
				<td><?php echo esc_html( dbmanager_format_timestamp( $backup['date'] ) ); ?></td>
			</tr>
			<tr style="background-color: #eee;">
				<th><?php esc_html_e( 'Database Backup File Name:', 'wp-dbmanager' ); ?></th>
				<td><span dir="ltr"><?php echo esc_html( $backup['filename'] ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Database Backup Type:', 'wp-dbmanager' ); ?></th>
				<td><?php esc_html_e( 'Full (Structure and Data)', 'wp-dbmanager' ); ?></td>
			</tr>
			<tr style="background-color: #eee;">
				<th><?php esc_html_e( 'MYSQL Dump Location:', 'wp-dbmanager' ); ?></th>
				<td><span dir="ltr"><?php echo esc_html( $backup['mysqldumppath'] ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'GZIP Database Backup File?', 'wp-dbmanager' ); ?></th>
				<td><input type="radio" id="gzip-yes" name="gzip" value="1"<?php checked( 1, $backup_gzip ); ?> />&nbsp;<label for="gzip-yes"><?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?></label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="gzip-no" name="gzip" value="0"<?php checked( 0, $backup_gzip ); ?> />&nbsp;<label for="gzip-no"><?php esc_html_e( 'No', 'wp-dbmanager' ); ?></label></td>
			</tr>
			<tr>
				<td colspan="2" align="center"><input type="submit" name="do" value="<?php esc_html_e( 'Backup', 'wp-dbmanager' ); ?>" class="button" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php esc_html_e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" onclick="javascript:history.go(-1)" /></td>
			</tr>
		</table>
	</div>
</form>

	<?php
}
