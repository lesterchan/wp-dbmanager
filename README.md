# WP-DBManager
Contributors: GamerZ  
Donate link: http://lesterchan.net/site/donation/  
Tags: database, manage, wp-dbmanager, manager, table, optimize, backup, queries, query, drop, empty, tables, table, run, repair, cron, schedule, scheduling, automatic  
Requires at least: 3.9  
Tested up to: 4.0  
Stable tag: 2.72  

Manages your WordPress database.

## Description
Allows you to optimize database, repair database, backup database, restore database, delete backup database , drop/empty tables and run selected queries. Supports automatic scheduling of backing up, optimizing and repairing of database.

### Build Status
[![Build Status](https://travis-ci.org/lesterchan/wp-dbmanager.svg?branch=master)](https://travis-ci.org/lesterchan/wp-dbmanager)

### Development
* [https://github.com/lesterchan/wp-dbmanager](https://github.com/lesterchan/wp-dbmanager "https://github.com/lesterchan/wp-dbmanager")

### Translations
* [http://dev.wp-plugins.org/browser/wp-dbmanager/i18n/](http://dev.wp-plugins.org/browser/wp-dbmanager/i18n/ "http://dev.wp-plugins.org/browser/wp-dbmanager/i18n/")

### Credits
* Plugin icon by [Freepik](http://www.freepik.com) from [Flaticon](http://www.flaticon.com)

### Donations
* I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appericiate it. If not feel free to use it without any obligations.

### Disclaimer
* Note that this plugin passes your datababase password via --password in the command line of mysqldump. This is convenient but as a trade off, it is insecure.
* On some systems, your password becomes visible to system status programs such as ps that may be invoked by other users to display command lines. MySQL clients typically overwrite the command-line password argument with zeros during their initialization sequence. However, there is still a brief interval during which the value is visible. Also, on some systems this overwriting strategy is ineffective and the password remains visible to ps. Source: [End-User Guidelines for Password Security](http://dev.mysql.com/doc/refman/5.5/en/password-security-user.html)
* If this is a concern to you, I recommend another database backup plugin called [WP-DB-Backup](https://wordpress.org/plugins/wp-db-backup/)
* To know about the difference between WP-DBManager and WP-DB-backup, checkout __What is the difference between WP-DBManager and WP-DB-Backup?__ in the [FAQ section](https://wordpress.org/plugins/wp-dbmanager/faq/).

## Changelog
### Version 2.72
* FIXED: Uses escapeshellcmd() to escape shell commands
* FIXED: Do not allow LOAD_FILE to be run
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

### Version 2.61 (30-04-2011)
* FIXED: Checks File Extension And Sanitise File Name That Is Pass Through The URL When Downloading Database File. Props to [Joakim Jardenberg](http://jardenberg.se "Joakim Jardenberg"), [Jonas Nordstram](http://jonasnordstrom.se "Jonas Nordstram"), [Andreas Viklund](http://andreasviklund.com/ "Andreas Viklund")

### Version 2.60 (01-12-2009)
* FIXED: Bug In Cron Backup On Windows Server

### Version 2.50 (01-06-2009)
* NEW: Works For WordPress 2.8 Only
* NEW: Uses jQuery Framework
* NEW: Ensure That .htaccess Is In Backup Folder By Informing The User If It Is NOT There
* NEW: Use _n() Instead Of __ngettext() And _n_noop() Instead Of __ngettext_noop()
* FIXED: Uses $_SERVER['PHP_SELF'] With plugin_basename(__FILE__) Instead Of Just $_SERVER['REQUEST_URI']

### Version 2.40 (12-12-2008)
* NEW: Works For WordPress 2.7 Only
* NEW: Load Admin JS And CSS Only In WP-DBManager Admin Pages
* NEW: Added database-admin-css.css For WP-DBManager Admin CSS Styles
* NEW: Uses admin_url(), plugins_url() And site_url()
* NEW: Better Translation Using __ngetext() by Anna Ozeritskaya
* NEW: Right To Left Language Support by Kambiz R. Khojasteh
* FIXED: SSL Support
* FIXED: Bug In Downloading Backups In Other Languages by Kambiz R. Khojasteh
* FIXED: Bug In Backup/Restore On Windows Server When Path To mysqldump/mysql Or Backup File Contains Space Kambiz R. Khojasteh
* FIXED: In database-manage.php, $nice_file_date Was Calculated More Than Once by Kambiz R. Khojasteh
* FIXED: Returning Only DBManager Cron Schedules

### Version 2.31 (16-07-2008)
* NEW: Works For WordPress 2.6
* FIXED: Unable To Optimize Or Repair Tables If Table Name Contains - (dash)

### Version 2.30 (01-06-2008)
* NEW: Uses /wp-dbmanager/ Folder Instead Of /dbmanager/
* NEW: Uses wp-dbmanager.php Instead Of dbmanager.php
* NEW: Added Minute(s) Option To Backup And Optimize Cron Jobs
* NEW: Uses GiB, MiB, KiB Instead Of GB, MB, KB

### Version 2.20 (01-10-2007)
* NEW: Added --skip-lock-tables Argument When Backing Up Database
* NEW: Limit The Maximum Number Of Backup Files In The Backup Folder
* NEW: Ability To Uninstall WP-DBManager

### Version 2.11 (01-06-2007)
* NEW: Sort Database Backup Files By Date In Descending Order
* NEW: Added Repair Database Feature
* NEW: Automatic Scheduling Of Backing Up And Optimizing Of Database

### Version 2.10 (01-02-2007)
* NEW: Works For WordPress 2.1 Only
* NEW: Removed database-config.php
* NEW: Localize WP-DBManager
* NEW: Added The Ability To Auto Detect MYSQL And MYSQL Dump Path

### Version 2.05 (01-06-2006)
* FIXED: Database Table Names Not Appearing Correctly
* NEW: DBManager Administration Panel Is XHTML 1.0 Transitional

### Version 2.04 (10-05-2006)
* FIXED: Unable To Download Backup DB Due To Header Sent Error
* FIXED: Some XHTML Code Fixes

### Version 2.03 (01-04-2006)
* FIXED: Run Query Box Too Big
* FIXED: Header Sent Error
* FIXED: Extra Slashes For Mysql/Mysql Dump Path
* FIXED: Mismatch Date Due To GMT

### Version 2.02 (01-03-2006)
* NEW: Improved On 'manage_database' Capabilities
* NEW: Added GigaBytes To File Size
* NEW: Added ALTER Statement To Allowed Queries
* NEW: Able To Empty/Drop Tables
* NEW: Able To EMail Database Backup File
* NEW: Splitted database-manager.php Into Individual Files
* NEW: Merge Restore And Delete Backup Database
* NEW: Included .htaccess File To Protect Backup Folder
* NEW: Checking Of Backup Status
* FIXED: Using Old Method To Add Submenu
* FIXED: PHP Short Tags
* FIXED: Redirect Back To The Same Page Instead Of Manage Database Page After Submitting Form

### Version 2.01 (01-02-2006)
* NEW: Added 'manage_database' Capabilities To Administrator Role

### Version 2.00 (01-01-2006)
* NEW: Compatible With WordPress 2.0 Only
* NEW: GPL License Added

## Installation

1. Open `wp-content/plugins` Folder
2. Put: `Folder: wp-dbmanager`
3. Activate `WP-DBManager` Plugin
4. Rename `htaccess.txt` to `.htaccess` file in `Folder: wp-content/plugins/wp-dbmanager`
5. The script will automatically create a folder called `backup-db` in the wp-content folder if that folder is writable. If it is not created, please create it and CHMOD it to 777
6. Open `Folder: wp-content/backup-db`
7. Move the `.htaccess` file from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db`
8. Go to `WP-Admin -> Database -> DB Options` to configure the database options.

## Upgrading

1. Deactivate `WP-DBManager` Plugin
2. Open `wp-content/plugins` Folder
3. Put/Overwrite: `Folder: wp-dbmanager`
4. Activate `WP-DBManager` Plugin
5. Go to `WP-Admin -> Database -> DB Options` to re-configure the database options.

## Upgrade Notice

N/A

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
2. Find `check_backup_files();` on line 210
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
