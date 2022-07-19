<?php
### Check Whether User Can Manage Database
if ( ! current_user_can( 'install_plugins' ) ) {
	die( 'Access Denied' );
}


### Variables Variables Variables
$base_name = plugin_basename('wp-dbmanager/database-manager.php');
$base_page = 'admin.php?page='.$base_name;
$current_date = mysql2date(sprintf(__('%s @ %s', 'wp-dbmanager'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', current_time('timestamp')));
$backup = array();
$backup_options = get_option('dbmanager_options');
$backup['date'] = current_time('timestamp');
$backup['mysqldumppath'] = $backup_options['mysqldumppath'];
$backup['mysqlpath'] = $backup_options['mysqlpath'];
$backup['path'] = $backup_options['path'];
$backup['charset'] = ' --default-character-set="utf8mb4"';

### Form Processing
if(!empty($_POST['do'])) {
	$text = '';
	// Decide What To Do
	switch($_POST['do']) {
		case __('Backup', 'wp-dbmanager'):
			check_admin_referer('wp-dbmanager_backup');
			$brace = 0 === strpos( PHP_OS, 'WIN' ) ? '"' : '';
			$backup['host'] = DB_HOST;
			$backup['port'] = '';
			$backup['sock'] = '';
			if ( strpos( DB_HOST, ':' ) !== false ) {
				$db_host = explode(':', DB_HOST);
				$backup['host'] = $db_host[0];
				if ( (int) $db_host[1] !== 0) {
					$backup['port'] = ' --port=' . escapeshellarg( (int) $db_host[1] );
				} else {
					$backup['sock'] = ' --socket=' . escapeshellarg( $db_host[1] );
				}
			}
			$gzip = isset( $_POST['gzip'] ) ? (int) $_POST['gzip'] : 0;
			$backup['filename'] = $backup['date'] . '_-_' . DB_NAME . '.sql';
			if ( $gzip === 1 ) {
				$backup['filename'] .= '.gz';
				$backup['filepath'] = $backup['path'] . '/' . $backup['filename'];
				do_action( 'wp_dbmanager_before_escapeshellcmd' );
				$backup['command'] = $brace . escapeshellcmd( $backup['mysqldumppath'] ) . $brace . ' --force --host=' . escapeshellarg( $backup['host'] ) . ' --user=' . escapeshellarg( DB_USER ) . ' --password=' . escapeshellarg( DB_PASSWORD ) . $backup['port'] . $backup['sock'] . $backup['charset'] . ' --add-drop-table --skip-lock-tables ' . DB_NAME . ' | gzip > ' . $brace . escapeshellcmd( $backup['filepath'] ) . $brace;
			} else {
				$backup['filepath'] = $backup['path'] . '/' . $backup['filename'];
				do_action( 'wp_dbmanager_before_escapeshellcmd' );
				$backup['command'] = $brace . escapeshellcmd( $backup['mysqldumppath'] ) . $brace . ' --force --host=' . escapeshellarg( $backup['host'] ) . ' --user=' . escapeshellarg( DB_USER ) . ' --password=' . escapeshellarg( DB_PASSWORD ) . $backup['port'] . $backup['sock'] . $backup['charset'] . ' --add-drop-table --skip-lock-tables ' . DB_NAME . ' > ' . $brace . escapeshellcmd( $backup['filepath'] ) . $brace;
			}
			$error = execute_backup( $backup['command'] );
			if ( ! is_writable( $backup['path'] ) ) {
				$text = '<p style="color: red;">'.sprintf(__('Database Failed To Backup On \'%s\'. Backup Folder Not Writable.', 'wp-dbmanager'), $current_date).'</p>';
			} elseif ( is_file( $backup['filepath'] ) && filesize( $backup['filepath'] ) === 0 ) {
				$text = '<p style="color: red;">'.sprintf(__('Database Failed To Backup On \'%s\'. Backup File Size Is 0KB.', 'wp-dbmanager'), $current_date).'</p>';
			} elseif ( ! is_file( $backup['filepath'] ) ) {
				$text = '<p style="color: red;">'.sprintf(__('Database Failed To Backup On \'%s\'. Invalid Backup File Path.', 'wp-dbmanager'), $current_date).'</p>';
			} elseif ( $error ) {
				$text = '<p style="color: red;">'.sprintf(__('Database Failed To Backup On \'%s\'.', 'wp-dbmanager'), $current_date).'</p>';
			} else {
				rename( $backup['filepath'], $backup['path'] . '/' . md5_file( $backup['filepath'] ) . '_-_' . $backup['filename'] );
				$text = '<p style="color: green;">'.sprintf(__('Database Backed Up Successfully On \'%s\'.', 'wp-dbmanager'), $current_date).'</p>';
			}
			break;
	}
}

### Backup File Name
$backup['filename'] = $backup['date'].'_-_'.DB_NAME.'.sql';
$backup_path = stripslashes( $backup['path'] );

### MYSQL Base Dir
$has_error = false;
$disabled_function = false;
?>
<?php if( ! empty( $text ) ) { echo '<div id="message" class="updated">'.$text.'</div>'; } ?>
<!-- Checking Backup Status -->
<div class="wrap">
	<h2><?php _e('Backup Database', 'wp-dbmanager'); ?></h2>
	<h3><?php _e('Checking Security Status', 'wp-dbmanager'); ?></h3>
	<p>
		<?php
			if( is_iis() ) {
				if ( ! is_file( $backup_path . '/Web.config' ) ) {
					echo '<p style="color: red;">' . sprintf( __( 'Web.config is missing from %s', 'wp-dbmanager' ), $backup_path ) . '</p>';
					$has_error = true;
				} else {
					echo '<p style="color: green;">' . sprintf( __( 'Web.config is present in %s', 'wp-dbmanager' ), $backup_path ) . '</p>';
				}
			} else {
				if( ! is_file( $backup_path . '/.htaccess' ) ) {
					echo '<p style="color: red;">' . sprintf( __( '.htaccess is missing from %s', 'wp-dbmanager' ), $backup_path ) . '</p>';
					$has_error = true;
				} else {
					echo '<p style="color: green;">' . sprintf( __( '.htaccess is present in %s', 'wp-dbmanager' ), $backup_path ) . '</p>';
				}
			}
			if( ! is_file( $backup_path . '/index.php' ) ) {
				echo '<p style="color: red;">' . sprintf( __( 'index.php is missing from %s', 'wp-dbmanager' ), $backup_path ) . '</p>';
				$has_error = true;
			} else {
				echo '<p style="color: green;">' . sprintf( __( 'index.php is present in %s', 'wp-dbmanager' ), $backup_path ) . '</p>';
			}
		?>
	</p>
	<h3><?php _e('Checking Backup Status', 'wp-dbmanager'); ?></h3>
	<p>
		<?php _e('Checking Backup Folder', 'wp-dbmanager'); ?> <span dir="ltr">(<strong><?php echo $backup_path; ?></strong>)</span> ...<br />
		<?php
			if( realpath( $backup_path ) === false ) {
				echo '<p style="color: red;">' . sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), $backup_path ) . '</p>';
				$has_error = true;
			} else {
				if ( @is_dir( $backup_path ) ) {
					echo '<p style="color: green;">' . __('Backup folder exists', 'wp-dbmanager') . '</p>';
				} else {
					echo '<p style="color: red;">' . sprintf(__('Backup folder does NOT exist. Please create \'backup-db\' folder in \'%s\' folder and CHMOD it to \'777\' or change the location of the backup folder under DB Option.', 'wp-dbmanager'), WP_CONTENT_DIR) . '</p>';
					$has_error = true;
				}
				if ( @is_writable( $backup_path ) ) {
					echo '<p style="color: green;">' . __('Backup folder is writable', 'wp-dbmanager') . '</p>';
				} else {
					echo '<p style="color: red;">' . __('Backup folder is NOT writable. Please CHMOD it to \'777\'.', 'wp-dbmanager') . '</p>';
					$has_error = true;
				}
			}
		?>
	</p>
	<p>
		<?php
			if( dbmanager_is_valid_path( $backup['mysqldumppath'] ) === 0 ) {
				echo '<p style="color: red;">' . sprintf( __( '%s is not a valid backup mysqldump path', 'wp-dbmanager' ), stripslashes( $backup['mysqldumppath'] ) ) . '</p>';
				$has_error = true;
			} else {
				if ( @file_exists( stripslashes( $backup['mysqldumppath'] ) ) ) {
					echo __('Checking MYSQL Dump Path', 'wp-dbmanager') . ' <span dir="ltr">(<strong>' . stripslashes( $backup['mysqldumppath'] ) . '</strong>)</span> ...<br />';
					echo '<p style="color: green;">' . __('MYSQL dump path exists.', 'wp-dbmanager') . '</p>';
				} else {
					echo __('Checking MYSQL Dump Path', 'wp-dbmanager') . ' ...<br />';
					echo '<p style="color: red;">' . __('MYSQL dump path does NOT exist. Please check your mysqldump path under DB Options. If uncertain, contact your server administrator.', 'wp-dbmanager') . '</p>';
					$has_error = true;
				}
			}
		?>
	</p>
	<p>
		<?php
			if( dbmanager_is_valid_path( $backup['mysqlpath'] ) === 0 ) {
				echo '<p style="color: red;">' . sprintf( __( '%s is not a valid backup mysql path', 'wp-dbmanager' ), stripslashes( $backup['mysqlpath'] ) ) . '</p>';
				$has_error = true;
			} else {
				if ( @file_exists( stripslashes($backup['mysqlpath'] ) ) ) {
					echo __('Checking MYSQL Path', 'wp-dbmanager') . ' <span dir="ltr">(<strong>' . stripslashes($backup['mysqlpath']) . '</strong>)</span> ...<br />';
					echo '<p style="color: green;">' . __('MYSQL path exists.', 'wp-dbmanager') . '</p>';
				} else {
					echo __('Checking MYSQL Path', 'wp-dbmanager') . ' ...<br />';
					echo '<p style="color: red;">' . __('MYSQL path does NOT exist. Please check your mysql path under DB Options. If uncertain, contact your server administrator.', 'wp-dbmanager') . '</p>';
					$has_error = true;
				}
			}
		?>
	</p>
	<p>
		<?php _e('Checking PHP Functions', 'wp-dbmanager'); ?> <span dir="ltr">(<strong>passthru()</strong>, <strong>system()</strong> <?php _e('and', 'wp-dbmanager'); ?> <strong>exec()</strong>)</span> ...<br />
		<?php
			if( dbmanager_is_function_disabled( 'passthru' ) ) {
				echo '<p style="color: red;"><span dir="ltr">passthru()</span> '.__('disabled', 'wp-dbmanager').'.</p>';
				$disabled_function = true;
			} else if( ! function_exists( 'passthru' ) ) {
				echo '<p style="color: red;"><span dir="ltr">passthru()</span> '.__('missing', 'wp-dbmanager').'.</p>';
				$disabled_function = true;
			} else {
				echo '<p style="color: green;"><span dir="ltr">passthru()</span> '.__('enabled', 'wp-dbmanager').'.</p>';
			}
			if( dbmanager_is_function_disabled( 'system' ) ) {
				echo '<p style="color: red;"><span dir="ltr">system()</span> '.__('disabled', 'wp-dbmanager').'.</p>';
				$disabled_function = true;
			} else if( ! function_exists( 'system' ) ) {
				echo '<p style="color: red;"><span dir="ltr">system()</span> '.__('missing', 'wp-dbmanager').'.</p>';
				$disabled_function = true;
			} else {
				echo '<p style="color: green;"><span dir="ltr">system()</span> '.__('enabled', 'wp-dbmanager').'.</p>';
			}
			if( dbmanager_is_function_disabled( 'exec' ) ) {
				echo '<p style="color: red;"><span dir="ltr">exec()</span> '.__('disabled', 'wp-dbmanager').'.</p>';
				$disabled_function = true;
			} else if( ! function_exists( 'exec' ) ) {
				echo '<p style="color: red;"><span dir="ltr">exec()</span> '.__('missing', 'wp-dbmanager').'.</p>';
				$disabled_function = true;
			} else {
				echo '<p style="color: green;"><span dir="ltr">exec()</span> '.__('enabled', 'wp-dbmanager').'.</p>';
			}
		?>
	</p>
	<p>
		<?php
			if( $disabled_function ) {
				echo '<strong><p style="color: red;">' . __( 'I\'m sorry, your server administrator has disabled passthru(), system() and/or exec(), thus you cannot use this plugin. Please find an alternative plugin.', 'wp-dbmanager' ) . '</p></strong>';
			} else if( ! $has_error ) {
				echo '<strong><p style="color: green;">'.__('Excellent. You Are Good To Go.', 'wp-dbmanager').'</p></strong>';
			} else {
				echo '<strong><p style="color: red;">'.__('Please Rectify The Error Highlighted In Red Before Proceeding On.', 'wp-dbmanager').'</p></strong>';
			}
		?>
	</p>
	<p><i><?php _e('Note: The checking of backup status is still undergoing testing, it may not be accurate.', 'wp-dbmanager'); ?></i></p>
</div>
<!-- Backup Database -->
<form method="post" action="<?php echo admin_url('admin.php?page='.plugin_basename(__FILE__)); ?>">
	<?php wp_nonce_field('wp-dbmanager_backup'); ?>
	<div class="wrap">
		<h3><?php _e('Backup Database', 'wp-dbmanager'); ?></h3>
		<br style="clear" />
		<table class="widefat">
			<thead>
				<tr>
					<th><?php _e('Option', 'wp-dbmanager'); ?></th>
					<th><?php _e('Value', 'wp-dbmanager'); ?></th>
				</tr>
			</thead>
			<tr>
				<th><?php _e('Database Name:', 'wp-dbmanager'); ?></th>
				<td><?php echo DB_NAME; ?></td>
			</tr>
			<tr style="background-color: #eee;">
				<th><?php _e('Database Backup To:', 'wp-dbmanager'); ?></th>
				<td><span dir="ltr"><?php echo $backup_path; ?></span></td>
			</tr>
			<tr>
				<th><?php _e('Database Backup Date:', 'wp-dbmanager'); ?></th>
				<td><?php echo mysql2date(sprintf(__('%s @ %s', 'wp-dbmanager'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', $backup['date'])); ?></td>
			</tr>
			<tr style="background-color: #eee;">
				<th><?php _e('Database Backup File Name:', 'wp-dbmanager'); ?></th>
				<td><span dir="ltr"><?php echo $backup['filename']; ?></span></td>
			</tr>
			<tr>
				<th><?php _e('Database Backup Type:', 'wp-dbmanager'); ?></th>
				<td><?php _e('Full (Structure and Data)', 'wp-dbmanager'); ?></td>
			</tr>
			<tr style="background-color: #eee;">
				<th><?php _e('MYSQL Dump Location:', 'wp-dbmanager'); ?></th>
				<td><span dir="ltr"><?php echo stripslashes($backup['mysqldumppath']); ?></span></td>
			</tr>
			<tr>
				<th><?php _e('GZIP Database Backup File?', 'wp-dbmanager'); ?></th>
				<td><input type="radio" id="gzip-yes" name="gzip" value="1" />&nbsp;<label for="gzip-yes"><?php _e('Yes', 'wp-dbmanager'); ?></label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="gzip-no" name="gzip" value="0" checked="checked" />&nbsp;<label for="gzip-no"><?php _e('No', 'wp-dbmanager'); ?></label></td>
			</tr>
			<tr>
				<td colspan="2" align="center"><input type="submit" name="do" value="<?php _e('Backup', 'wp-dbmanager'); ?>" class="button" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php _e('Cancel', 'wp-dbmanager'); ?>" class="button" onclick="javascript:history.go(-1)" /></td>
			</tr>
		</table>
	</div>
</form>
