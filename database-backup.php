<?php
### Check Whether User Can Manage Database
if(!current_user_can('manage_database')) {
	die('Access Denied');
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
$backup['password'] = str_replace('$', '\$', DB_PASSWORD);
$backup['charset'] = ' --default-character-set="utf8"';

### Form Processing
if(!empty($_POST['do'])) {
	$text = '';
	// Decide What To Do
	switch($_POST['do']) {
		case __('Backup', 'wp-dbmanager'):
			check_admin_referer('wp-dbmanager_backup');
			$brace = (substr(PHP_OS, 0, 3) == 'WIN') ? '"' : '';
			$backup['host'] = DB_HOST;
			$backup['port'] = '';
			$backup['sock'] = '';
			if(strpos(DB_HOST, ':') !== false) {
				$db_host = explode(':', DB_HOST);
				$backup['host'] = $db_host[0];
				if(intval($db_host[1]) != 0) {
					$backup['port'] = ' --port="'.intval($db_host[1]).'"';
				} else {
					$backup['sock'] = ' --socket="'.$db_host[1].'"';
				}
			}
			$gzip = intval($_POST['gzip']);
			if($gzip == 1) {
				$backup['filename'] = $backup['date'].'_-_'.DB_NAME.'.sql.gz';
				$backup['filepath'] = $backup['path'].'/'.$backup['filename'];
				$backup['command'] = $brace.$backup['mysqldumppath'].$brace.' --force --host="'.$backup['host'].'" --user="'.DB_USER.'" --password="'.$backup['password'].'"'.$backup['port'].$backup['sock'].$backup['charset'].' --add-drop-table --skip-lock-tables '.DB_NAME.' | gzip > '.$brace.$backup['filepath'].$brace;
			} else {
				$backup['filename'] = $backup['date'].'_-_'.DB_NAME.'.sql';
				$backup['filepath'] = $backup['path'].'/'.$backup['filename'];
				$backup['command'] = $brace.$backup['mysqldumppath'].$brace.' --force --host="'.$backup['host'].'" --user="'.DB_USER.'" --password="'.$backup['password'].'"'.$backup['port'].$backup['sock'].$backup['charset'].' --add-drop-table --skip-lock-tables '.DB_NAME.' > '.$brace.$backup['filepath'].$brace;
			}
			$error = execute_backup($backup['command']);
			if(!is_writable($backup['path'])) {
				$text = '<font color="red">'.sprintf(__('Database Failed To Backup On \'%s\'. Backup Folder Not Writable.', 'wp-dbmanager'), $current_date).'</font>';
			} elseif(filesize($backup['filepath']) == 0) {
				unlink($backup['filepath']);
				$text = '<font color="red">'.sprintf(__('Database Failed To Backup On \'%s\'. Backup File Size Is 0KB.', 'wp-dbmanager'), $current_date).'</font>';
			} elseif(!is_file($backup['filepath'])) {
				$text = '<font color="red">'.sprintf(__('Database Failed To Backup On \'%s\'. Invalid Backup File Path.', 'wp-dbmanager'), $current_date).'</font>';
			} elseif($error) {
				$text = '<font color="red">'.sprintf(__('Database Failed To Backup On \'%s\'.', 'wp-dbmanager'), $current_date).'</font>';
			} else {
				$text = '<font color="green">'.sprintf(__('Database Backed Up Successfully On \'%s\'.', 'wp-dbmanager'), $current_date).'</font>';
			}
			break;
	}
}


### Backup File Name
$backup['filename'] = $backup['date'].'_-_'.DB_NAME.'.sql';


### MYSQL Base Dir
$status_count = 0;
$stats_function_disabled = 0;
?>
<?php if(!empty($text)) { echo '<!-- Last Action --><div id="message" class="updated fade"><p>'.$text.'</p></div>'; } ?>
<!-- Checking Backup Status -->
<div class="wrap">
	<h2><?php _e('Backup Database', 'wp-dbmanager'); ?></h2>
	<h3><?php _e('Checking Backup Status', 'wp-dbmanager'); ?></h3>
	<p>
		<?php _e('Checking Backup Folder', 'wp-dbmanager'); ?> <span dir="ltr">(<strong><?php echo stripslashes($backup['path']); ?></strong>)</span> ...<br />
		<?php
			if(@is_dir(stripslashes($backup['path']))) {
				echo '<font color="green">'.__('Backup folder exists', 'wp-dbmanager').'</font><br />';
				$status_count++;
			} else {
				echo '<font color="red">'.sprintf(__('Backup folder does NOT exist. Please create \'backup-db\' folder in \'%s\' folder and CHMOD it to \'777\' or change the location of the backup folder under DB Option.', 'wp-dbmanager'), WP_CONTENT_DIR).'</font><br />';
			}
			if(@is_writable(stripslashes($backup['path']))) {
				echo '<font color="green">'.__('Backup folder is writable', 'wp-dbmanager').'</font>';
				$status_count++;
			} else {
				echo '<font color="red">'.__('Backup folder is NOT writable. Please CHMOD it to \'777\'.', 'wp-dbmanager').'</font>';
			}
		?>
	</p>
	<p>
		<?php
			if(@file_exists(stripslashes($backup['mysqldumppath']))) {
				echo __('Checking MYSQL Dump Path', 'wp-dbmanager').' <span dir="ltr">(<strong>'.stripslashes($backup['mysqldumppath']).'</strong>)</span> ...<br />';
				echo '<font color="green">'.__('MYSQL dump path exists.', 'wp-dbmanager').'</font>';
				$status_count++;
			} else {
				echo __('Checking MYSQL Dump Path', 'wp-dbmanager').' ...<br />';
				echo '<font color="red">'.__('MYSQL dump path does NOT exist. Please check your mysqldump path under DB Options. If uncertain, contact your server administrator.', 'wp-dbmanager').'</font>';
			}
		?>
	</p>
	<p>
		<?php
			if(@file_exists(stripslashes($backup['mysqlpath']))) {
				echo __('Checking MYSQL Path', 'wp-dbmanager').' <span dir="ltr">(<strong>'.stripslashes($backup['mysqlpath']).'</strong>)</span> ...<br />';
				echo '<font color="green">'.__('MYSQL path exists.', 'wp-dbmanager').'</font>';
				$status_count++;
			} else {
				echo __('Checking MYSQL Path', 'wp-dbmanager').' ...<br />';
				echo '<font color="red">'.__('MYSQL path does NOT exist. Please check your mysql path under DB Options. If uncertain, contact your server administrator.', 'wp-dbmanager').'</font>';
			}
		?>
	</p>
	<p>
		<?php _e('Checking PHP Functions', 'wp-dbmanager'); ?> <span dir="ltr">(<strong>passthru()</strong>, <strong>system()</strong> <?php _e('and', 'wp-dbmanager'); ?> <strong>exec()</strong>)</span> ...<br />
		<?php
			if(function_exists('passthru')) {
				echo '<font color="green"><span dir="ltr">passthru()</span> '.__('enabled', 'wp-dbmanager').'.</font><br />';
				$status_count++;
			} else {
				echo '<font color="red"><span dir="ltr">passthru()</span> '.__('disabled', 'wp-dbmanager').'.</font><br />';
				$stats_function_disabled++;
			}
			if(function_exists('system')) {
				echo '<font color="green"><span dir="ltr">system()</span> '.__('enabled', 'wp-dbmanager').'.</font><br />';
			} else {
				echo '<font color="red"><span dir="ltr">system()</span> '.__('disabled', 'wp-dbmanager').'.</font><br />';
				$stats_function_disabled++;
			}
			if(function_exists('exec')) {
				echo '<font color="green"><span dir="ltr">exec()</span> '.__('enabled', 'wp-dbmanager').'.</font>';
			} else {
				echo '<font color="red"><span dir="ltr">exec()</span> '.__('disabled', 'wp-dbmanager').'.</font>';
				$stats_function_disabled++;
			}
		?>
	</p>
	<p>
		<?php
			if($status_count == 5) {
				echo '<strong><font color="green">'.__('Excellent. You Are Good To Go.', 'wp-dbmanager').'</font></strong>';
			} else if($stats_function_disabled == 3) {
				echo '<strong><font color="red">'.__('I\'m sorry, your server administrator has disabled passthru(), system() and exec(), thus you cannot use this backup script. You may consider using the default WordPress database backup script instead.', 'wp-dbmanager').'</font></strong>';
			} else {
				echo '<strong><font color="red">'.__('Please Rectify The Error Highlighted In Red Before Proceeding On.', 'wp-dbmanager').'</font></strong>';
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
				<td><span dir="ltr"><?php echo stripslashes($backup['path']); ?></span></td>
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
