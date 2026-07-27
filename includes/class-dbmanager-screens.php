<?php
/**
 * The seven admin screens.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Database screens.
 *
 * Each of these was a standalone PHP file included by admin.php at global
 * scope, which is why the pre-3.0.0 versions each wrapped themselves in a
 * function to stop their locals leaking into the global namespace. They are
 * ordinary menu callbacks now, and the work they used to do inline - building
 * shell commands, validating table names - has moved to the domain classes.
 *
 * @since 3.0.0
 */
class DBManager_Screens {

	/**
	 * The Database information screen.
	 *
	 * @return void
	 */
	public static function manager() {
		DBManager_Admin::check_capability();

		$version = DBManager_Tables::version();
		?>
<!-- Database Information -->
<div class="wrap">
	<h2><?php esc_html_e( 'Database', 'wp-dbmanager' ); ?></h2>
	<h3><?php esc_html_e( 'Database Information', 'wp-dbmanager' ); ?></h3>
	<br style="clear" />
	<table class="widefat">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Setting', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Value', 'wp-dbmanager' ); ?></th>
			</tr>
		</thead>
		<tr>
			<td><?php esc_html_e( 'Database Host', 'wp-dbmanager' ); ?></td>
			<td><?php echo esc_html( DB_HOST ); ?></td>
		</tr>
		<tr class="alternate">
			<td><?php esc_html_e( 'Database Name', 'wp-dbmanager' ); ?></td>
			<td><?php echo esc_html( DB_NAME ); ?></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Database User', 'wp-dbmanager' ); ?></td>
			<td><?php echo esc_html( DB_USER ); ?></td>
		</tr>
		<tr class="alternate">
			<td><?php esc_html_e( 'Database Type', 'wp-dbmanager' ); ?></td>
			<td>MYSQL</td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Database Version', 'wp-dbmanager' ); ?></td>
			<td>v<?php echo esc_html( $version ); ?></td>
		</tr>
	</table>
</div>
<p>&nbsp;</p>

<div class="wrap">
	<h3><?php esc_html_e( 'Tables Information', 'wp-dbmanager' ); ?></h3>
	<br style="clear" />
	<table class="widefat">
		<thead>
			<tr>
				<th><?php esc_html_e( 'No.', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Tables', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Records', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Data Usage', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Index Usage', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Overhead', 'wp-dbmanager' ); ?></th>
			</tr>
		</thead>
		<?php
		$no             = 0;
		$row_usage      = 0;
		$data_usage     = 0;
		$index_usage    = 0;
		$overhead_usage = 0;

		foreach ( DBManager_Tables::status() as $table ) {
			$style = ( 0 === $no % 2 ) ? '' : 'alternate';
			++$no;

			printf(
				"<tr class=\"%1\$s\"><td>%2\$s</td><td>%3\$s</td><td>%4\$s</td><td>%5\$s</td><td>%6\$s</td><td>%7\$s</td></tr>\n",
				esc_attr( $style ),
				esc_html( number_format_i18n( $no ) ),
				esc_html( $table->Name ),
				esc_html( number_format_i18n( $table->Rows ) ),
				esc_html( DBManager_Backups::format_size( $table->Data_length ) ),
				esc_html( DBManager_Backups::format_size( $table->Index_length ) ),
				esc_html( DBManager_Backups::format_size( $table->Data_free ) )
			);

			$row_usage      += $table->Rows;
			$data_usage     += $table->Data_length;
			$index_usage    += $table->Index_length;
			$overhead_usage += $table->Data_free;
		}

		/* translators: %s: number of tables. */
		$total_tables = sprintf( _n( '%s Table', '%s Tables', $no, 'wp-dbmanager' ), number_format_i18n( $no ) );
		/* translators: %s: number of records. */
		$total_records = sprintf( _n( '%s Record', '%s Records', $row_usage, 'wp-dbmanager' ), number_format_i18n( $row_usage ) );

		printf(
			"<tr class=\"thead\"><th>%1\$s</th><th>%2\$s</th><th>%3\$s</th><th>%4\$s</th><th>%5\$s</th><th>%6\$s</th></tr>\n",
			esc_html__( 'Total:', 'wp-dbmanager' ),
			esc_html( $total_tables ),
			esc_html( $total_records ),
			esc_html( DBManager_Backups::format_size( $data_usage ) ),
			esc_html( DBManager_Backups::format_size( $index_usage ) ),
			esc_html( DBManager_Backups::format_size( $overhead_usage ) )
		);
		?>
	</table>
</div>
		<?php
	}

	/**
	 * The Backup Database screen.
	 *
	 * @return void
	 */
	public static function backup() {
		DBManager_Admin::check_capability();

		$options      = DBManager_Options::get();
		$current_date = DBManager::format_timestamp( time() );
		$backup_path  = $options['path'];
		$messages     = array();

		$action = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( '' !== $action ) {
			// Verified before any request data is read, not part way down the switch.
			check_admin_referer( 'wp-dbmanager_backup' );

			if ( __( 'Backup', 'wp-dbmanager' ) === $action ) {
				$gzip   = isset( $_POST['gzip'] ) ? 1 === (int) $_POST['gzip'] : false;
				$result = DBManager_Database::backup( $gzip );

				$failures = array(
					/* translators: %s: date and time of the attempt. */
					'path'    => __( 'Database Failed To Backup On \'%s\'. Backup Folder Not Writable.', 'wp-dbmanager' ),
					/* translators: %s: date and time of the attempt. */
					'empty'   => __( 'Database Failed To Backup On \'%s\'. Backup File Size Is 0KB.', 'wp-dbmanager' ),
					/* translators: %s: date and time of the attempt. */
					'missing' => __( 'Database Failed To Backup On \'%s\'. Invalid Backup File Path.', 'wp-dbmanager' ),
					/* translators: %s: date and time of the attempt. */
					'command' => __( 'Database Failed To Backup On \'%s\'.', 'wp-dbmanager' ),
				);

				if ( $result['success'] ) {
					$messages[] = array(
						'type' => 'success',
						/* translators: %s: date and time of the backup. */
						'text' => sprintf( __( 'Database Backed Up Successfully On \'%s\'.', 'wp-dbmanager' ), $current_date ),
					);
				} else {
					$reason     = isset( $failures[ $result['reason'] ] ) ? $failures[ $result['reason'] ] : $failures['command'];
					$messages[] = array(
						'type' => 'error',
						'text' => sprintf( $reason, $current_date ),
					);
				}
			}
		}

		$backup_date     = time();
		$backup_filename = $backup_date . '_-_' . DB_NAME . '.sql';
		$backup_gzip     = (int) $options['backup_gzip'];

		$has_error         = false;
		$disabled_function = false;

		DBManager_Admin::render_messages( $messages );
		?>
<!-- Checking Backup Status -->
<div class="wrap">
	<h2><?php esc_html_e( 'Backup Database', 'wp-dbmanager' ); ?></h2>
	<h3><?php esc_html_e( 'Checking Security Status', 'wp-dbmanager' ); ?></h3>
	<p>
		<?php
		$server_type = DBManager_Folder::server_type();
		$backup_url  = DBManager_Folder::url();

		// Ask the server whether the folder is reachable, rather than assuming a
		// dropped in file did the job. On nginx it did not.
		$is_public = DBManager_Folder::is_public();

		if ( false === $backup_url ) {
			printf( '<p style="color: green;">%s</p>', esc_html__( 'Your backup folder is outside the web root, so it cannot be downloaded over HTTP.', 'wp-dbmanager' ) );
		} else {
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
				$backup_uri = wp_parse_url( $backup_url, PHP_URL_PATH );
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
		if ( DBManager_Database::is_valid_path( $options['mysqldumppath'] ) === 0 ) {
			/* translators: %s: configured mysqldump path. */
			printf( '<p style="color: red;">%s</p>', esc_html( sprintf( __( '%s is not a valid backup mysqldump path', 'wp-dbmanager' ), $options['mysqldumppath'] ) ) );
			$has_error = true;
		} elseif ( @file_exists( $options['mysqldumppath'] ) ) {
			printf(
				'%1$s <span dir="ltr">(<strong>%2$s</strong>)</span> ...<br />',
				esc_html__( 'Checking MYSQL Dump Path', 'wp-dbmanager' ),
				esc_html( $options['mysqldumppath'] )
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
		if ( DBManager_Database::is_valid_path( $options['mysqlpath'] ) === 0 ) {
			/* translators: %s: configured mysql path. */
			printf( '<p style="color: red;">%s</p>', esc_html( sprintf( __( '%s is not a valid backup mysql path', 'wp-dbmanager' ), $options['mysqlpath'] ) ) );
			$has_error = true;
		} elseif ( @file_exists( $options['mysqlpath'] ) ) {
			printf(
				'%1$s <span dir="ltr">(<strong>%2$s</strong>)</span> ...<br />',
				esc_html__( 'Checking MYSQL Path', 'wp-dbmanager' ),
				esc_html( $options['mysqlpath'] )
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
		foreach ( array( 'passthru', 'system', 'exec' ) as $function_name ) {
			if ( DBManager_Database::is_function_disabled( $function_name ) ) {
				printf(
					'<p style="color: red;"><span dir="ltr">%1$s()</span> %2$s.</p>',
					esc_html( $function_name ),
					esc_html__( 'disabled', 'wp-dbmanager' )
				);
				$disabled_function = true;
			} elseif ( ! function_exists( $function_name ) ) {
				printf(
					'<p style="color: red;"><span dir="ltr">%1$s()</span> %2$s.</p>',
					esc_html( $function_name ),
					esc_html__( 'missing', 'wp-dbmanager' )
				);
				$disabled_function = true;
			} else {
				printf(
					'<p style="color: green;"><span dir="ltr">%1$s()</span> %2$s.</p>',
					esc_html( $function_name ),
					esc_html__( 'enabled', 'wp-dbmanager' )
				);
			}
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
<form method="post" action="<?php echo esc_url( DBManager_Admin::page_url( 'backup' ) ); ?>">
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
				<td><?php echo esc_html( DBManager::format_timestamp( $backup_date ) ); ?></td>
			</tr>
			<tr style="background-color: #eee;">
				<th><?php esc_html_e( 'Database Backup File Name:', 'wp-dbmanager' ); ?></th>
				<td><span dir="ltr"><?php echo esc_html( $backup_filename ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Database Backup Type:', 'wp-dbmanager' ); ?></th>
				<td><?php esc_html_e( 'Full (Structure and Data)', 'wp-dbmanager' ); ?></td>
			</tr>
			<tr style="background-color: #eee;">
				<th><?php esc_html_e( 'MYSQL Dump Location:', 'wp-dbmanager' ); ?></th>
				<td><span dir="ltr"><?php echo esc_html( $options['mysqldumppath'] ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'GZIP Database Backup File?', 'wp-dbmanager' ); ?></th>
				<td><input type="radio" id="gzip-yes" name="gzip" value="1"<?php checked( 1, $backup_gzip ); ?> />&nbsp;<label for="gzip-yes"><?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?></label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="gzip-no" name="gzip" value="0"<?php checked( 0, $backup_gzip ); ?> />&nbsp;<label for="gzip-no"><?php esc_html_e( 'No', 'wp-dbmanager' ); ?></label></td>
			</tr>
			<tr>
				<td colspan="2" align="center"><input type="submit" name="do" value="<?php esc_attr_e( 'Backup', 'wp-dbmanager' ); ?>" class="button" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php esc_attr_e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" data-dbmanager-back="1" /></td>
			</tr>
		</table>
	</div>
</form>
		<?php
	}

	/**
	 * The Manage Backup Database screen.
	 *
	 * @return void
	 */
	public static function manage() {
		DBManager_Admin::check_capability();

		$options  = DBManager_Options::get();
		$messages = array();

		$action = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( '' !== $action ) {
			check_admin_referer( 'wp-dbmanager_manage' );

			$database_file = ! empty( $_POST['database_file'] ) ? sanitize_file_name( wp_unslash( $_POST['database_file'] ) ) : '';
			$file          = DBManager_Backups::parse_filename( $database_file );

			$none_selected = array(
				'type' => 'error',
				'text' => __( 'No Backup Database File Selected', 'wp-dbmanager' ),
			);

			switch ( $action ) {
				case __( 'Restore', 'wp-dbmanager' ):
					if ( empty( $database_file ) ) {
						$messages[] = $none_selected;
						break;
					}

					$result = DBManager_Database::restore( $database_file );

					foreach ( $result['errors'] as $error ) {
						$messages[] = array(
							'type' => 'error',
							'text' => $error,
						);
					}

					// Only report on the restore when it actually ran; the path
					// errors above already explain why it did not.
					if ( $result['ran'] ) {
						$messages[] = array(
							'type' => $result['error'] ? 'error' : 'success',
							'text' => $result['error']
								/* translators: %s: date and time of the backup file. */
								? sprintf( __( 'Database On \'%s\' Failed To Restore', 'wp-dbmanager' ), $file['formatted_date'] )
								/* translators: %s: date and time of the backup file. */
								: sprintf( __( 'Database On \'%s\' Restored Successfully', 'wp-dbmanager' ), $file['formatted_date'] ),
						);
					}
					break;

				case __( 'E-Mail', 'wp-dbmanager' ):
					if ( empty( $database_file ) ) {
						$messages[] = $none_selected;
						break;
					}

					$to = ! empty( $_POST['email_to'] ) ? sanitize_email( wp_unslash( $_POST['email_to'] ) ) : get_option( 'admin_email' );

					if ( DBManager_Mailer::send( $to, $options['path'] . '/' . $database_file ) ) {
						$messages[] = array(
							'type' => 'success',
							/* translators: 1: date and time of the backup file, 2: e-mail address. */
							'text' => sprintf( __( 'Database Backup File For \'%1$s\' Successfully E-Mailed To \'%2$s\'', 'wp-dbmanager' ), $file['formatted_date'], $to ),
						);
					} else {
						$messages[] = array(
							'type' => 'error',
							/* translators: 1: date and time of the backup file, 2: e-mail address. */
							'text' => sprintf( __( 'Unable To E-Mail Database Backup File For \'%1$s\' To \'%2$s\'', 'wp-dbmanager' ), $file['formatted_date'], $to ),
						);
					}
					break;

				case __( 'Download', 'wp-dbmanager' ):
					// A real download exits during init; reaching here means there
					// was nothing selected to download.
					$messages[] = $none_selected;
					break;

				case __( 'Delete', 'wp-dbmanager' ):
					if ( empty( $database_file ) ) {
						$messages[] = $none_selected;
						break;
					}

					if ( ! is_file( $options['path'] . '/' . $database_file ) ) {
						$messages[] = array(
							'type' => 'error',
							/* translators: %s: date and time of the backup file. */
							'text' => sprintf( __( 'Invalid Database Backup File On \'%s\'', 'wp-dbmanager' ), $file['formatted_date'] ),
						);
						break;
					}

					// wp_delete_file() returns nothing, so ask the filesystem
					// whether it actually worked.
					wp_delete_file( $options['path'] . '/' . $database_file );

					$messages[] = array(
						'type' => file_exists( $options['path'] . '/' . $database_file ) ? 'error' : 'success',
						'text' => file_exists( $options['path'] . '/' . $database_file )
							/* translators: %s: date and time of the backup file. */
							? sprintf( __( 'Unable To Delete Database Backup File On \'%s\'', 'wp-dbmanager' ), $file['formatted_date'] )
							/* translators: %s: date and time of the backup file. */
							: sprintf( __( 'Database Backup File On \'%s\' Deleted Successfully', 'wp-dbmanager' ), $file['formatted_date'] ),
					);
					break;
			}
		}

		DBManager_Admin::render_messages( $messages );

		$restore_warning = __( 'You Are About To Restore A Database.\nThis Action Is Not Reversible.\nAny Data Inserted After The Backup Date Will Be Gone.\n\n Choose [Cancel] to stop, [Ok] to restore.', 'wp-dbmanager' );
		$delete_warning  = __( 'You Are About To Delete The Selected Database Backup Files.\nThis Action Is Not Reversible.\n\n Choose [Cancel] to stop, [Ok] to delete.', 'wp-dbmanager' );
		?>
<!-- Manage Backup Database -->
<form method="post" action="<?php echo esc_url( DBManager_Admin::page_url( 'manage' ) ); ?>">
		<?php wp_nonce_field( 'wp-dbmanager_manage' ); ?>
	<div class="wrap">
		<h2><?php esc_html_e( 'Manage Backup Database', 'wp-dbmanager' ); ?></h2>
		<p><?php esc_html_e( 'Choose A Backup Date To E-Mail, Restore, Download Or Delete', 'wp-dbmanager' ); ?></p>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'No.', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'MD5 Checksum', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'Database File', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'Date/Time', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'Size', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'Select', 'wp-dbmanager' ); ?></th>
				</tr>
			</thead>
			<?php
			$no        = 0;
			$totalsize = 0;
			$files     = array_reverse( DBManager_Backups::all( $options['path'] ) );

			if ( ! empty( $files ) ) {
				foreach ( $files as $entry ) {
					$style = ( 0 === $no % 2 ) ? '' : 'alternate';
					++$no;

					$file = DBManager_Backups::parse_file( $options['path'] . '/' . $entry['name'] );

					printf(
						'<tr class="%1$s"><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td>',
						esc_attr( $style ),
						esc_html( number_format_i18n( $no ) ),
						esc_html( $file['checksum'] ),
						esc_html( $file['database'] ),
						esc_html( $file['formatted_date'] ),
						esc_html( $file['formatted_size'] )
					);
					printf(
						'<td><input type="radio" name="database_file" value="%s" /></td></tr>',
						esc_attr( $entry['name'] )
					);

					$totalsize += $file['size'];
				}
			} else {
				printf(
					'<tr><td align="center" colspan="6">%s</td></tr>',
					esc_html__( 'There Are No Database Backup Files Available.', 'wp-dbmanager' )
				);
			}
			?>
			<tr class="thead">
				<?php /* translators: %s: number of backup files. */ ?>
				<th colspan="4"><?php printf( esc_html( _n( '%s Backup File', '%s Backup Files', $no, 'wp-dbmanager' ) ), esc_html( number_format_i18n( $no ) ) ); ?></th>
				<th><?php echo esc_html( DBManager_Backups::format_size( $totalsize ) ); ?></th>
				<th>&nbsp;</th>
			</tr>
		</table>
		<table class="form-table">
			<tr>
				<td colspan="5" align="center"><label for="email_to"><?php esc_html_e( 'E-mail database backup file to:', 'wp-dbmanager' ); ?></label> <input type="text" id="email_to" name="email_to" size="30" maxlength="50" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" dir="ltr" />&nbsp;&nbsp;<input type="submit" name="do" value="<?php esc_attr_e( 'E-Mail', 'wp-dbmanager' ); ?>" class="button" /></td>
			</tr>
			<tr>
				<td colspan="5" align="center">
					<input type="submit" name="do" value="<?php esc_attr_e( 'Download', 'wp-dbmanager' ); ?>" class="button" />&nbsp;&nbsp;
					<input type="submit" name="do" value="<?php esc_attr_e( 'Restore', 'wp-dbmanager' ); ?>" data-dbmanager-confirm="<?php echo esc_attr( $restore_warning ); ?>" class="button" />&nbsp;&nbsp;
					<input type="submit" class="button" name="do" value="<?php esc_attr_e( 'Delete', 'wp-dbmanager' ); ?>" data-dbmanager-confirm="<?php echo esc_attr( $delete_warning ); ?>" />&nbsp;&nbsp;
					<input type="button" name="cancel" value="<?php esc_attr_e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" data-dbmanager-back="1" /></td>
			</tr>
		</table>
	</div>
</form>
		<?php
	}

	/**
	 * The Optimize Database screen.
	 *
	 * @return void
	 */
	public static function optimize() {
		DBManager_Admin::check_capability();

		$messages = array();

		$action = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( '' !== $action ) {
			// Verified before any request data is read, not part way down the switch.
			check_admin_referer( 'wp-dbmanager_optimize' );

			if ( __( 'Optimize', 'wp-dbmanager' ) === $action ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys are table names, validated against SHOW TABLES by filter().
				$submitted = ! empty( $_POST['optimize'] ) ? wp_unslash( $_POST['optimize'] ) : array();
				$tables    = DBManager_Tables::filter( $submitted, 'yes' );

				if ( empty( $tables ) ) {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'No Tables Selected', 'wp-dbmanager' ),
					);
				} elseif ( DBManager_Tables::optimize( $tables ) ) {
					$messages[] = array(
						'type' => 'success',
						/* translators: %s: comma separated table names. */
						'text' => sprintf( __( 'Table(s) \'%s\' Optimized', 'wp-dbmanager' ), implode( ', ', $tables ) ),
					);
				} else {
					$messages[] = array(
						'type' => 'error',
						/* translators: %s: comma separated table names. */
						'text' => sprintf( __( 'Table(s) \'%s\' NOT Optimized', 'wp-dbmanager' ), implode( ', ', $tables ) ),
					);
				}
			}
		}

		DBManager_Admin::render_messages( $messages );
		?>
<!-- Optimize Database -->
<form method="post" action="<?php echo esc_url( DBManager_Admin::page_url( 'optimize' ) ); ?>">
		<?php wp_nonce_field( 'wp-dbmanager_optimize' ); ?>
	<div class="wrap">
		<h2><?php esc_html_e( 'Optimize Database', 'wp-dbmanager' ); ?></h2>
		<br style="clear" />
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tables', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'Options', 'wp-dbmanager' ); ?></th>
				</tr>
			</thead>
			<?php
			$no = 0;

			foreach ( DBManager_Tables::all() as $table_name ) {
				$style = ( 0 === $no % 2 ) ? '' : 'alternate';
				++$no;

				printf(
					'<tr class="%1$s"><th align="left" scope="row">%2$s</th>',
					esc_attr( $style ),
					esc_html( $table_name )
				);
				printf(
					'<td><input type="radio" id="%1$s-no" name="optimize[%1$s]" value="no" />&nbsp;<label for="%1$s-no">%2$s</label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="%1$s-yes" name="optimize[%1$s]" value="yes" checked="checked" />&nbsp;<label for="%1$s-yes">%3$s</label></td></tr>',
					esc_attr( $table_name ),
					esc_html__( 'No', 'wp-dbmanager' ),
					esc_html__( 'Yes', 'wp-dbmanager' )
				);
			}
			?>
			<tr>
				<td colspan="2" align="center"><?php esc_html_e( 'Database should be optimized once every month.', 'wp-dbmanager' ); ?></td>
			</tr>
			<tr>
				<td colspan="2" align="center"><input type="submit" name="do" value="<?php esc_attr_e( 'Optimize', 'wp-dbmanager' ); ?>" class="button" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php esc_attr_e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" data-dbmanager-back="1" /></td>
			</tr>
		</table>
	</div>
</form>
		<?php
	}

	/**
	 * The Repair Database screen.
	 *
	 * @return void
	 */
	public static function repair() {
		DBManager_Admin::check_capability();

		$messages = array();

		$action = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( '' !== $action ) {
			// Verified before any request data is read, not part way down the switch.
			check_admin_referer( 'wp-dbmanager_repair' );

			if ( __( 'Repair', 'wp-dbmanager' ) === $action ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys are table names, validated against SHOW TABLES by filter().
				$submitted = ! empty( $_POST['repair'] ) ? wp_unslash( $_POST['repair'] ) : array();
				$tables    = DBManager_Tables::filter( $submitted, 'yes' );

				if ( empty( $tables ) ) {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'No Tables Selected', 'wp-dbmanager' ),
					);
				} elseif ( DBManager_Tables::repair( $tables ) ) {
					$messages[] = array(
						'type' => 'success',
						/* translators: %s: comma separated table names. */
						'text' => sprintf( __( 'Table(s) \'%s\' Repaired', 'wp-dbmanager' ), implode( ', ', $tables ) ),
					);
				} else {
					$messages[] = array(
						'type' => 'error',
						/* translators: %s: comma separated table names. */
						'text' => sprintf( __( 'Table(s) \'%s\' NOT Repaired', 'wp-dbmanager' ), implode( ', ', $tables ) ),
					);
				}
			}
		}

		DBManager_Admin::render_messages( $messages );
		?>
<!-- Repair Database -->
<form method="post" action="<?php echo esc_url( DBManager_Admin::page_url( 'repair' ) ); ?>">
		<?php wp_nonce_field( 'wp-dbmanager_repair' ); ?>
	<div class="wrap">
		<h2><?php esc_html_e( 'Repair Database', 'wp-dbmanager' ); ?></h2>
		<br style="clear" />
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tables', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'Options', 'wp-dbmanager' ); ?></th>
				</tr>
			</thead>
			<?php
			$no = 0;

			foreach ( DBManager_Tables::all() as $table_name ) {
				$style = ( 0 === $no % 2 ) ? '' : 'alternate';
				++$no;

				printf(
					'<tr class="%1$s"><th align="left" scope="row">%2$s</th>',
					esc_attr( $style ),
					esc_html( $table_name )
				);
				printf(
					'<td><input type="radio" id="%1$s-no" name="repair[%1$s]" value="no" />&nbsp;<label for="%1$s-no">%2$s</label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="%1$s-yes" name="repair[%1$s]" value="yes" checked="checked" />&nbsp;<label for="%1$s-yes">%3$s</label></td></tr>',
					esc_attr( $table_name ),
					esc_html__( 'No', 'wp-dbmanager' ),
					esc_html__( 'Yes', 'wp-dbmanager' )
				);
			}
			?>
			<tr>
				<td colspan="2" align="center"><input type="submit" name="do" value="<?php esc_attr_e( 'Repair', 'wp-dbmanager' ); ?>" class="button" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php esc_attr_e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" data-dbmanager-back="1" /></td>
			</tr>
		</table>
	</div>
</form>
		<?php
	}

	/**
	 * The Empty/Drop Tables screen.
	 *
	 * @return void
	 */
	public static function empty_tables() {
		DBManager_Admin::check_capability();

		$messages = array();

		$action = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( '' !== $action ) {
			// Verified before any request data is read, not part way down the switch.
			check_admin_referer( 'wp-dbmanager_empty' );

			if ( __( 'Empty/Drop', 'wp-dbmanager' ) === $action ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys are table names, validated against SHOW TABLES by filter().
				$submitted = ! empty( $_POST['emptydrop'] ) ? wp_unslash( $_POST['emptydrop'] ) : array();

				$empty_tables = DBManager_Tables::filter( $submitted, 'empty' );
				$drop_tables  = DBManager_Tables::filter( $submitted, 'drop' );

				if ( empty( $empty_tables ) && empty( $drop_tables ) ) {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'No Tables Selected.', 'wp-dbmanager' ),
					);
				}

				foreach ( $empty_tables as $empty_table ) {
					DBManager_Tables::truncate( $empty_table );

					$messages[] = array(
						'type' => 'success',
						/* translators: %s: table name. */
						'text' => sprintf( __( 'Table \'%s\' Emptied', 'wp-dbmanager' ), $empty_table ),
					);
				}

				if ( ! empty( $drop_tables ) ) {
					DBManager_Tables::drop( $drop_tables );

					$messages[] = array(
						'type' => 'success',
						/* translators: %s: comma separated table names. */
						'text' => sprintf( __( 'Table(s) \'%s\' Dropped', 'wp-dbmanager' ), implode( ', ', $drop_tables ) ),
					);
				}
			}
		}

		DBManager_Admin::render_messages( $messages );

		$warning = __( 'You Are About To Empty Or Drop The Selected Databases.\nThis Action Is Not Reversible.\n\n Choose [Cancel] to stop, [Ok] to delete.', 'wp-dbmanager' );
		?>
<!-- Empty/Drop Tables -->
<form method="post" action="<?php echo esc_url( DBManager_Admin::page_url( 'empty' ) ); ?>">
		<?php wp_nonce_field( 'wp-dbmanager_empty' ); ?>
	<div class="wrap">
		<h2><?php esc_html_e( 'Empty/Drop Tables', 'wp-dbmanager' ); ?></h2>
		<br style="clear" />
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tables', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'Empty', 'wp-dbmanager' ); ?> <sup>1</sup></th>
					<th><?php esc_html_e( 'Drop', 'wp-dbmanager' ); ?> <sup>2</sup></th>
				</tr>
			</thead>
			<?php
			$no = 0;

			foreach ( DBManager_Tables::all() as $table_name ) {
				$style = ( 0 === $no % 2 ) ? '' : 'alternate';
				++$no;

				printf(
					'<tr class="%1$s"><th align="left" scope="row">%2$s</th>',
					esc_attr( $style ),
					esc_html( $table_name )
				);
				printf(
					'<td><input type="radio" id="%1$s-empty" name="emptydrop[%1$s]" value="empty" />&nbsp;<label for="%1$s-empty">%2$s</label></td>',
					esc_attr( $table_name ),
					esc_html__( 'Empty', 'wp-dbmanager' )
				);
				printf(
					'<td><input type="radio" id="%1$s-drop" name="emptydrop[%1$s]" value="drop" />&nbsp;<label for="%1$s-drop">%2$s</label></td></tr>',
					esc_attr( $table_name ),
					esc_html__( 'Drop', 'wp-dbmanager' )
				);
			}
			?>
			<tr>
				<td colspan="3">
					<?php esc_html_e( '1. EMPTYING a table means all the rows in the table will be deleted. This action is not REVERSIBLE.', 'wp-dbmanager' ); ?>
					<br />
					<?php esc_html_e( '2. DROPPING a table means deleting the table. This action is not REVERSIBLE.', 'wp-dbmanager' ); ?>
				</td>
			</tr>
			<tr>
				<td colspan="3" align="center"><input type="submit" name="do" value="<?php esc_attr_e( 'Empty/Drop', 'wp-dbmanager' ); ?>" class="button" data-dbmanager-confirm="<?php echo esc_attr( $warning ); ?>" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php esc_attr_e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" data-dbmanager-back="1" /></td>
			</tr>
		</table>
	</div>
</form>
		<?php
	}

	/**
	 * The Run SQL Query screen.
	 *
	 * @return void
	 */
	public static function run() {
		DBManager_Admin::check_capability();

		$messages = array();

		$action = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( '' !== $action ) {
			// Verified before any request data is read, not part way down the switch.
			check_admin_referer( 'wp-dbmanager_run' );

			if ( __( 'Run', 'wp-dbmanager' ) === $action ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This is a raw SQL console; sanitizing the query would defeat the feature. Statement types are filtered by run_query().
				$blob = isset( $_POST['sql_query'] ) ? trim( wp_unslash( $_POST['sql_query'] ) ) : '';
				// empty() rather than a comparison against '': the pre-3.0.0 code
				// gated on plain truthiness, so a console holding only "0" counted
				// as an empty query, and that is worth keeping identical.
				$queries = ! empty( $blob ) ? DBManager_Tables::split_queries( $blob ) : array();

				if ( empty( $queries ) ) {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'Empty Query', 'wp-dbmanager' ),
					);
				} else {
					$total   = 0;
					$success = 0;

					foreach ( $queries as $query ) {
						$outcome = DBManager_Tables::run_query( $query );

						if ( 'ignored' === $outcome ) {
							continue;
						}

						++$total;

						if ( 'ok' === $outcome ) {
							++$success;
						}

						$messages[] = array(
							'type' => 'ok' === $outcome ? 'success' : 'error',
							'text' => $query,
						);
					}

					$messages[] = array(
						'type' => 'info',
						'text' => number_format_i18n( $success ) . '/' . number_format_i18n( $total ) . ' ' . __( 'Query(s) Executed Successfully', 'wp-dbmanager' ),
					);
				}
			}
		}

		DBManager_Admin::render_messages( $messages );
		?>
<!-- Run SQL Query -->
<form method="post" action="<?php echo esc_url( DBManager_Admin::page_url( 'run' ) ); ?>">
		<?php wp_nonce_field( 'wp-dbmanager_run' ); ?>
	<div class="wrap">
		<h2><?php esc_html_e( 'Run SQL Query', 'wp-dbmanager' ); ?></h2>
		<br style="clear" />
		<div>
			<strong><?php esc_html_e( 'Separate Multiple Queries With A New Line', 'wp-dbmanager' ); ?></strong><br />
			<p style="color: green;"><?php esc_html_e( 'Use Only INSERT, UPDATE, REPLACE, DELETE, CREATE and ALTER statements.', 'wp-dbmanager' ); ?></p>
		</div>
		<table class="form-table">
			<tr>
				<td align="center"><textarea cols="120" rows="30" name="sql_query" style="width: 99%;" dir="ltr" ></textarea></td>
			</tr>
			<tr>
				<td align="center"><input type="submit" name="do" value="<?php esc_attr_e( 'Run', 'wp-dbmanager' ); ?>" class="button" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php esc_attr_e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" data-dbmanager-back="1" /></td>
			</tr>
		</table>
		<p>
			<?php esc_html_e( '1. CREATE statement will return an error, which is perfectly normal due to the database class. To confirm that your table has been created check the Manage Database page.', 'wp-dbmanager' ); ?><br />
			<?php esc_html_e( '2. UPDATE statement may return an error sometimes due to the newly updated value being the same as the previous value.', 'wp-dbmanager' ); ?><br />
			<?php esc_html_e( '3. ALTER statement will return an error because there is no value returned.', 'wp-dbmanager' ); ?>
		</p>
	</div>
</form>
		<?php
	}
}
