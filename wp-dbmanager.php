<?php
/*
Plugin Name: WP-DBManager
Plugin URI: https://lesterchan.net/portfolio/programming/php/
Description: Manages your WordPress database. Allows you to optimize database, repair database, backup database, restore database, delete backup database , drop/empty tables and run selected queries. Supports automatic scheduling of backing up, optimizing and repairing of database.
Version: 2.80.3
Author: Lester 'GaMerZ' Chan
Author URI: https://lesterchan.net
Text Domain: wp-dbmanager
*/


/*
    Copyright 2020  Lester Chan  (email : lesterchan@gmail.com)

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
add_action( 'plugins_loaded', 'dbmanager_textdomain' );
function dbmanager_textdomain() {
	load_plugin_textdomain( 'wp-dbmanager', false, dirname( plugin_basename( __FILE__ ) ) );
}


### Function: Database Manager Menu
add_action('admin_menu', 'dbmanager_menu');
function dbmanager_menu() {
	if (function_exists('add_menu_page')) {
		add_menu_page(__('Database', 'wp-dbmanager'), __('Database', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-manager.php', '', 'dashicons-archive');
	}
	if (function_exists('add_submenu_page')) {
		add_submenu_page('wp-dbmanager/database-manager.php', __('Backup DB', 'wp-dbmanager'), __('Backup DB', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-backup.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Manage Backup DB', 'wp-dbmanager'), __('Manage Backup DB', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-manage.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Optimize DB', 'wp-dbmanager'), __('Optimize DB', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-optimize.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Repair DB', 'wp-dbmanager'), __('Repair DB', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-repair.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Empty/Drop Tables', 'wp-dbmanager'), __('Empty/Drop Tables', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-empty.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('Run SQL Query', 'wp-dbmanager'), __('Run SQL Query', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/database-run.php');
		add_submenu_page('wp-dbmanager/database-manager.php', __('DB Options', 'wp-dbmanager'),  __('DB Options', 'wp-dbmanager'), 'manage_database', 'wp-dbmanager/wp-dbmanager.php', 'dbmanager_options');
	}
}


### Function: Append get_allowed_mime_types()
add_filter( 'upload_mimes', 'dbmanager_upload_mimes' );
function dbmanager_upload_mimes( $mime_types ) {
	$mime_types['sql'] = 'application/sql';
	return $mime_types;
}

### Function: Database Manager Cron
add_filter('cron_schedules', 'cron_dbmanager_reccurences');
add_action('dbmanager_cron_backup', 'cron_dbmanager_backup');
add_action('dbmanager_cron_optimize', 'cron_dbmanager_optimize');
add_action('dbmanager_cron_repair', 'cron_dbmanager_repair');
function cron_dbmanager_backup() {
	$backup_options = get_option('dbmanager_options');
	$backup_email = stripslashes($backup_options['backup_email']);
	if ( (int) $backup_options['backup_period'] > 0 ) {
		$backup = array();
		$backup['date'] = current_time('timestamp');
		$backup['mysqldumppath'] = $backup_options['mysqldumppath'];
		$backup['mysqlpath'] = $backup_options['mysqlpath'];
		$backup['path'] = $backup_options['path'];
		$backup['charset'] = ' --default-character-set="utf8"';
		$backup['host'] = DB_HOST;
		$backup['port'] = '';
		$backup['sock'] = '';
		if ( strpos( DB_HOST, ':' ) !== false ) {
			$db_host = explode(':', DB_HOST);
			$backup['host'] = $db_host[0];
			if ( (int) $db_host[1] !== 0 ) {
				$backup['port'] = ' --port=' . escapeshellarg( (int) $db_host[1] );
			} else {
				$backup['sock'] = ' --socket=' . escapeshellarg( $db_host[1] );
			}
		}
		$backup['command'] = '';
		$backup['filename'] = $backup['date'] . '_-_' . DB_NAME . '.sql';
		$brace = 0 === strpos( PHP_OS, 'WIN' ) ? '"' : '';
		if ( (int) $backup_options['backup_gzip'] === 1 ) {
			$backup['filename'] .= '.gz';
			$backup['filepath'] = $backup['path'] . '/'. $backup['filename'];
			do_action( 'wp_dbmanager_before_escapeshellcmd' );
			$backup['command'] = $brace . escapeshellcmd( $backup['mysqldumppath'] ) . $brace . ' --force --host=' . escapeshellarg( $backup['host'] ).' --user=' . escapeshellarg( DB_USER ) . ' --password=' . escapeshellarg( DB_PASSWORD ) . $backup['port'] . $backup['sock'] . $backup['charset'] . ' --add-drop-table --skip-lock-tables ' . DB_NAME . ' | gzip > '. $brace . escapeshellcmd( $backup['filepath'] ) . $brace;
		} else {
			$backup['filepath'] = $backup['path'] . '/'. $backup['filename'];
			do_action( 'wp_dbmanager_before_escapeshellcmd' );
			$backup['command'] = $brace . escapeshellcmd( $backup['mysqldumppath'] ) . $brace . ' --force --host=' . escapeshellarg( $backup['host'] ).' --user=' . escapeshellarg( DB_USER ). ' --password=' . escapeshellarg( DB_PASSWORD ) . $backup['port'] . $backup['sock'] . $backup['charset'] . ' --add-drop-table --skip-lock-tables ' . DB_NAME . ' > ' . $brace . escapeshellcmd( $backup['filepath'] ) . $brace;
		}
		execute_backup( $backup['command'] );
		$new_filepath = $backup['path'] . '/' . md5_file( $backup['filepath'] ) . '_-_' . $backup['filename'];
		rename( $backup['filepath'], $new_filepath );
		if ( ! empty( $backup_email ) ) {
			dbmanager_email_backup( $backup_email, $new_filepath );
		}
	}
}

function cron_dbmanager_optimize() {
	global $wpdb;
	$backup_options = get_option('dbmanager_options');
	$optimize_period = (int) $backup_options['optimize_period'];
	if($optimize_period > 0) {
		$optimize_tables = array();
		$tables = $wpdb->get_col("SHOW TABLES");
			foreach($tables as $table_name) {
				$optimize_tables[] = '`'.$table_name.'`';
		}
		$wpdb->query('OPTIMIZE TABLE '.implode(',', $optimize_tables));
	}
}

function cron_dbmanager_repair() {
	global $wpdb;
	$backup_options = get_option('dbmanager_options');
	$repair_period = (int) $backup_options['repair_period'];
	if($repair_period > 0) {
		$repair_tables = array();
		$tables = $wpdb->get_col("SHOW TABLES");
			foreach($tables as $table_name) {
				$repair_tables[] = '`'.$table_name.'`';
		}
		$wpdb->query('REPAIR TABLE '.implode(',', $repair_tables));
	}
}

function cron_dbmanager_reccurences($schedules) {
	$backup_options = get_option( 'dbmanager_options' );

	if( isset( $backup_options['backup'] ) && isset( $backup_options['backup_period'] ) ) {
		$backup = (int) $backup_options['backup'] * (int) $backup_options['backup_period'];
	} else {
		$backup = 0;
	}
	if( isset( $backup_options['optimize'] ) && isset( $backup_options['optimize_period'] ) ) {
		$optimize = (int) $backup_options['optimize'] * (int) $backup_options['optimize_period'];
	} else {
		$optimize = 0;
	}
	if( isset( $backup_options['repair'] ) && isset( $backup_options['repair_period'] ) ) {
		$repair = (int) $backup_options['repair'] * (int) $backup_options['repair_period'];
	} else {
		$repair = 0;
	}

	if( $backup === 0 ) {
		$backup = 31536000;
	}
	if( $optimize === 0 ) {
		$optimize = 31536000;
	}
	if( $repair === 0 ) {
		$repair = 31536000;
	}
   $schedules['dbmanager_backup'] = array( 'interval' => $backup, 'display' => __( 'WP-DBManager Backup Schedule', 'wp-dbmanager' ) );
   $schedules['dbmanager_optimize'] = array( 'interval' => $optimize, 'display' => __( 'WP-DBManager Optimize Schedule', 'wp-dbmanager' ) );
   $schedules['dbmanager_repair'] = array( 'interval' => $repair, 'display' => __( 'WP-DBManager Repair Schedule', 'wp-dbmanager' ) );
   return $schedules;
}


### Function: Ensure .htaccess Is In The Backup Folder
add_action( 'admin_notices', 'dbmanager_admin_notices' );
function dbmanager_admin_notices() {
	$backup_options = get_option( 'dbmanager_options' );
	$backup_folder_writable = ( is_dir( $backup_options['path'] ) && wp_is_writable( $backup_options['path'] ) );
	$htaccess_exists = file_exists( $backup_options['path'] . '/.htaccess' );
	$webconfig_exists =  file_exists( $backup_options['path'] . '/Web.config' );
	$index_exists =  file_exists( $backup_options['path'] . '/index.php' );

	if( ! isset( $backup_options['hide_admin_notices'] ) || (int) $backup_options['hide_admin_notices'] === 0 )
	{
		if( ! $backup_folder_writable || ! $index_exists || ( is_iis() && ! $webconfig_exists ) || ( ! is_iis() && ! $htaccess_exists ) ) {

			echo '<div class="error">';
			if( !$backup_folder_writable ) {
				echo '<p style="font-weight: bold;">' . __( 'Your backup folder is NOT writable', 'wp-postratings') . '</p>';
				echo '<p>'.sprintf( __( 'To correct this issue, make the folder <strong>%s</strong> writable.', 'wp-dbmanager' ), $backup_options['path'] ).'</p>';
			}
			if( ! $index_exists || ( is_iis() && ! $webconfig_exists ) || ( ! is_iis() && ! $htaccess_exists ) ) {
				echo '<p style="font-weight: bold;">'.__( 'Your backup folder MIGHT be visible to the public', 'wp-dbmanager' ).'</p>';
			}
			if( is_iis() ) {
				if( ! $webconfig_exists ) {
					echo '<p>'.sprintf( __( 'To correct this issue, move the file from <strong>%s</strong> to <strong>%s</strong>', 'wp-dbmanager'), plugin_dir_path( __FILE__ ) . 'Web.config.txt', $backup_options['path'] .'/Web.config' ).'</p>';
				}
			} else {
				if( ! $htaccess_exists ) {
					echo '<p>'.sprintf( __( 'To correct this issue, move the file from <strong>%s</strong> to <strong>%s</strong>', 'wp-dbmanager'), plugin_dir_path( __FILE__ ) . 'htaccess.txt', $backup_options['path'] .'/.htaccess' ).'</p>';
				}
			}
			if( ! $index_exists ) {
				echo '<p>'.sprintf( __( 'To correct this issue, move the file from <strong>%s</strong> to <strong>%s</strong>', 'wp-dbmanager'), plugin_dir_path( __FILE__ ) . 'index.php', $backup_options['path'] .'/index.php' ).'</p>';
			}
			echo '<p>' . sprintf( __( '<a href="%s">Click here</a> to let WP-DBManager try to fix it', 'wp-dbmanager' ), wp_nonce_url( admin_url( 'admin.php?page=wp-dbmanager/database-backup.php&try_fix=1' ), 'wp-dbmanager_fix' ) ) . '</a></p>';
			echo '</div>';
		}
	}
}


### Function: Auto Detect MYSQL and MYSQL Dump Paths
function detect_mysql() {
	global $wpdb;
	$paths = array('mysq' => '', 'mysqldump' => '');
	if ( substr( PHP_OS,0,3 ) === 'WIN' ) {
		$mysql_install = $wpdb->get_row("SHOW VARIABLES LIKE 'basedir'");
		if($mysql_install) {
			$install_path = trailingslashit( str_replace('\\', '/', $mysql_install->Value) );
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

### Function: Check if WordPress is installed on IIS
function is_iis() {
	$server_software = strtolower( $_SERVER['SERVER_SOFTWARE'] );
	if ( strpos( $server_software, 'microsoft-iis') !== false ) {
		return true;
	}

	return false;
}

### Executes OS-Dependent mysqldump Command (By: Vlad Sharanhovich)
function execute_backup($command) {
	$backup_options = get_option('dbmanager_options');
	check_backup_files();

	if( realpath( $backup_options['path'] ) === false ) {
		return sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), stripslashes( $backup_options['path'] ) );
	} else if( dbmanager_is_valid_path( $backup_options['mysqldumppath'] ) === 0 ) {
		return sprintf( __( '%s is not a valid mysqldump path', 'wp-dbmanager' ), stripslashes( $backup_options['mysqldumppath'] ) );
	} else if( dbmanager_is_valid_path( $backup_options['mysqlpath'] ) === 0 ) {
		return sprintf( __( '%s is not a valid mysql path', 'wp-dbmanager' ), stripslashes( $backup_options['mysqlpath'] ) );
	}

	if( substr( PHP_OS, 0, 3 ) === 'WIN' ) {
		$writable_dir = $backup_options['path'];
		$tmpnam = $writable_dir.'/wp-dbmanager.bat';
		$fp = fopen( $tmpnam, 'w' );
		fwrite ($fp, $command );
		fclose( $fp );
		system( $tmpnam.' > NUL', $error );
		unlink( $tmpnam );
	} else {
		passthru( $command, $error );
	}
	return $error;
}

### Function: Check for valid file path
function dbmanager_is_valid_path( $path ) {
	return preg_match( '/^[^*?"<>|;]*$/', $path );
}

### Functionn : Breakdown the file name into array
function dbmanager_parse_filename( $filename ) {
	$file_parts = explode( '_-_', $filename );
	if ( count( $file_parts ) > 2 ) {
		$file = array(
			'checksum'       => $file_parts[0],
			'timestamp'      => $file_parts[1],
			'database'       => $file_parts[2],
		);
	} else {
		$file = array(
			'checksum'  => '-',
			'timestamp' => $file_parts[0],
			'database'  => $file_parts[1],
		);
	}

	$file['name'] = $filename;
	$file['formatted_date'] = mysql2date( sprintf( __( '%s @ %s', 'wp-dbmanager' ), get_option( 'date_format' ), get_option( 'time_format' ) ), gmdate( 'Y-m-d H:i:s', $file['timestamp'] ) );

	return $file;
}

### Functionn : Return extra information like file size and nice date of the file
function dbmanager_parse_file( $filepath ) {
	$filename = basename( $filepath );
	$file_parts = dbmanager_parse_filename( $filename );
	$file_parts['path'] = dirname( $filepath );
	$file_parts['size'] = filesize( $filepath );
	$file_parts['formatted_size'] = format_size( $file_parts['size'] );

	return $file_parts;
}

### Function: Email database backup
function dbmanager_email_backup( $to, $backup_file_path ) {
	$to = ( !empty( $to ) ? $to : get_option( 'admin_email' ) );

	if( is_email( $to ) && file_exists( $backup_file_path ) ) {
		$backup_options = get_option( 'dbmanager_options' );

		$file = dbmanager_parse_file( $backup_file_path );
		$file_gmt_date = gmdate( 'Y-m-d H:i:s', $file['timestamp'] );

		$subject = ( ! empty( $backup_options['backup_email_subject'] ) ? $backup_options['backup_email_subject'] : dbmanager_default_options( 'backup_email_subject' ) );
		$subject = str_replace(
			array(
				'%SITE_NAME%',
				'%POST_DATE%',
				'%POST_TIME%'
			),
			array(
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				mysql2date( get_option( 'date_format' ), $file_gmt_date ),
				mysql2date( get_option( 'time_format' ), $file_gmt_date )
			)
			, $subject
		);
		$message = __( 'Website Name:', 'wp-dbmanager' ) . ' ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . "\n" .
			__( 'Website URL:', 'wp-dbmanager' ) . ' '. get_bloginfo( 'url' ) . "\n\n" .
			__( 'Backup File Name:', 'wp-dbmanager' ) . ' ' . $file['name'] . "\n" .
			__( 'Backup File MD5 Checksum:', 'wp-dbmanager' ) . ' ' . $file['checksum'] . "\n" .
			__( 'Backup File Date:', 'wp-dbmanager' ) . ' ' . $file['formatted_date'] . "\n" .
			__( 'Backup File Size:', 'wp-dbmanager' ) . ' ' . $file['formatted_size'] . "\n\n" .
			__( 'With Regards,', 'wp-dbmanager' )."\n".
			wp_specialchars_decode( get_bloginfo( 'name' ),  ENT_QUOTES ) . ' ' . __('Administrator', 'wp-dbmanager' ) . "\n" .
			get_bloginfo( 'url' );

		$from = ( ! empty( $backup_options['backup_email_from'] ) ? $backup_options['backup_email_from'] : dbmanager_default_options( 'backup_email_from' ) );
		$from_name = ( ! empty( $backup_options['backup_email_from_name'] ) ? $backup_options['backup_email_from_name'] : dbmanager_default_options( 'backup_email_from_name' ) );
		$headers[] = 'From: "' . wp_specialchars_decode( stripslashes_deep( $from_name ), ENT_QUOTES ) . '" <' . $from . '>';

		return wp_mail( $to, $subject, $message, $headers, $backup_file_path );
	}

	return false;
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
		   $folder_content = '';
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
	$backup_options = get_option( 'dbmanager_options' );
	$database_files = array();
	if ( ! is_emtpy_folder( $backup_options['path'] ) ) {
		if ( $handle = opendir($backup_options['path'] ) ) {
			while ( false !== ( $file = readdir( $handle ) ) ) {
				if ( $file !== '.' && $file !== '..' && ( file_ext( $file ) === 'sql' || file_ext( $file ) === 'gz' ) ) {
					$database_files[ filemtime( $backup_options['path'] . '/' . $file ) ] = $file;
				}
			}
			closedir( $handle );
			ksort( $database_files );
		}
	}
	if ( sizeof( $database_files ) >= $backup_options['max_backup'] ) {
		@unlink( $backup_options['path'] . '/' . $database_files[ array_key_first( $database_files ) ] );
	}
}


### Function: DBManager Default Options
function dbmanager_default_options( $option_name )
{
	switch( $option_name ) {
		case 'backup_email_from':
			return get_option( 'admin_email' );
			break;
		case 'backup_email_from_name':
			return wp_specialchars_decode( get_bloginfo( 'name' ),  ENT_QUOTES )  .' '.__( 'Administrator', 'wp-dbmanager' );
			break;
		case 'backup_email_subject':
			return __( '%SITE_NAME% Database Backup File For %POST_DATE% @ %POST_TIME%', 'wp-dbmanager' );
			break;
		case 'hide_admin_notices':
			return 0;
			break;
	}
}

### Function: Acticate Plugin
register_activation_hook( __FILE__, 'dbmanager_activation' );
function dbmanager_activation( $network_wide ) {
	$auto = detect_mysql();
	// Add Options
	$option_name = 'dbmanager_options';
	$option = array(
		  'mysqldumppath'           => $auto['mysqldump']
		, 'mysqlpath'               => $auto['mysql']
		, 'path'                    => str_replace( '\\', '/', WP_CONTENT_DIR ).'/backup-db'
		, 'max_backup'              => 10
		, 'backup'                  => 1
		, 'backup_gzip'             => 0
		, 'backup_period'           => 604800
		, 'backup_email'            => get_option( 'admin_email' )
		, 'backup_email_from'       => dbmanager_default_options( 'backup_email_from' )
		, 'backup_email_from_name'  => dbmanager_default_options( 'backup_email_from_name' )
		, 'backup_email_subject'    => dbmanager_default_options( 'backup_email_subject' )
		, 'optimize'                => 3
		, 'optimize_period'         => 86400
		, 'repair'                  => 2
		, 'repair_period'           => 604800
		, 'hide_admin_notices'      => 0
	);

	if ( is_multisite() && $network_wide ) {
		$ms_sites = function_exists( 'get_sites' ) ? get_sites() : wp_get_sites();

		if( 0 < sizeof( $ms_sites ) ) {
			foreach ( $ms_sites as $ms_site ) {
				$blog_id = class_exists( 'WP_Site' ) ? $ms_site->blog_id : $ms_site['blog_id'];
				switch_to_blog( $blog_id );
				add_option( $option_name, $option );
				dbmanager_activate();
			}
		}

		restore_current_blog();
	} else {
		add_option( $option_name, $option );
		dbmanager_activate();
	}
}

function dbmanager_activate() {
	dbmanager_create_backup_folder();

	// Set 'manage_database' Capabilities To Administrator
	$role = get_role( 'administrator' );
	if( ! $role->has_cap( 'manage_database') )
	{
		$role->add_cap( 'manage_database' );
	}
}

function dbmanager_create_backup_folder() {
	$plugin_path = plugin_dir_path( __FILE__ );
	$backup_path = WP_CONTENT_DIR . '/backup-db';
	$backup_options = get_option( 'dbmanager_options' );

	if( ! empty( $backup_options['path'] ) ) {
		$backup_path = $backup_options['path'];
	}

	// Create Backup Folder
	wp_mkdir_p( $backup_path );
	if( is_dir( $backup_path ) && wp_is_writable( $backup_path ) ) {
		if( is_iis() ) {
			if ( ! is_file( $backup_path . '/Web.config' ) ) {
				@copy( $plugin_path . 'Web.config.txt', $backup_path . '/Web.config' );
			}
		} else {
			if( ! is_file( $backup_path . '/.htaccess' ) ) {
				@copy( $plugin_path . 'htaccess.txt', $backup_path . '/.htaccess' );
			}
		}
		if( ! is_file( $backup_path . '/index.php' ) ) {
			@copy( $plugin_path . 'index.php', $backup_path . '/index.php' );
		}
		@chmod( $backup_path, 0750 );
	}
}

add_action( 'init', 'dbmanager_try_fix' );
function dbmanager_try_fix() {
	if ( ! empty( $_GET['try_fix'] ) && (int) $_GET['try_fix'] === 1 ) {
		check_admin_referer( 'wp-dbmanager_fix' );
		dbmanager_create_backup_folder();
	}
}


### Function: Download Database
add_action( 'init', 'download_database' );
function download_database() {
	if( isset( $_POST['do'] ) && $_POST['do'] === __( 'Download', 'wp-dbmanager' ) && ! empty( $_POST['database_file'] ) ) {
		check_admin_referer( 'wp-dbmanager_manage' );
		$database_file = trim( $_POST['database_file'] );
		if( substr( $database_file, strlen( $database_file ) -4, 4 ) === '.sql' || substr( $database_file, strlen( $database_file ) -7, 7 ) === '.sql.gz' ) {
			$backup_options = get_option( 'dbmanager_options' );
			$clean_file_name = sanitize_file_name( $database_file );
			$clean_file_name = str_replace( 'sql_.gz', 'sql.gz', $clean_file_name );
			$file_path = $backup_options['path'] . '/' . $clean_file_name;
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

### Function: Check whether a function is disabled.
function dbmanager_is_function_disabled( $function_name ) {
	return in_array( $function_name, array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );
}

### Function: Polyfill array_key_first() for PHP < 7.3
if ( ! function_exists( 'array_key_first' ) ) {
	function array_key_first( $arr ) {
		foreach( $arr as $key => $unused ) {
			return $key;
		}
		return null;
	}
}

### Function: Database Options
function dbmanager_options() {
	$text = '';
	$backup_options = get_option('dbmanager_options');
	$old_backup_options = $backup_options;
	if(!empty($_POST['Submit'])) {
		check_admin_referer('wp-dbmanager_options');
		$backup_options['mysqldumppath']            = ! empty( $_POST['db_mysqldumppath'] ) ? sanitize_text_field( $_POST['db_mysqldumppath'] ) : '';
		$backup_options['mysqlpath']                = ! empty ( $_POST['db_mysqlpath'] ) ? sanitize_text_field( $_POST['db_mysqlpath'] ) : '';
		$backup_options['path']                     = ! empty ( $_POST['db_path'] ) ? sanitize_text_field( $_POST['db_path'] ) : '';
		$backup_options['max_backup']               = ! empty( $_POST['db_max_backup'] ) ? (int) $_POST['db_max_backup'] : 0;
		$backup_options['backup']                   = ! empty ( $_POST['db_backup'] ) ? (int) $_POST['db_backup'] : 0;
		$backup_options['backup_gzip']              = ! empty( $_POST['db_backup_gzip'] ) ? (int) $_POST['db_backup_gzip'] : 0;
		$backup_options['backup_period']            = ! empty( $_POST['db_backup_period'] ) ? (int) $_POST['db_backup_period'] : 0;
		$backup_options['backup_email']             = ! empty( $_POST['db_backup_email'] ) ? sanitize_email( $_POST['db_backup_email'] ) : '';
		$backup_options['backup_email_from']        = ! empty( $_POST['db_backup_email_from'] ) ? sanitize_email( $_POST['db_backup_email_from'] ) : '';
		$backup_options['backup_email_from_name']   = ! empty( $_POST['db_backup_email_from_name']  ) ? sanitize_text_field( $_POST['db_backup_email_from_name'] ) : '';
		$backup_options['backup_email_subject']     = ! empty( $_POST['db_backup_email_subject'] ) ? sanitize_text_field( $_POST['db_backup_email_subject'] ) : '';
		$backup_options['optimize']                 = ! empty( $_POST['db_optimize'] ) ? (int) $_POST['db_optimize'] : 0;
		$backup_options['optimize_period']          = ! empty( $_POST['db_optimize_period'] ) ? (int) $_POST['db_optimize_period'] : 0;
		$backup_options['repair']                   = ! empty( $_POST['db_repair'] ) ? (int) $_POST['db_repair'] : 0;
		$backup_options['repair_period']            = ! empty( $_POST['db_repair_period'] ) ? (int) $_POST['db_repair_period'] : 0;
		$backup_options['hide_admin_notices']       = ! empty( $_POST['db_hide_admin_notices'] ) ? (int) $_POST['db_hide_admin_notices'] : 0;

		if( realpath( $backup_options['path'] ) === false ) {
			$text = '<div id="message" class="error"><p>' . sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), stripslashes( $backup_options['path'] ) ) . '</p></div>';
			$backup_options['path'] = $old_backup_options['path'];
		} else if( dbmanager_is_valid_path( $backup_options['mysqldumppath'] ) === 0 ) {
			$text = '<div id="message" class="error"><p>' . sprintf( __( '%s is not a valid mysqldump path', 'wp-dbmanager' ), stripslashes( $backup_options['mysqldumppath'] ) ) . '</p></div>';
			$backup_options['mysqldumppath'] = $old_backup_options['mysqldumppath'];
		} else if( dbmanager_is_valid_path( $backup_options['mysqlpath'] ) === 0 ) {
			$text = '<div id="message" class="error"><p>' . sprintf( __( '%s is not a valid mysql path', 'wp-dbmanager' ), stripslashes( $backup_options['mysqlpath'] ) ) . '</p></div>';
			$backup_options['mysqlpath'] = $old_backup_options['mysqlpath'];
		}

		$update_db_options = update_option( 'dbmanager_options', $backup_options );
		if( $update_db_options ) {
			$text = '<div id="message" class="updated"><p>' . __( 'Database Options Updated', 'wp-dbmanager' ) . '</p></div>';
		}
		if( empty( $text ) ) {
			$text = '<div id="message" class="error"><p>' . __( 'No Database Option Updated', 'wp-dbmanager' ) . '</p></div>';
		}
		wp_clear_scheduled_hook( 'dbmanager_cron_backup' );
		if( $backup_options['backup_period'] > 0 ) {
			if ( ! wp_next_scheduled( 'dbmanager_cron_backup' ) ) {
				wp_schedule_event( time(), 'dbmanager_backup', 'dbmanager_cron_backup' );
			}
		}
		wp_clear_scheduled_hook( 'dbmanager_cron_optimize' );
		if( $backup_options['optimize_period'] > 0 ) {
			if ( ! wp_next_scheduled('dbmanager_cron_optimize' ) ) {
				wp_schedule_event( time(), 'dbmanager_optimize', 'dbmanager_cron_optimize' );
			}
		}
		wp_clear_scheduled_hook( 'dbmanager_cron_repair' );
		if( $backup_options['repair_period'] > 0 ) {
			if ( ! wp_next_scheduled( 'dbmanager_cron_repair' ) ) {
				wp_schedule_event( time(), 'dbmanager_repair', 'dbmanager_cron_repair' );
			}
		}
	}
	$path = detect_mysql();

	// Default Options
	if( !isset( $backup_options['backup_email_from'] ) )
	{
		$backup_options['backup_email_from'] = dbmanager_default_options( 'backup_email_from' );
	}
	if( !isset( $backup_options['backup_email_from_name'] ) )
	{
		$backup_options['backup_email_from_name'] = dbmanager_default_options( 'backup_email_from_name' );
	}
	if( !isset( $backup_options['backup_email_subject'] ) )
	{
		$backup_options['backup_email_subject'] = dbmanager_default_options( 'backup_email_subject' );
	}
	if( !isset( $backup_options['hide_admin_notices'] ) )
	{
		$backup_options['hide_admin_notices'] = dbmanager_default_options( 'hide_admin_notices' );
	}

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
<?php if( ! empty( $text ) ) { echo $text; } ?>
<!-- Database Options -->
<form method="post" action="<?php echo admin_url('admin.php?page='.plugin_basename(__FILE__)); ?>">
	<?php wp_nonce_field('wp-dbmanager_options'); ?>
	<div class="wrap">
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
					<input type="text" name="db_path" size="60" maxlength="105" value="<?php echo stripslashes($backup_options['path']); ?>" dir="ltr" />
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
						<?php _e('Every', 'wp-dbmanager'); ?>&nbsp;<input type="text" name="db_backup" size="3" maxlength="5" value="<?php echo esc_attr( $backup_options['backup'] ); ?>" />&nbsp;
						<select name="db_backup_period" size="1">
							<option value="0"<?php selected('0', $backup_options['backup_period']); ?>><?php _e('Disable', 'wp-dbmanager'); ?></option>
							<option value="60"<?php selected('60', $backup_options['backup_period']); ?>><?php _e('Minutes(s)', 'wp-dbmanager'); ?></option>
							<option value="3600"<?php selected('3600', $backup_options['backup_period']); ?>><?php _e('Hour(s)', 'wp-dbmanager'); ?></option>
							<option value="86400"<?php selected('86400', $backup_options['backup_period']); ?>><?php _e('Day(s)', 'wp-dbmanager'); ?></option>
							<option value="604800"<?php selected('604800', $backup_options['backup_period']); ?>><?php _e('Week(s)', 'wp-dbmanager'); ?></option>
							<option value="2592000"<?php selected('2592000', $backup_options['backup_period']); ?>><?php _e('Month(s)', 'wp-dbmanager'); ?></option>
						</select>&nbsp;&nbsp;&nbsp;
						<?php _e('Gzip', 'wp-dbmanager'); ?>
						<select name="db_backup_gzip" size="1">
							<option value="0"<?php selected('0', $backup_options['backup_gzip']); ?>><?php _e('No', 'wp-dbmanager'); ?></option>
							<option value="1"<?php selected('1', $backup_options['backup_gzip']); ?>><?php _e('Yes', 'wp-dbmanager'); ?></option>
						</select>
					</p>
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
					<?php _e('Every', 'wp-dbmanager'); ?>&nbsp;<input type="text" name="db_optimize" size="3" maxlength="5" value="<?php echo esc_attr( $backup_options['optimize'] ); ?>" />&nbsp;
					<select name="db_optimize_period" size="1">
						<option value="0"<?php selected('0', $backup_options['optimize_period']); ?>><?php _e('Disable', 'wp-dbmanager'); ?></option>
						<option value="60"<?php selected('60', $backup_options['optimize_period']); ?>><?php _e('Minutes(s)', 'wp-dbmanager'); ?></option>
						<option value="3600"<?php selected('3600', $backup_options['optimize_period']); ?>><?php _e('Hour(s)', 'wp-dbmanager'); ?></option>
						<option value="86400"<?php selected('86400', $backup_options['optimize_period']); ?>><?php _e('Day(s)', 'wp-dbmanager'); ?></option>
						<option value="604800"<?php selected('604800', $backup_options['optimize_period']); ?>><?php _e('Week(s)', 'wp-dbmanager'); ?></option>
						<option value="2592000"<?php selected('2592000', $backup_options['optimize_period']); ?>><?php _e('Month(s)', 'wp-dbmanager'); ?></option>
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
					<?php _e('Every', 'wp-dbmanager'); ?>&nbsp;<input type="text" name="db_repair" size="3" maxlength="5" value="<?php echo esc_attr( $backup_options['repair'] ); ?>" />&nbsp;
					<select name="db_repair_period" size="1">
						<option value="0"<?php selected('0', $backup_options['repair_period']); ?>><?php _e('Disable', 'wp-dbmanager'); ?></option>
						<option value="60"<?php selected('60', $backup_options['repair_period']); ?>><?php _e('Minutes(s)', 'wp-dbmanager'); ?></option>
						<option value="3600"<?php selected('3600', $backup_options['repair_period']); ?>><?php _e('Hour(s)', 'wp-dbmanager'); ?></option>
						<option value="86400"<?php selected('86400', $backup_options['repair_period']); ?>><?php _e('Day(s)', 'wp-dbmanager'); ?></option>
						<option value="604800"<?php selected('604800', $backup_options['repair_period']); ?>><?php _e('Week(s)', 'wp-dbmanager'); ?></option>
						<option value="2592000"<?php selected('2592000', $backup_options['repair_period']); ?>><?php _e('Month(s)', 'wp-dbmanager'); ?></option>
					</select>
					</p>
					<p><?php _e('WP-DBManager can automatically repair your database after a certain period.', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php _e('Backup Email Options', 'wp-dbmanager'); ?></h3>
		<table class="form-table">
			<tr>
				<td valign="top"><strong><?php _e('To', 'wp-dbmanager'); ?></strong></td>
				<td>
					<p>
						<input type="text" name="db_backup_email" size="30" maxlength="250" placeholder="<?php _e ( 'To E-mail', 'wp-dbmanager' ); ?>"  value="<?php echo esc_attr( stripslashes( $backup_options['backup_email'] ) ) ?>" dir="ltr" />
					</p>
					<p><?php _e('(Leave blank to disable this feature)', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php _e('From', 'wp-dbmanager'); ?></strong></td>
				<td>
					<p>
						<input type="text" name="db_backup_email_from_name" size="60" maxlength="250" placeholder="<?php _e ( 'From Name', 'wp-dbmanager' ); ?>" value="<?php echo esc_attr( stripslashes( $backup_options['backup_email_from_name'] ) ) ?>" dir="ltr" />&nbsp;
						&lt;<input type="text" name="db_backup_email_from" size="30" maxlength="250" placeholder="<?php _e ( 'From E-mail', 'wp-dbmanager' ); ?>"  value="<?php echo esc_attr( stripslashes( $backup_options['backup_email_from'] ) ) ?>" dir="ltr" />&gt;
					</p>
					<p><?php _e('(Leave blank to use the default)', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php _e('Subject:', 'wp-dbmanager'); ?></strong></td>
				<td>
					<p>
						<input type="text" name="db_backup_email_subject" size="90" maxlength="255" placeholder="<?php _e ( 'Subject', 'wp-dbmanager' ); ?>"  value="<?php echo esc_attr( stripslashes( $backup_options['backup_email_subject'] ) ) ?>" dir="ltr" />
					</p>
					<p><?php _e('(Leave blank to use the default)', 'wp-dbmanager'); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php _e('Miscellaneous Options', 'wp-dbmanager'); ?></h3>
		<table class="form-table">
			<tr>
				<td valign="top"><strong><?php _e('Hide Admin Notices', 'wp-dbmanager'); ?></strong></td>
				<td>
					<p>
						<input type="radio" name="db_hide_admin_notices" value="1"<?php echo (int) $backup_options['hide_admin_notices'] === 1 ? ' checked="checked"' : ''; ?> />&nbsp;<?php _e('Yes', 'wp-dbmanager'); ?>
						<input type="radio" name="db_hide_admin_notices" value="0"<?php echo (int) $backup_options['hide_admin_notices'] === 0 ? ' checked="checked"' : ''; ?> />&nbsp;<?php _e('No', 'wp-dbmanager'); ?>
					</p>
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
