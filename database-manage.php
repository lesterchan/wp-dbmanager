<?php
/**
 * Manage Backup Database admin page.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

// Check Whether User Can Manage Database.
if ( ! current_user_can( 'install_plugins' ) ) {
	die( 'Access Denied' );
}

dbmanager_page_manage();

/**
 * Renders the page.
 *
 * Wrapped in a function because admin.php includes this file at global scope,
 * which would otherwise leak every variable below into the global namespace.
 */
function dbmanager_page_manage() {

	// Variables Variables Variables.
	$base_name               = plugin_basename( 'wp-dbmanager/database-manager.php' );
	$base_page               = 'admin.php?page=' . $base_name;
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
		check_admin_referer( 'wp-dbmanager_manage' );
		// Lets Prepare The Variables.
		$database_file = ! empty( $_POST['database_file'] ) ? sanitize_file_name( wp_unslash( $_POST['database_file'] ) ) : '';
		$file          = dbmanager_parse_filename( $database_file );

		// Decide What To Do.
		switch ( $_POST['do'] ) {
			case __( 'Restore', 'wp-dbmanager' ):
				if ( ! empty( $database_file ) ) {
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

					$defaults_file = dbmanager_write_defaults_file();
					do_action( 'wp_dbmanager_before_escapeshellcmd' );
					if ( false !== stripos( $database_file, '.gz' ) ) {
						$backup['command'] = 'gunzip < ' . escapeshellarg( $backup['path'] . '/' . $database_file ) . ' | ' . escapeshellarg( $backup['mysqlpath'] ) . dbmanager_credential_args( $defaults_file ) . ' --host=' . escapeshellarg( $backup['host'] ) . ' --user=' . escapeshellarg( DB_USER ) . $backup['port'] . $backup['sock'] . $backup['charset'] . ' ' . escapeshellarg( DB_NAME );
					} else {
						$backup['command'] = escapeshellarg( $backup['mysqlpath'] ) . dbmanager_credential_args( $defaults_file ) . ' --host=' . escapeshellarg( $backup['host'] ) . ' --user=' . escapeshellarg( DB_USER ) . $backup['port'] . $backup['sock'] . $backup['charset'] . ' ' . escapeshellarg( DB_NAME ) . ' < ' . escapeshellarg( $backup['path'] . '/' . $database_file );
					}
					$error       = 0;
					$restore_ran = false;
					if ( realpath( $backup['path'] ) === false ) {
						$messages[] = array(
							'type' => 'error',
							/* translators: %s: configured backup folder path. */
							'text' => sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), $backup['path'] ),
						);
					} elseif ( dbmanager_is_valid_path( $backup['mysqlpath'] ) === 0 ) {
						$messages[] = array(
							'type' => 'error',
							/* translators: %s: configured mysql path. */
							'text' => sprintf( __( '%s is not a valid mysql path', 'wp-dbmanager' ), $backup['mysqlpath'] ),
						);
					} else {
						passthru( $backup['command'], $error );
						$restore_ran = true;
					}
					dbmanager_delete_defaults_file( $defaults_file );

					// Only report on the restore when it actually ran, the path errors above
					// already explain why it did not.
					if ( $restore_ran ) {
						if ( $error ) {
							$messages[] = array(
								'type' => 'error',
								/* translators: %s: date and time of the backup file. */
								'text' => sprintf( __( 'Database On \'%s\' Failed To Restore', 'wp-dbmanager' ), $file['formatted_date'] ),
							);
						} else {
							$messages[] = array(
								'type' => 'success',
								/* translators: %s: date and time of the backup file. */
								'text' => sprintf( __( 'Database On \'%s\' Restored Successfully', 'wp-dbmanager' ), $file['formatted_date'] ),
							);
						}
					}
				} else {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'No Backup Database File Selected', 'wp-dbmanager' ),
					);
				}
				break;
			case __( 'E-Mail', 'wp-dbmanager' ):
				if ( ! empty( $database_file ) ) {
					$to = ( ! empty( $_POST['email_to'] ) ? sanitize_email( wp_unslash( $_POST['email_to'] ) ) : get_option( 'admin_email' ) );

					if ( dbmanager_email_backup( $to, $backup['path'] . '/' . $database_file ) ) {
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
				} else {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'No Backup Database File Selected', 'wp-dbmanager' ),
					);
				}
				break;
			case __( 'Download', 'wp-dbmanager' ):
				if ( empty( $database_file ) ) {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'No Backup Database File Selected', 'wp-dbmanager' ),
					);
				}
				break;
			case __( 'Delete', 'wp-dbmanager' ):
				if ( ! empty( $database_file ) ) {
					if ( is_file( $backup['path'] . '/' . $database_file ) ) {
						// wp_delete_file() returns nothing, so ask the filesystem whether it worked.
						wp_delete_file( $backup['path'] . '/' . $database_file );
						if ( file_exists( $backup['path'] . '/' . $database_file ) ) {
							$messages[] = array(
								'type' => 'error',
								/* translators: %s: date and time of the backup file. */
								'text' => sprintf( __( 'Unable To Delete Database Backup File On \'%s\'', 'wp-dbmanager' ), $file['formatted_date'] ),
							);
						} else {
							$messages[] = array(
								'type' => 'success',
								/* translators: %s: date and time of the backup file. */
								'text' => sprintf( __( 'Database Backup File On \'%s\' Deleted Successfully', 'wp-dbmanager' ), $file['formatted_date'] ),
							);
						}
					} else {
						$messages[] = array(
							'type' => 'error',
							/* translators: %s: date and time of the backup file. */
							'text' => sprintf( __( 'Invalid Database Backup File On \'%s\'', 'wp-dbmanager' ), $file['formatted_date'] ),
						);
					}
				} else {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'No Backup Database File Selected', 'wp-dbmanager' ),
					);
				}
				break;
		}
	}
	?>
	<?php
	dbmanager_render_messages( $messages );
	?>
<!-- Manage Backup Database -->
<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . plugin_basename( __FILE__ ) ) ); ?>">
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
				$no             = 0;
				$totalsize      = 0;
				$database_files = array_reverse( dbmanager_list_backup_files( $backup['path'] ) );
			if ( ! empty( $database_files ) ) {
				foreach ( $database_files as $database_file_entry ) {
					$database_file = $database_file_entry['name'];
					if ( 0 === $no % 2 ) {
						$style = '';
					} else {
						$style = 'alternate';
					}
					++$no;
					$file = dbmanager_parse_file( $backup['path'] . '/' . $database_file );
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
						esc_attr( $database_file )
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
				<th><?php echo esc_html( format_size( $totalsize ) ); ?></th>
				<th>&nbsp;</th>
			</tr>
		</table>
		<table class="form-table">
			<tr>
				<td colspan="5" align="center"><label for="email_to"><?php esc_html_e( 'E-mail database backup file to:', 'wp-dbmanager' ); ?></label> <input type="text" id="email_to" name="email_to" size="30" maxlength="50" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" dir="ltr" />&nbsp;&nbsp;<input type="submit" name="do" value="<?php esc_html_e( 'E-Mail', 'wp-dbmanager' ); ?>" class="button" /></td>
			</tr>
			<tr>
				<td colspan="5" align="center">
					<input type="submit" name="do" value="<?php esc_html_e( 'Download', 'wp-dbmanager' ); ?>" class="button" />&nbsp;&nbsp;
					<input type="submit" name="do" value="<?php esc_html_e( 'Restore', 'wp-dbmanager' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'You Are About To Restore A Database.\nThis Action Is Not Reversible.\nAny Data Inserted After The Backup Date Will Be Gone.\n\n Choose [Cancel] to stop, [Ok] to restore.', 'wp-dbmanager' ) ); ?>')" class="button" />&nbsp;&nbsp;
					<input type="submit" class="button" name="do" value="<?php esc_html_e( 'Delete', 'wp-dbmanager' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'You Are About To Delete The Selected Database Backup Files.\nThis Action Is Not Reversible.\n\n Choose [Cancel] to stop, [Ok] to delete.', 'wp-dbmanager' ) ); ?>')" />&nbsp;&nbsp;
					<input type="button" name="cancel" value="<?php esc_html_e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" onclick="history.go(-1)" /></td>
			</tr>
		</table>
	</div>
</form>

	<?php
}
