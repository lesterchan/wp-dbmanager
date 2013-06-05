<?php
/*
+----------------------------------------------------------------+
|																							|
|	WordPress 2.8 Plugin: WP-DBManager 2.63								|
|	Copyright (c) 2009 Lester "GaMerZ" Chan									|
|																							|
|	File Written By:																	|
|	- Lester "GaMerZ" Chan															|
|	- http://lesterchan.net															|
|																							|
|	File Information:																	|
|	- Database Restore																|
|	- wp-content/plugins/wp-dbmanager/database-restore.php			|
|																							|
+----------------------------------------------------------------+
*/


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
$backup['password'] = str_replace('$', '\$', DB_PASSWORD);


### Form Processing 
if($_POST['do']) {
	check_admin_referer('wp-dbmanager_manage');
	// Lets Prepare The Variables
	$database_file = trim($_POST['database_file']);
	$nice_file_date = mysql2date(sprintf(__('%s @ %s', 'wp-dbmanager'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', substr($database_file, 0, 10)));

	// Decide What To Do
	switch($_POST['do']) {
		case __('Restore', 'wp-dbmanager'):
			if(!empty($database_file)) {
				$brace = (substr(PHP_OS, 0, 3) == 'WIN') ? '"' : '';
				$backup['host'] = DB_HOST;
				$backup['port'] = '';
				$backup['sock'] = '';	
				if(strpos(DB_HOST, ':') !== false) {
					$db_host = explode(':', DB_HOST);
					$backup['host'] = $db_host[0];
					if(is_int($db_host[1])) {
						$backup['port'] = ' --port="'.intval($db_host[1]).'"';
					} else {
						$backup['sock'] = ' --socket="'.$db_host[1].'"';
					}
				}
				if(stristr($database_file, '.gz')) {
					$backup['command'] = 'gunzip < '.$brace.$backup['path'].'/'.$database_file.$brace.' | '.$brace.$backup['mysqlpath'].$brace.' --host="'.$backup['host'].'" --user="'.DB_USER.'" --password="'.$backup['password'].'"'.$backup['port'].$backup['sock'].' '.DB_NAME;
				} else {
					$backup['command'] = $brace.$backup['mysqlpath'].$brace.' --host="'.$backup['host'].'" --user="'.DB_USER.'" --password="'.$backup['password'].'"'.$backup['port'].$backup['sock'].' '.DB_NAME.' < '.$brace.$backup['path'].'/'.$database_file.$brace;
				}
				passthru($backup['command'], $error);
				if($error) {
					$text = '<font color="red">'.sprintf(__('Database On \'%s\' Failed To Restore', 'wp-dbmanager'), $nice_file_date).'</font>';
				} else {
					$text = '<font color="green">'.sprintf(__('Database On \'%s\' Restored Successfully', 'wp-dbmanager'), $nice_file_date).'</font>';
				}
			} else {
				$text = '<font color="red">'.__('No Backup Database File Selected', 'wp-dbmanager').'</font>';
			}
			break;
		case __('E-Mail', 'wp-dbmanager'):
			if(!empty($database_file)) {
				// Get And Read The Database Backup File
				$file_path = $backup['path'].'/'.$database_file;
				$file_size = format_size(filesize($file_path));
				$file_date = $nice_file_date;
				$file = fopen($file_path,'rb');
				$file_data = fread($file,filesize($file_path));
				fclose($file);
				$file_data = chunk_split(base64_encode($file_data));
				// Create Mail To, Mail Subject And Mail Header
				if(!empty($_POST['email_to'])) {
					$mail_to = trim($_POST['email_to']);
				} else {
					$mail_to = get_option('admin_email');
				}
				$mail_subject = sprintf(__('%s Database Backup File For %s', 'wp-dbmanager'), wp_specialchars_decode(get_option('blogname')), $file_date);
				$mail_header = 'From: '.wp_specialchars_decode(get_option('blogname')).' Administrator <'.get_option('admin_email').'>';
				// MIME Boundary
				$random_time = md5(time());
				$mime_boundary = "==WP-DBManager- $random_time";
				// Create Mail Header And Mail Message
				$mail_header .= "\nMIME-Version: 1.0\n" .
										"Content-Type: multipart/mixed;\n" .
										" boundary=\"{$mime_boundary}\"";
				$mail_message = __('Website Name:', 'wp-dbmanager').' '.wp_specialchars_decode(get_option('blogname'))."\n".
										__('Website URL:', 'wp-dbmanager').' '.get_bloginfo('siteurl')."\n".
										__('Backup File Name:', 'wp-dbmanager').' '.$database_file."\n".
										__('Backup File Date:', 'wp-dbmanager').' '.$file_date."\n".
										__('Backup File Size:', 'wp-dbmanager').' '.$file_size."\n\n".
										__('With Regards,', 'wp-dbmanager')."\n".
										wp_specialchars_decode(get_option('blogname')).' '. __('Administrator', 'wp-dbmanager')."\n".
										get_bloginfo('siteurl');
				$mail_message = "This is a multi-part message in MIME format.\n\n" .
										"--{$mime_boundary}\n" .
										"Content-Type: text/plain; charset=\"utf-8\"\n" .
										"Content-Transfer-Encoding: 7bit\n\n".$mail_message."\n\n";				
				$mail_message .= "--{$mime_boundary}\n" .
										"Content-Type: application/octet-stream;\n" .
										" name=\"$database_file\"\n" .
										"Content-Disposition: attachment;\n" .
										" filename=\"$database_file\"\n" .
										"Content-Transfer-Encoding: base64\n\n" .
										$file_data."\n\n--{$mime_boundary}--\n";
				if(mail($mail_to, $mail_subject, $mail_message, $mail_header)) {
					$text .= '<font color="green">'.sprintf(__('Database Backup File For \'%s\' Successfully E-Mailed To \'%s\'', 'wp-dbmanager'), $file_date, $mail_to).'</font><br />';
				} else {
					$text = '<font color="red">'.sprintf(__('Unable To E-Mail Database Backup File For \'%s\' To \'%s\'', 'wp-dbmanager'), $file_date, $mail_to).'</font>';
				}
			} else {
				$text = '<font color="red">'.__('No Backup Database File Selected', 'wp-dbmanager').'</font>';
			}
			break;
		case __('Download', 'wp-dbmanager'):
			if(empty($database_file)) {
				$text = '<font color="red">'.__('No Backup Database File Selected', 'wp-dbmanager').'</font>';
			}
			break;
		case __('Delete', 'wp-dbmanager'):
			if(!empty($database_file)) {
				if(is_file($backup['path'].'/'.$database_file)) {
					if(!unlink($backup['path'].'/'.$database_file)) {
						$text .= '<font color="red">'.sprintf(__('Unable To Delete Database Backup File On \'%s\'', 'wp-dbmanager'), $nice_file_date).'</font><br />';
					} else {
						$text .= '<font color="green">'.sprintf(__('Database Backup File On \'%s\' Deleted Successfully', 'wp-dbmanager'), $nice_file_date).'</font><br />';
					}
				} else {
					$text = '<font color="red">'.sprintf(__('Invalid Database Backup File On \'%s\'', 'wp-dbmanager'), $nice_file_date).'</font>';
				}
			} else {
				$text = '<font color="red">'.__('No Backup Database File Selected', 'wp-dbmanager').'</font>';
			}
			break;
	}
}
?>
<?php if(!empty($text)) { echo '<!-- Last Action --><div id="message" class="updated fade"><p>'.$text.'</p></div>'; } ?>
<!-- Manage Backup Database -->
<form method="post" action="<?php echo admin_url('admin.php?page='.plugin_basename(__FILE__)); ?>">
	<?php wp_nonce_field('wp-dbmanager_manage'); ?>
	<div class="wrap">
		<div id="icon-wp-dbmanager" class="icon32"><br /></div>
		<h2><?php _e('Manage Backup Database', 'wp-dbmanager'); ?></h2>
		<p><?php _e('Choose A Backup Date To E-Mail, Restore, Download Or Delete', 'wp-dbmanager'); ?></p>	
		<table class="widefat">
			<thead>
				<tr>
					<th><?php _e('No.', 'wp-dbmanager'); ?></th>
					<th><?php _e('Database File', 'wp-dbmanager'); ?></th>
					<th><?php _e('Date/Time', 'wp-dbmanager'); ?></th>
					<th><?php _e('Size', 'wp-dbmanager'); ?></th>
					<th><?php _e('Select', 'wp-dbmanager'); ?></th>
				</tr>
			</thead>
			<?php
				if(!is_emtpy_folder($backup['path'])) {
					if ($handle = opendir($backup['path'])) {
						$database_files = array();
						while (false !== ($file = readdir($handle))) { 
							if ($file != '.' && $file != '..' && $file != '.htaccess' && (file_ext($file) == 'sql' || file_ext($file) == 'gz')) {
								$database_files[] = $file;
							} 
						}
						closedir($handle);
						sort($database_files);
						for($i = (sizeof($database_files)-1); $i > -1; $i--) {
							if($no%2 == 0) {
								$style = '';								
							} else {
								$style = ' class="alternate"';
							}
							$no++;
							$database_text = substr($database_files[$i], 13);
							$date_text = mysql2date(sprintf(__('%s @ %s', 'wp-dbmanager'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', substr($database_files[$i], 0, 10)));
							$size_text = filesize($backup['path'].'/'.$database_files[$i]);
							echo "<tr$style>\n";
							echo '<td>'.number_format_i18n($no).'</td>';
							echo "<td>$database_text</td>";
							echo "<td>$date_text</td>";
							echo '<td>'.format_size($size_text).'</td>';
							echo "<td><input type=\"radio\" name=\"database_file\" value=\"$database_files[$i]\" /></td>\n</tr>\n";
							$totalsize += $size_text;
						}
					} else {
						echo '<tr><td align="center" colspan="5">'.__('There Are No Database Backup Files Available.', 'wp-dbmanager').'</td></tr>';
					}
				} else {
					echo '<tr><td align="center" colspan="5">'.__('There Are No Database Backup Files Available.', 'wp-dbmanager').'</td></tr>';
				}
			?>
			<tr class="thead">
				<th colspan="3"><?php printf(_n('%s Backup File', '%s Backup Files', $no, 'wp-dbmanager'), number_format_i18n($no)); ?></th>
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
					<input type="button" name="cancel" value="<?php _e('Cancel', 'wp-dbmanager'); ?>" class="button" onclick="javascript:history.go(-1)" /></td>
			</tr>					
		</table>
	</div>
</form>