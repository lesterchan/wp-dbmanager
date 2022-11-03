# WP-DBManager
Contributors: GamerZ  
Donate link: http://lesterchan.net/site/donation/  
Tags: database, manage, wp-dbmanager, manager, table, optimize, backup, queries, query, drop, empty, tables, table, run, repair, cron, schedule, scheduling, automatic  
Requires at least: 4.0  
Tested up to: 6.1  
Stable tag: 2.80.9  

Manages your WordPress database.

## Description
Allows you to optimize database, repair database, backup database, restore database, delete backup database , drop/empty tables and run selected queries. Supports automatic scheduling of backing up, optimizing and repairing of database.

## General Usage
1. Activate `WP-DBManager` Plugin
1. The script will automatically create a folder called `backup-db` in the wp-content folder if that folder is writable. If it is not created, please create the folder and ensure that the folder is writable
1. Open `Folder: wp-content/backup-db`
1. If you are on Apache, move the `htaccess.txt` file from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/.htaccess` if it is not there already
1. If you are on IIS, move the `Web.config.txt` file from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/Web.config` if it is not there already
1. Move `index.php` file from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/index.php` if it is not there already
1. Go to `WP-Admin -> Database -> DB Options` to configure the database options

### Build Status
[![Build Status](https://travis-ci.org/lesterchan/wp-dbmanager.svg?branch=master)](https://travis-ci.org/lesterchan/wp-dbmanager)

### Development
* [https://github.com/lesterchan/wp-dbmanager](https://github.com/lesterchan/wp-dbmanager "https://github.com/lesterchan/wp-dbmanager")

### Translations
* [http://dev.wp-plugins.org/browser/wp-dbmanager/i18n/](http://dev.wp-plugins.org/browser/wp-dbmanager/i18n/ "http://dev.wp-plugins.org/browser/wp-dbmanager/i18n/")

### Credits
* Plugin icon by [Freepik](http://www.freepik.com) from [Flaticon](http://www.flaticon.com)

### Donations
* I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

### Disclaimer
* Note that this plugin passes your datababase password via --password in the command line of mysqldump. This is convenient but as a trade off, it is insecure.
* On some systems, your password becomes visible to system status programs such as ps that may be invoked by other users to display command lines. MySQL clients typically overwrite the command-line password argument with zeros during their initialization sequence. However, there is still a brief interval during which the value is visible. Also, on some systems this overwriting strategy is ineffective and the password remains visible to ps. Source: [End-User Guidelines for Password Security](http://dev.mysql.com/doc/refman/5.5/en/password-security-user.html)
* If this is a concern to you, I recommend another database backup plugin called [WP-DB-Backup](https://wordpress.org/plugins/wp-db-backup/)
* To know about the difference between WP-DBManager and WP-DB-backup, checkout __What is the difference between WP-DBManager and WP-DB-Backup?__ in the [FAQ section](https://wordpress.org/plugins/wp-dbmanager/faq/).

## Changelog
### Version 2.80.9
* FIXED: Handle folder permissions properly

### Version 2.80.8
* FIXED: Miss out database-backup.php.

### Version 2.80.7
* FIXED: Changed 'edit_files' capability to 'install_plugins' instead.

### Version 2.80.6
* FIXED: Remove 'manage_database' and use 'edit_files' to check for capability instead.

### Version 2.80.5
* FIXED: Changed utf8 to utf8mb4 for backing up

### Version 2.80.4
* FIXED: Clear WP-DBManager cron jobs on uninstall

### Version 2.80.3
* FIXED: Poly fill array_key_first() for PHP < 7.3

### Version 2.80.2
* FIXED: Newer backup is being replaced instead of older backup

### Version 2.80.1
* FIXED: 1970 date issues.
* FIXED: Sorting order of backup files. 

### Version 2.80
* NEW: Prefix MD5 checksum to the database backup file to prevent user from guessing the filename.
 
### Version 2.79.2
* FIXED: Arbitrary file delete bug by sanitizing filename. Props [RIPS Technologies](https://www.ripstech.com).

### Version 2.79.1
* FIXED: Added default utf8 charset

### Version 2.79
* FIXED: Proper check for disabled functions

### Version 2.78.1
* NEW: Bump WordPress 4.7
* FIXED: Undefined index: repair and repair_period

### Version 2.78
* FIXED: escapeshellcmd on Windows. Props Gregory Karpinsky. 
* FIXED: Move wp_mkdir_p() up before if check. Props Scott Allen.

### Version 2.77
* FIXED: Blank screen downloading backup
* FIXED: Remove MySQL Version check to display tables stats

### Version 2.76
* NEW: Add wp_dbmanager_before_escapeshellcmd action just before escapeshellcmd()
* FIXED: Missing / for Windows

### Version 2.75
* FIXED: When activating the plugin, copy index.php to the backup folder
* FIXED: If you are on Apache, .htaccess will be copied to the backup folder, if you are on IIS, Web.config will be copied to the backup folder
* FIXED: When choosing 1 Month(s) for Backup/Optimize/Repair, the next date calculation is wrong

### Version 2.74
* FIXED: escapeshellarg() already escape $, no need to double escape it

### Version 2.73
* FIXED: Unable to backup/restore database if user database password has certain special characters in them

### Version 2.72
* FIXED: Use escapeshellcmd() to escape shell commands. Props Larry W. Cashdollari.
* FIXED: Do not allow LOAD_FILE to be run. Props Larry W. Cashdollari.
* FIXED: Uses dbmanager_is_valid_path() to check for mysql and mysqldump path. Fixes arbitrary command injection using backup path. Props Larry W. Cashdollari.
* FIXED: Uses realpath() to check for backup path. Fixes arbitrary command injection using backup path. Props Larry W. Cashdollari.

### Version 2.71
* NEW: Bump to 4.0

### Version 2.70
* New: Uses WordPress 3.9 Dashicons
* NEW: Allow you to hide admin notices in the DB Options page
* NEW: Allow Multisite Network Activate
* NEW: Uses WordPress uninstall.php file to uninstall the plugin
* NEW: Uses wp_mail() to send email instead of PHP mail()
* NEW: New From E-mail, From Name & Subject template
* FIXED: Issues with email from field if site title contains , (comma)
* FIXED: Notices

### Version 2.65
* FIXED: Set default character set to UTF-8. Props Karsonito

### Version 2.64
* FIXED: Use intval() instead of is_int() when checking for port number. Props [Webby Scots](http://webbyscots.com/ "Webby Scots")

### Version 2.63 (03-05-2011)
* NEW: Added Auto Repair Functionality
* NEW: Added nonce To All Forms For Added Security

## Screenshots

1. Admin - Backup DB
2. Admin - Empty/Drop Tables In DB
3. Admin - DB Information
4. Admin - Manage DB
5. Admin - Optimize DB
6. Admin - DB Options
7. Admin - DB Options
8. Admin - Repair DB
9. Admin - Run Query in DB

## Frequently Asked Questions

### My database is not backed up / My backup file is 0Kb
* Ensure that your host allows you to access mysqldump. You can try to narrow the problem by Debugging via SSH:
1. In `wp-dbmanager.php`
2. Find `check_backup_files();` on line 246
3. Add below it `echo $command;`
4. Go to `WP-Admin -> Database -> Backup`
5. Click `Backup`
6. It should print some debugging statements
7. Copy that line than run it in SSH
8. If you need help on SSH contact your host or google for more info

### What is the difference between WP-DBManager and WP-DB-Backup?
* WP-DBManager uses `mysqldump` application to generate the backup and `mysql` application to restore them via shell.
* WP-DB-Backup uses PHP to generate the backup. In some cases WP-DB-Backup will work better for you because it requires less permissions. Not all host allows you to access mysqldump/mysql directly via shell.
* WP-DBManager allows you to have automatic optimizing and repairing of database on top of backing up of database.

### Why do I get the message "Warning: Your backup folder MIGHT be visible to the public!"?
* Ensure that you have renamed `htaccess.txt` to `.htaccess` and placed it in your backup folder (defaults to `wp-content/backup-db/`)
* If you are 100% sure you have did that and have verfied that the folder no longer is accessible to the public by visiting the URL `http://yousite.com/wp-content/backup-db/`, you can safely disable the notice by going to `WP-Admin -> Database -> DB Options` and set `Hide Admin Notices` to `Yes`.
