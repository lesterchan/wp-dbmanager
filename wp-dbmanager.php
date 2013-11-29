<?php
/*
Plugin Name: WP-DBManager
Plugin URI: http://lesterchan.net/portfolio/programming/php/
Description: Manages your WordPress database. Allows you to optimize database, repair database, backup database, restore database, delete backup database , drop/empty tables and run selected queries. Supports automatic scheduling of backing up, optimizing and repairing of database.
Version: 2.65
Author: Lester 'GaMerZ' Chan
Author URI: http://lesterchan.net
Text Domain: wp-dbmanager
*/


/*
	Copyright 2013  Lester Chan  (email : lesterchan@gmail.com)

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


### Create Text Domain For Translations
add_action('init', 'dbmanager_textdomain');
function dbmanager_textdomain() {
	load_plugin_textdomain('wp-dbmanager', false, 'wp-dbmanager');
}


### Function: Database Manager Menu
add_action('admin_menu', 'dbmanager_menu');
function dbmanager_menu() {
	if (function_exists('add_menu_page')) {
		add_menu_page(__('Database', 'wp-dbmanager'), __('Database', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-manager.php', '', plugins_url('wp-dbmanager/images/database.png'));
	}
	if (function_exists('add_submenu_page')) {
		add_submenu_page('wp-dbmanager/database-manager.php', __('Backup DB', 'wp-dbmanager'), __('Backup DB', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-backup.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Manage Backup DB', 'wp-dbmanager'), __('Manage Backup DB', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-manage.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Optimize DB', 'wp-dbmanager'), __('Optimize DB', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-optimize.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Repair DB', 'wp-dbmanager'), __('Repair DB', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-repair.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Empty/Drop Tables', 'wp-dbmanager'), __('Empty/Drop Tables', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-empty.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Run SQL Query', 'wp-dbmanager'), __('Run SQL Query', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-run.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('DB Options', 'wp-dbmanager'),  __('DB Options', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/wp-dbmanager.php', 'dbmanager_options');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Uninstall WP-DBManager', 'wp-dbmanager'), __('Uninstall WP-DBManager', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-uninstall.php');
	}
}


### Function: Displays DBManager Header In WP-Admin
add_action('admin_enqueue_scripts', 'dbmanager_stylesheets_admin');
function dbmanager_stylesheets_admin($hook_suffix) {
	$dbmanager_admin_pages = array('wp-dbmanager/database-manager.php', 'wp-dbmanager/database-backup.php', 'wp-dbmanager/database-manage.php', 'wp-dbmanager/database-optimize.php', 'wp-dbmanager/database-repair.php', 'wp-dbmanager/database-empty.php', 'wp-dbmanager/database-run.php', 'database_page_wp-dbmanager/wp-dbmanager', 'wp-dbmanager/database-uninstall.php');
	if(in_array($hook_suffix, $dbmanager_admin_pages)) {
		wp_enqueue_style('wp-dbmanager-admin', plugins_url('wp-dbmanager/database-admin-css.css'), false, '2.63', 'all');
	}
}


### Funcion: Database Manager Cron
add_filter('cron_schedules', 'cron_dbmanager_reccurences');
add_action('dbmanager_cron_backup', 'cron_dbmanager_backup');
add_action('dbmanager_cron_optimize', 'cron_dbmanager_optimize');
add_action('dbmanager_cron_repair', 'cron_dbmanager_repair');
function cron_dbmanager_backup() {
	global $wpdb;
	$backup_options = get_option('dbmanager_options');
	$backup_email = stripslashes($backup_options['backup_email']);
	if(intval($backup_options['backup_period']) > 0) {
		$current_date = mysql2date(sprintf(__('%s @ %s', 'wp-dbmanager'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', current_time('timestamp')));
		$backup = array();
		$backup['date'] = current_time('timestamp');
		$backup['mysqldumppath'] = $backup_options['mysqldumppath'];
		$backup['mysqlpath'] = $backup_options['mysqlpath'];
		$backup['path'] = $backup_options['path'];
		$backup['password'] = str_replace('$', '\$', DB_PASSWORD);
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
		$backup['command'] = '';
		$brace = (substr(PHP_OS, 0, 3) == 'WIN') ? '"' : '';
		if(intval($backup_options['backup_gzip']) == 1) {
			$backup['filename'] = $backup['date'].'_-_'.DB_NAME.'.sql.gz';
			$backup['filepath'] = $backup['path'].'/'.$backup['filename'];
			$backup['command'] = $brace.$backup['mysqldumppath'].$brace.' --force --host="'.$backup['host'].'" --user="'.DB_USER.'" --password="'.$backup['password'].'"'.$backup['port'].$backup['sock'].' --add-drop-table --skip-lock-tables '.DB_NAME.' | gzip > '.$brace.$backup['filepath'].$brace;
		} else {
			$backup['filename'] = $backup['date'].'_-_'.DB_NAME.'.sql';
			$backup['filepath'] = $backup['path'].'/'.$backup['filename'];
			$backup['command'] = $brace.$backup['mysqldumppath'].$brace.' --force --host="'.$backup['host'].'" --user="'.DB_USER.'" --password="'.$backup['password'].'"'.$backup['port'].$backup['sock'].' --add-drop-table --skip-lock-tables '.DB_NAME.' > '.$brace.$backup['filepath'].$brace;
		}
		execute_backup($backup['command']);
		if(!empty($backup_email)) {
				// Get And Read The Database Backup File
				$file_path = $backup['filepath'];
				$file_size = format_size(filesize($file_path));
				$file_date = mysql2date(sprintf(__('%s @ %s', 'wp-dbmanager'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', substr($backup['filename'], 0, 10)));
				$file = fopen($file_path,'rb');
				$file_data = fread($file,filesize($file_path));
				fclose($file);
				$file_data = chunk_split(base64_encode($file_data));
				// Create Mail To, Mail Subject And Mail Header
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
										__('Backup File Name:', 'wp-dbmanager').' '.$backup['filename']."\n".
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
										" name=\"{$backup['filename']}\"\n" .
										"Content-Disposition: attachment;\n" .
										" filename=\"{$backup['filename']}\"\n" .
										"Content-Transfer-Encoding: base64\n\n" .
										$file_data."\n\n--{$mime_boundary}--\n";
			mail($backup_email, $mail_subject, $mail_message, $mail_header);
		}
	}
	return;
}
function cron_dbmanager_optimize() {
	global $wpdb;
	$backup_options = get_option('dbmanager_options');
	$optimize = intval($backup_options['optimize']);
	$optimize_period = intval($backup_options['optimize_period']);
	if($optimize_period > 0) {
		$optimize_tables = array();
		$tables = $wpdb->get_col("SHOW TABLES");
			foreach($tables as $table_name) {
				$optimize_tables[] = '`'.$table_name.'`';
		}
		$wpdb->query('OPTIMIZE TABLE '.implode(',', $optimize_tables));
	}
	return;
}
function cron_dbmanager_repair() {
	global $wpdb;
	$backup_options = get_option('dbmanager_options');
	$repair = intval($backup_options['repair']);
	$repair_period = intval($backup_options['repair_period']);
	if($repair_period > 0) {
		$repair_tables = array();
		$tables = $wpdb->get_col("SHOW TABLES");
			foreach($tables as $table_name) {
				$repair_tables[] = '`'.$table_name.'`';
		}
		$wpdb->query('REPAIR TABLE '.implode(',', $repair_tables));
	}
	return;
}
function cron_dbmanager_reccurences($schedules) {
	$backup_options = get_option('dbmanager_options');
	$backup = intval($backup_options['backup'])*intval($backup_options['backup_period']);
	$optimize = intval($backup_options['optimize'])*intval($backup_options['optimize_period']);
	$repair = intval($backup_options['repair'])*intval($backup_options['repair_period']);
	if($backup == 0) {
		$backup = 31536000;
	}
	if($optimize == 0) {
		$optimize = 31536000;
	}
	if($repair == 0) {
		$repair = 31536000;
	}
   $schedules['dbmanager_backup'] = array('interval' => $backup, 'display' => __('WP-DBManager Backup Schedule', 'wp-dbmanager'));
   $schedules['dbmanager_optimize'] = array('interval' => $optimize, 'display' => __('WP-DBManager Optimize Schedule', 'wp-dbmanager'));
   $schedules['dbmanager_repair'] = array('interval' => $repair, 'display' => __('WP-DBManager Repair Schedule', 'wp-dbmanager'));
   return $schedules;
}


### Function: Ensure .htaccess Is In The Backup Folder
add_action('admin_notices', 'dbmanager_admin_notices');
function dbmanager_admin_notices() {
	$backup_options = get_option('dbmanager_options');
	if(!@file_exists($backup_options['path'].'/.htaccess')) {
		echo '<div class="error" style="text-align: center;"><p style="color: red; font-size: 14px; font-weight: bold;">'.__('Your backup folder MIGHT be visible to the public', 'wp-postratings').'</p><p>'.sprintf(__('To correct this issue, move the <strong>.htaccess</strong> file from <strong>wp-content/plugins/wp-dbmanager</strong> to <strong>%s</strong>', 'wp-postratings'), $backup_options['path']).'</p></div>';
	}
}


### Function: Auto Detect MYSQL and MYSQL Dump Paths
function detect_mysql() {
	global $wpdb;
	$paths = array('mysq' => '', 'mysqldump' => '');
	if(substr(PHP_OS,0,3) == 'WIN') {
		$mysql_install = $wpdb->get_row("SHOW VARIABLES LIKE 'basedir'");
		if($mysql_install) {
			$install_path = str_replace('\\', '/', $mysql_install->Value);
			$paths['mysql'] = $install_path.'bin/mysql.exe';
			$paths['mysqldump'] = $install_path.'bin/mysqldump.exe';
		} else {
			$paths['mysql'] = 'mysql.exe';
			$paths['mysqldump'] = 'mysqldump.exe';
		}
	} else {
		if(function_exists('exec')) {
			$paths['mysql'] = @exec('which mysql');
			$paths['mysqldump'] = @exec('which mysqldump');
		} else {
			$paths['mysql'] = 'mysql';
			$paths['mysqldump'] = 'mysqldump';
		}
	}
	return $paths;
}


### Executes OS-Dependent mysqldump Command (By: Vlad Sharanhovich)
function execute_backup($command) {
	$backup_options = get_option('dbmanager_options');
	check_backup_files();
	if(substr(PHP_OS, 0, 3) == 'WIN') {
		$writable_dir = $backup_options['path'];
		$tmpnam = $writable_dir.'/wp-dbmanager.bat';
		$fp = fopen($tmpnam, 'w');
		fwrite($fp, $command);
		fclose($fp);
		system($tmpnam.' > NUL', $error);
		unlink($tmpnam);
	} else {
		passthru($command, $error);
	}
	return $error;
}


### Function: Format Bytes Into KB/MB
if(!function_exists('format_size')) {
	function format_size($rawSize) {
		if($rawSize / 1073741824 > 1)
			return number_format_i18n($rawSize/1048576, 1) . ' '.__('GiB', 'wp-dbmanager');
		else if ($rawSize / 1048576 > 1)
			return number_format_i18n($rawSize/1048576, 1) . ' '.__('MiB', 'wp-dbmanager');
		else if ($rawSize / 1024 > 1)
			return number_format_i18n($rawSize/1024, 1) . ' '.__('KiB', 'wp-dbmanager');
		else
			return number_format_i18n($rawSize, 0) . ' '.__('bytes', 'wp-dbmanager');
	}
}


### Function: Get File Extension
if(!function_exists('file_ext')) {
	function file_ext($file_name) {
		return substr(strrchr($file_name, '.'), 1);
	}
}


### Function: Check Folder Whether There Is Any File Inside
if(!function_exists('is_emtpy_folder')) {
	function is_emtpy_folder($folder){
	   if(is_dir($folder) ){
		   $handle = opendir($folder);
		   while( (gettype( $name = readdir($handle)) != 'boolean')){
				if($name != '.htaccess') {
					$name_array[] = $name;
				}
		   }
		   foreach($name_array as $temp)
			   $folder_content .= $temp;

		   if($folder_content == '...')
			   return true;
		   else
			   return false;
		   closedir($handle);
	   }
	   else
		   return true;
	}
}


### Function: Make Sure Maximum Number Of Database Backup Files Does Not Exceed
function check_backup_files() {
	$backup_options = get_option('dbmanager_options');
	$database_files = array();
	if(!is_emtpy_folder($backup_options['path'])) {
		if ($handle = opendir($backup_options['path'])) {
			while (false !== ($file = readdir($handle))) {
				if ($file != '.' && $file != '..' && (file_ext($file) == 'sql' || file_ext($file) == 'gz')) {
					$database_files[] = $file;
				}
			}
			closedir($handle);
			sort($database_files);
		}
	}
	if(sizeof($database_files) >= $backup_options['max_backup']) {
		@unlink($backup_options['path'].'/'.$database_files[0]);
	}
}


### Function: Database Manager Role
add_action('activate_wp-dbmanager/wp-dbmanager.php', 'dbmanager_init');
function dbmanager_init() {
	global $wpdb;
	$auto = detect_mysql();
	// Add Options
	$backup_options = array();
	$backup_options['mysqldumppath'] = $auto['mysqldump'];
	$backup_options['mysqlpath'] = $auto['mysql'];
	$backup_options['path'] = str_replace('\\', '/', WP_CONTENT_DIR).'/backup-db';
	$backup_options['max_backup'] = 10;
	$backup_options['backup'] = 1;
	$backup_options['backup_gzip'] = 0;
	$backup_options['backup_period'] = 604800;
	$backup_options['backup_email'] = get_option('admin_email');
	$backup_options['optimize'] = 3;
	$backup_options['optimize_period'] = 86400;
	$backup_options['repair'] = 2;
	$backup_options['repair_period'] = 604800;
	add_option('dbmanager_options', $backup_options, 'WP-DBManager Options');

	// Create Backup Folder
	if(!is_dir(WP_CONTENT_DIR.'/backup-db')) {
		mkdir(WP_CONTENT_DIR.'/backup-db');
		chmod(WP_CONTENT_DIR.'/backup-db', 0750);
	}

	// Set 'manage_database' Capabilities To Administrator
	$role = get_role('administrator');
	if(!$role->has_cap('manage_database')) {
		$role->add_cap('manage_database');
	}
}


### Function: Download Database
add_action('init', 'download_database');
function download_database() {
	if(isset($_POST['do']) && $_POST['do'] == __('Download', 'wp-dbmanager') && !empty($_POST['database_file'])) {
		if(strpos($_SERVER['HTTP_REFERER'], admin_url('admin.php?page=wp-dbmanager/database-manage.php')) !== false) {
			$database_file = trim($_POST['database_file']);
			if(substr($database_file, strlen($database_file) - 4, 4) == '.sql' || substr($database_file, strlen($database_file) - 7, 7) == '.sql.gz') {
				check_admin_referer('wp-dbmanager_manage');
				$backup_options = get_option('dbmanager_options');
				$clean_file_name = sanitize_file_name($database_file);
				$clean_file_name = str_replace('sql_.gz', 'sql.gz', $clean_file_name);
				$file_path = $backup_options['path'].'/'.$clean_file_name;
				header("Pragma: public");
				header("Expires: 0");
				header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
				header("Content-Type: application/force-download");
				header("Content-Type: application/octet-stream");
				header("Content-Type: application/download");
				header("Content-Disposition: attachment; filename=".basename($file_path).";");
				header("Content-Transfer-Encoding: binary");
				header("Content-Length: ".filesize($file_path));
				@readfile($file_path);
			}
		}
		exit();
	}
}


### Function: Database Options
function dbmanager_options() {
	global $wpdb;
	$text = '';
	$backup_options = array();
	$backup_options = get_option('dbmanager_options');
	if($_POST['Submit']) {
		check_admin_referer('wp-dbmanager_options');
		$backup_options['mysqldumppath'] = trim($_POST['db_mysqldumppath']);
		$backup_options['mysqlpath'] = trim($_POST['db_mysqlpath']);
		$backup_options['path'] = trim($_POST['db_path']);
		$backup_options['max_backup'] = intval($_POST['db_max_backup']);
		$backup_options['backup'] = intval($_POST['db_backup']);
		$backup_options['backup_gzip'] = intval($_POST['db_backup_gzip']);
		$backup_options['backup_period'] = intval($_POST['db_backup_period']);
		$backup_options['backup_email'] = trim(addslashes($_POST['db_backup_email']));
		$backup_options['optimize'] = intval($_POST['db_optimize']);
		$backup_options['optimize_period'] = intval($_POST['db_optimize_period']);
		$backup_options['repair'] = intval($_POST['db_repair']);
		$backup_options['repair_period'] = intval($_POST['db_repair_period']);
		$update_db_options = update_option('dbmanager_options', $backup_options);
		if($update_db_options) {
			$text = '<font color="green">'.__('Database Options Updated', 'wp-dbmanager').'</font>';
		}
		if(empty($text)) {
			$text = '<font color="red">'.__('No Database Option Updated', 'wp-dbmanager').'</font>';
		}
		wp_clear_scheduled_hook('dbmanager_cron_backup');
		if($backup_options['backup_period'] > 0) {
			if (!wp_next_scheduled('dbmanager_cron_backup')) {
				wp_schedule_event(time(), 'dbmanager_backup', 'dbmanager_cron_backup');
			}
		}
		wp_clear_scheduled_hook('dbmanager_cron_optimize');
		if($backup_options['optimize_period'] > 0) {
			if (!wp_next_scheduled('dbmanager_cron_optimize')) {
				wp_schedule_event(time(), 'dbmanager_optimize', 'dbmanager_cron_optimize');
			}
		}
		wp_clear_scheduled_hook('dbmanager_cron_repair');
		if($backup_options['repair_period'] > 0) {
			if (!wp_next_scheduled('dbmanager_cron_repair')) {
				wp_schedule_event(time(), 'dbmanager_repair', 'dbmanager_cron_repair');
			}
		}
	}
	$path = detect_mysql();
?>
<script type="text/javascript">
/* <![CDATA[*/
	function mysqlpath() {
		jQuery("#db_mysqlpath").val("<?php echo $path['mysql']; ?>");
	}
	function mysqldumppath() {
		jQuery("#db_mysqldumppath").val("<?php echo $path['mysqldump']; ?>");
	}
/* ]]> */
</script>
<?php if(!empty($text)) { echo '<!-- Last Action --><div id="message" class="updated fade"><p>'.$text.'</p></div>'; } ?>
<!-- Database Options -->
<form method="post" action="<?php echo admin_url('admin.php?page='.plugin_basename(__FILE__)); ?>">
	<?php wp_nonce_field('wp-dbmanager_options'); ?>
	<div class="wrap">
		<div id="icon-wp-dbmanager" class="icon32"><br /></div>
		<h2><?php _e('Database Options', 'wp-dbmanager'); ?></h2>
		<h3><?php _e('Paths', 'wp-dbmanager'); ?></h3>
		<table class="form-table">
			<tr>
				<td width="20%" valign="top"><strong><?php _e('Path To mysqldump:', 'wp-dbmanager'); ?></strong></td>
				<td width="80%">
					<input type="text" id="db_mysqldumppath" name="db_mysqldumppath" size="60" maxlength="100" value="<?php echo stripslashes($backup_options['mysqldumppath']); ?>" dir="ltr" />&nbsp;&nbsp;<input type="button" value="<?php _e('Auto Detect', 'wp-dbmanager'); ?>" onclick="mysqldumppath();" />
					<p><?php _e('The absolute path to mysqldump without trailing slash. If unsure, please email your server administrator about this.', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php _e('Path To mysql:', 'wp-dbmanager'); ?></strong></td>
				<td>
					<input type="text" id="db_mysqlpath" name="db_mysqlpath" size="60" maxlength="100" value="<?php echo stripslashes($backup_options['mysqlpath']); ?>" dir="ltr" />&nbsp;&nbsp;<input type="button" value="<?php _e('Auto Detect', 'wp-dbmanager'); ?>" onclick="mysqlpath();" />
					<p><?php _e('The absolute path to mysql without trailing slash. If unsure, please email your server administrator about this.', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php _e('Path To Backup:', 'wp-dbmanager'); ?></strong></td>
				<td>
					<input type="text" name="db_path" size="60" maxlength="100" value="<?php echo stripslashes($backup_options['path']); ?>" dir="ltr" />
					<p><?php _e('The absolute path to your database backup folder without trailing slash. Make sure the folder is writable.', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php _e('Maximum Backup Files:', 'wp-dbmanager'); ?></strong></td>
				<td>
					<input type="text" name="db_max_backup" size="5" maxlength="5" value="<?php echo stripslashes($backup_options['max_backup']); ?>" />
					<p><?php _e('The maximum number of database backup files that is allowed in the backup folder as stated above. The oldest database backup file is always deleted in order to maintain this value. This is to prevent the backup folder from getting too large.', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php _e('Note', 'wp-dbmanager'); ?></h3>
		<table class="form-table">
			<tr>
				<td>
					<strong><?php _e('Windows Server', 'wp-dbmanager'); ?></strong><br />
					<?php _e('For mysqldump path, you can try \'<strong>mysqldump.exe</strong>\'.', 'wp-dbmanager'); ?><br />
					<?php _e('For mysql path, you can try \'<strong>mysql.exe</strong>\'.', 'wp-dbmanager'); ?>
				</td>
			</tr>
			<tr>
				<td>
					<strong><?php _e('Linux Server', 'wp-dbmanager'); ?></strong><br />
					<?php _e('For mysqldump path, normally is just \'<strong>mysqldump</strong>\'.', 'wp-dbmanager'); ?><br />
					<?php _e('For mysql path, normally is just \'<strong>mysql</strong>\'.', 'wp-dbmanager'); ?>
				</td>
			</tr>
			<tr>
				<td>
					<strong><?php _e('Note', 'wp-dbmanager'); ?></strong><br />
					<?php _e('The \'Auto Detect\' function does not work for some servers. If it does not work for you, please contact your server administrator for the MYSQL and MYSQL DUMP paths.', 'wp-dbmanager'); ?>
				</td>
			</tr>
		</table>

		<h3><?php _e('Automatic Scheduling', 'wp-dbmanager'); ?></h3>
		<table class="form-table">
			<tr>
				<td valign="top"><strong><?php _e('Automatic Backing Up Of DB:', 'wp-dbmanager'); ?></strong></td>
				<td>
					<?php
						_e('Next backup date: ', 'wp-dbmanager');
						if(wp_next_scheduled('dbmanager_cron_backup')) {
							echo '<strong>'.mysql2date(sprintf(__('%s @ %s', 'wp-dbmanager'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', (wp_next_scheduled('dbmanager_cron_backup') + (get_option('gmt_offset') * 3600)))).'</strong>';
						} else {
							_e('N/A', 'wp-dbmanager');
						}
					?>
					<p>
						<?php _e('Every', 'wp-dbmanager'); ?>&nbsp;<input type="text" name="db_backup" size="3" maxlength="5" value="<?php echo intval($backup_options['backup']); ?>" />&nbsp;
					<select name="db_backup_period" size="1">
						<option value="0"<?php selected('0', $backup_options['backup_period']); ?>><?php _e('Disable', 'wp-dbmanager'); ?></option>
						<option value="60"<?php selected('60', $backup_options['backup_period']); ?>><?php _e('Minutes(s)', 'wp-dbmanager'); ?></option>
						<option value="3600"<?php selected('3600', $backup_options['backup_period']); ?>><?php _e('Hour(s)', 'wp-dbmanager'); ?></option>
						<option value="86400"<?php selected('86400', $backup_options['backup_period']); ?>><?php _e('Day(s)', 'wp-dbmanager'); ?></option>
						<option value="604800"<?php selected('604800', $backup_options['backup_period']); ?>><?php _e('Week(s)', 'wp-dbmanager'); ?></option>
						<option value="18144000"<?php selected('18144000', $backup_options['backup_period']); ?>><?php _e('Month(s)', 'wp-dbmanager'); ?></option>
					</select>&nbsp;&nbsp;&nbsp;
					<?php _e('Gzip', 'wp-dbmanager'); ?>
					<select name="db_backup_gzip" size="1">
						<option value="0"<?php selected('0', $backup_options['backup_gzip']); ?>><?php _e('No', 'wp-dbmanager'); ?></option>
						<option value="1"<?php selected('1', $backup_options['backup_gzip']); ?>><?php _e('Yes', 'wp-dbmanager'); ?></option>
					</select>
					</p>
					<p><?php _e('E-mail backup to:', 'wp-dbmanager'); ?> <input type="text" name="db_backup_email" size="30" maxlength="50" value="<?php echo stripslashes($backup_options['backup_email']) ?>" dir="ltr" />&nbsp;&nbsp;&nbsp;<?php _e('(Leave blank to disable this feature)', 'wp-dbmanager'); ?></p>
					<p><?php _e('WP-DBManager can automatically backup your database after a certain period.', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php _e('Automatic Optimizing Of DB:', 'wp-dbmanager'); ?></strong></td>
				<td>
					<?php
						_e('Next optimize date: ', 'wp-dbmanager');
						if(wp_next_scheduled('dbmanager_cron_optimize')) {
							echo '<strong>'.mysql2date(sprintf(__('%s @ %s', 'wp-dbmanager'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', (wp_next_scheduled('dbmanager_cron_optimize') + (get_option('gmt_offset') * 3600)))).'</strong>';
						} else {
							_e('N/A', 'wp-dbmanager');
						}
					?>
					<p>
					<?php _e('Every', 'wp-dbmanager'); ?>&nbsp;<input type="text" name="db_optimize" size="3" maxlength="5" value="<?php echo intval($backup_options['optimize']); ?>" />&nbsp;
					<select name="db_optimize_period" size="1">
						<option value="0"<?php selected('0', $backup_options['optimize_period']); ?>><?php _e('Disable', 'wp-dbmanager'); ?></option>
						<option value="60"<?php selected('60', $backup_options['optimize_period']); ?>><?php _e('Minutes(s)', 'wp-dbmanager'); ?></option>
						<option value="3600"<?php selected('3600', $backup_options['optimize_period']); ?>><?php _e('Hour(s)', 'wp-dbmanager'); ?></option>
						<option value="86400"<?php selected('86400', $backup_options['optimize_period']); ?>><?php _e('Day(s)', 'wp-dbmanager'); ?></option>
						<option value="604800"<?php selected('604800', $backup_options['optimize_period']); ?>><?php _e('Week(s)', 'wp-dbmanager'); ?></option>
						<option value="18144000"<?php selected('18144000', $backup_options['optimize_period']); ?>><?php _e('Month(s)', 'wp-dbmanager'); ?></option>
					</select>
					</p>
					<p><?php _e('WP-DBManager can automatically optimize your database after a certain period.', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php _e('Automatic Repairing Of DB:', 'wp-dbmanager'); ?></strong></td>
				<td>
					<?php
						_e('Next repair date: ', 'wp-dbmanager');
						if(wp_next_scheduled('dbmanager_cron_repair')) {
							echo '<strong>'.mysql2date(sprintf(__('%s @ %s', 'wp-dbmanager'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', (wp_next_scheduled('dbmanager_cron_repair') + (get_option('gmt_offset') * 3600)))).'</strong>';
						} else {
							_e('N/A', 'wp-dbmanager');
						}
					?>
					<p>
					<?php _e('Every', 'wp-dbmanager'); ?>&nbsp;<input type="text" name="db_repair" size="3" maxlength="5" value="<?php echo intval($backup_options['repair']); ?>" />&nbsp;
					<select name="db_repair_period" size="1">
						<option value="0"<?php selected('0', $backup_options['repair_period']); ?>><?php _e('Disable', 'wp-dbmanager'); ?></option>
						<option value="60"<?php selected('60', $backup_options['repair_period']); ?>><?php _e('Minutes(s)', 'wp-dbmanager'); ?></option>
						<option value="3600"<?php selected('3600', $backup_options['repair_period']); ?>><?php _e('Hour(s)', 'wp-dbmanager'); ?></option>
						<option value="86400"<?php selected('86400', $backup_options['repair_period']); ?>><?php _e('Day(s)', 'wp-dbmanager'); ?></option>
						<option value="604800"<?php selected('604800', $backup_options['repair_period']); ?>><?php _e('Week(s)', 'wp-dbmanager'); ?></option>
						<option value="18144000"<?php selected('18144000', $backup_options['repair_period']); ?>><?php _e('Month(s)', 'wp-dbmanager'); ?></option>
					</select>
					</p>
					<p><?php _e('WP-DBManager can automatically repair your database after a certain period.', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
		</table>
		<p class="submit">
			<input type="submit" name="Submit" class="button" value="<?php _e('Save Changes', 'wp-dbmanager'); ?>" />
		</p>
	</div>
</form>
<?php
}
?>