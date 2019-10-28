<?php
### Check Whether User Can Manage Database
if(!current_user_can('manage_database')) {
	die('Access Denied');
}


### Variables Variables Variables
$base_name = plugin_basename('wp-dbmanager/database-manager.php');
$base_page = 'admin.php?page='.$base_name;
$backup = array();
$backup_options = get_option('dbmanager_options');
$backup['date'] = current_time('timestamp');
$backup['mysqldumppath'] = $backup_options['mysqldumppath'];
$backup['mysqlpath'] = $backup_options['mysqlpath'];
$backup['path'] = $backup_options['path'];
$backup['charset'] = ' --default-character-set="utf8"';


### Form Processing
if( !empty( $_POST['do'] ) ) {
	check_admin_referer('wp-dbmanager_manage');
	// Lets Prepare The Variables
	$database_file = ! empty ( $_POST['database_file'] ) ? sanitize_file_name( $_POST['database_file'] ) : '';
	$file = dbmanager_parse_filename( $database_file );
	$text = '';

	// Decide What To Do
	switch($_POST['do']) {
		case __('Restore', 'wp-dbmanager'):
			if(!empty($database_file)) {
				$brace = substr(PHP_OS, 0, 3) === 'WIN' ? '"' : '';
				$backup['host'] = DB_HOST;
				$backup['port'] = '';
				$backup['sock'] = '';
				if(strpos(DB_HOST, ':') !== false) {
					$db_host = explode(':', DB_HOST);
					$backup['host'] = $db_host[0];
					if ( (int) $db_host[1] !== 0 ) {
						$backup['port'] = ' --port=' . escapeshellarg( (int) $db_host[1] );
					} else {
						$backup['sock'] = ' --socket=' . escapeshellarg( $db_host[1] );
					}
				}

				if ( false !== stripos( $database_file, '.gz' ) ) {
					do_action( 'wp_dbmanager_before_escapeshellcmd' );
					$backup['command'] = 'gunzip < ' . $brace . escapeshellcmd( $backup['path'] . '/' . $database_file ) . $brace . ' | ' . $brace . escapeshellcmd( $backup['mysqlpath'] ) . $brace . ' --host=' . escapeshellarg( $backup['host'] ) . ' --user=' . escapeshellarg( DB_USER ) . ' --password=' . escapeshellarg( DB_PASSWORD ) . $backup['port'] . $backup['sock'] . $backup['charset'] . ' ' . DB_NAME;
				} else {
					do_action( 'wp_dbmanager_before_escapeshellcmd' );
					$backup['command'] = $brace . escapeshellcmd( $backup['mysqlpath'] ) . $brace . ' --host=' . escapeshellarg( $backup['host'] ) . ' --user=' . escapeshellarg( DB_USER ) . ' --password=' . escapeshellarg( DB_PASSWORD ) . $backup['port'] . $backup['sock'] . $backup['charset'] . ' ' . DB_NAME . ' < ' . $brace . escapeshellcmd( $backup['path'] . '/' . $database_file ) . $brace;
				}
				if( realpath( $backup['path'] ) === false ) {
					$text = '<p style="color: red;">' . sprintf(__('%s is not a valid backup path', 'wp-dbmanager'), stripslashes( $backup['path'] ) ) . '</p>';
				} else if( dbmanager_is_valid_path( $backup['mysqlpath'] ) === 0 ) {
					$text = '<p style="color: red;">' . sprintf(__('%s is not a valid mysql path', 'wp-dbmanager'), stripslashes( $backup['mysqlpath'] ) ) . '</p>';
				} else {
					passthru( $backup['command'], $error );
				}
				if($error) {
					$text = '<p style="color: red;">' . sprintf( __( 'Database On \'%s\' Failed To Restore', 'wp-dbmanager' ), $file['formatted_date'] ) . '</p>';
				} else {
					$text = '<p style="color: green;">' . sprintf( __( 'Database On \'%s\' Restored Successfully', 'wp-dbmanager' ), $file['formatted_date'] ) . '</p>';
				}
			} else {
				$text = '<p style="color: red;">' . __('No Backup Database File Selected', 'wp-dbmanager' ) . '</p>';
			}
			break;
		case __('E-Mail', 'wp-dbmanager'):
			if(!empty($database_file)) {
				$to = ( !empty( $_POST['email_to'] ) ? sanitize_email( $_POST['email_to'] ) : get_option( 'admin_email' ) );

				if( dbmanager_email_backup( $to, $backup['path'] . '/' . $database_file ) ) {
					$text .= '<p style="color: green;">' . sprintf( __( 'Database Backup File For \'%s\' Successfully E-Mailed To \'%s\'', 'wp-dbmanager' ), $file['formatted_date'], $to) . '</p>';
				} else {
					$text = '<p style="color: red;">' . sprintf( __( 'Unable To E-Mail Database Backup File For \'%s\' To \'%s\'', 'wp-dbmanager' ), $file['formatted_date'], $to ) . '</p>';
				}
			} else {
				$text = '<p style="color: red;">' . __('No Backup Database File Selected', 'wp-dbmanager' ) . '</p>';
			}
			break;
		case __('Download', 'wp-dbmanager'):
			if(empty($database_file)) {
				$text = '<p style="color: red;">' . __( 'No Backup Database File Selected', 'wp-dbmanager' ) . '</p>';
			}
			break;
		case __('Delete', 'wp-dbmanager'):
			if ( ! empty( $database_file ) ) {
				if ( is_file( $backup['path'] . '/' . $database_file ) ) {
					if ( ! unlink( $backup['path'] . '/' . $database_file ) ) {
						$text .= '<p style="color: red;">' . sprintf( __( 'Unable To Delete Database Backup File On \'%s\'', 'wp-dbmanager' ), $file['formatted_date'] ) . '</p>';
					} else {
						$text .= '<p style="color: green;">' . sprintf( __( 'Database Backup File On \'%s\' Deleted Successfully', 'wp-dbmanager' ), $file['formatted_date'] ) . '</p>';
					}
				} else {
					$text = '<p style="color: red;">' . sprintf( __( 'Invalid Database Backup File On \'%s\'', 'wp-dbmanager' ), $file['formatted_date'] ) . '</p>';
				}
			} else {
				$text = '<p style="color: red;">' . __( 'No Backup Database File Selected', 'wp-dbmanager' ) . '</p>';
			}
			break;
	}
}
?>
<?php if(!empty($text)) { echo '<!-- Last Action --><div id="message" class="updated">'.$text.'</div>'; } ?>
<!-- Manage Backup Database -->
<form method="post" action="<?php echo admin_url('admin.php?page='.plugin_basename(__FILE__)); ?>">
	<?php wp_nonce_field('wp-dbmanager_manage'); ?>
	<div class="wrap">
		<h2><?php _e('Manage Backup Database', 'wp-dbmanager'); ?></h2>
		<p><?php _e('Choose A Backup Date To E-Mail, Restore, Download Or Delete', 'wp-dbmanager'); ?></p>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php _e('No.', 'wp-dbmanager'); ?></th>
					<th><?php _e('MD5 Checksum', 'wp-dbmanager'); ?></th>
					<th><?php _e('Database File', 'wp-dbmanager'); ?></th>
					<th><?php _e('Date/Time', 'wp-dbmanager'); ?></th>
					<th><?php _e('Size', 'wp-dbmanager'); ?></th>
					<th><?php _e('Select', 'wp-dbmanager'); ?></th>
				</tr>
			</thead>
			<?php
				$no = 0;
				$totalsize = 0;
				if ( ! is_emtpy_folder( $backup['path'] ) && $handle = opendir( $backup['path'] ) ) {
						$database_files = array();
						while ( false !== ( $file = readdir( $handle ) ) ) {
							if ( $file !== '.' && $file !== '..' && $file !== '.htaccess' && ( file_ext( $file ) === 'sql' || file_ext( $file ) === 'gz' ) ) {
								$database_files[filemtime( $backup['path'] . '/' . $file )] = $file;
							}
						}
						closedir( $handle );
						krsort( $database_files );
						foreach( $database_files as $database_file_mtime => $database_file ) {
							if ( $no % 2 === 0 ) {
								$style = '';
							} else {
								$style = ' class="alternate"';
							}
							$no++;
							$file = dbmanager_parse_file( $backup['path'] . '/'. $database_file );
							echo '<tr'. $style .'>';
							echo '<td>' . number_format_i18n( $no ) . '</td>';
							echo '<td>' . $file['checksum'] . '</td>';
							echo '<td>' . $file['database'] . '</td>';
							echo '<td>' . $file['formatted_date'] . '</td>';
							echo '<td>' . $file['formatted_size'] . '</td>';
							echo '<td><input type="radio" name="database_file" value="'. esc_attr( $database_file ) .'" /></td></tr>';
							$totalsize += $file['size'];
						}
				} else {
					echo '<tr><td align="center" colspan="6">'.__('There Are No Database Backup Files Available.', 'wp-dbmanager').'</td></tr>';
				}
			?>
			<tr class="thead">
				<th colspan="4"><?php printf(_n('%s Backup File', '%s Backup Files', $no, 'wp-dbmanager'), number_format_i18n($no)); ?></th>
				<th><?php echo format_size($totalsize); ?></th>
				<th>&nbsp;</th>
			</tr>
		</table>
		<table class="form-table">
			<tr>
				<td colspan="5" align="center"><label for="email_to"><?php _e('E-mail database backup file to:', 'wp-dbmanager'); ?></label> <input type="text" id="email_to" name="email_to" size="30" maxlength="50" value="<?php echo get_option('admin_email'); ?>" dir="ltr" />&nbsp;&nbsp;<input type="submit" name="do" value="<?php _e('E-Mail', 'wp-dbmanager'); ?>" class="button" /></td>
			</tr>
			<tr>
				<td colspan="5" align="center">
					<input type="submit" name="do" value="<?php _e('Download', 'wp-dbmanager'); ?>" class="button" />&nbsp;&nbsp;
					<input type="submit" name="do" value="<?php _e('Restore', 'wp-dbmanager'); ?>" onclick="return confirm('<?php _e('You Are About To Restore A Database.\nThis Action Is Not Reversible.\nAny Data Inserted After The Backup Date Will Be Gone.\n\n Choose [Cancel] to stop, [Ok] to restore.', 'wp-dbmanager'); ?>')" class="button" />&nbsp;&nbsp;
					<input type="submit" class="button" name="do" value="<?php _e('Delete', 'wp-dbmanager'); ?>" onclick="return confirm('<?php _e('You Are About To Delete The Selected Database Backup Files.\nThis Action Is Not Reversible.\n\n Choose [Cancel] to stop, [Ok] to delete.', 'wp-dbmanager'); ?>')" />&nbsp;&nbsp;
					<input type="button" name="cancel" value="<?php _e('Cancel', 'wp-dbmanager'); ?>" class="button" onclick="history.go(-1)" /></td>
			</tr>
		</table>
	</div>
</form>
