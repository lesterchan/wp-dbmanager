# WP-DBManager
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: database, backup, restore, optimize, repair  
Requires at least: 4.6  
Tested up to: 7.0  
Stable tag: 3.0.0  
Requires PHP: 7.2  
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manages your WordPress database.

## Description
Allows you to optimize database, repair database, backup database, restore database, delete backup database , drop/empty tables and run selected queries. Supports automatic scheduling of backing up, optimizing and repairing of database.

## General Usage
1. Activate `WP-DBManager` Plugin
1. The script will automatically create a folder called `backup-db` in the wp-content folder if that folder is writable. If it is not created, please create the folder and ensure that the folder is writable
1. Go to `WP-Admin -> Database -> DB Options` to configure the database options
1. Secure the backup folder, see below
1. Go to `WP-Admin -> Database -> Backup DB`, which checks whether the folder is actually reachable over HTTP and tells you if it is

## Securing The Backup Folder
A database backup contains everything, including your users table. Anyone who can guess a backup file name can download the lot, so the folder must not be served over HTTP.

**The reliable option, on any server:** set `Path To Backup` under `WP-Admin -> Database -> DB Options` to a folder outside your web root, for example `/var/www/example.com/backup-db` when WordPress lives in `/var/www/example.com/public_html`. Nothing served, nothing to configure.

If the folder has to stay inside the web root:

* **Apache** — move `htaccess.txt` from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/.htaccess`
* **IIS** — move `Web.config.txt` from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/Web.config`
* **nginx** — nginx does not read `.htaccess` files, so the file above does nothing. Add this to your server block and reload nginx:

```nginx
location ^~ /wp-content/backup-db/ { deny all; }
```

Move `index.php` from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/index.php` as well, so the folder cannot be listed.

The `Backup DB` page requests a file from the folder and reports what the server actually returns, so you can confirm the folder is closed rather than assume it.

### Development
* [https://github.com/lesterchan/wp-dbmanager](https://github.com/lesterchan/wp-dbmanager "https://github.com/lesterchan/wp-dbmanager")

### Credits
* Plugin icon by [Freepik](https://www.freepik.com) from [Flaticon](https://www.flaticon.com)

### Donations
* I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Changelog
### Version 3.0.0
* NOTE: The mysqldump and mysql paths are now passed as a single argument. If you added extra flags to either path under DB Options, move them out or your backups will fail.
* NOTE: Requires WordPress 4.6 or later, up from 4.0.
* NOTE: Backup file names now carry a real Unix timestamp. Backups taken before this release will show a date shifted by your timezone offset. The files themselves are fine.
* NEW: WordPress 7.0
* NEW: The Backup Database page now asks the server whether the backup folder is actually reachable over HTTP, instead of assuming a dropped in .htaccess protects it. nginx is detected and given a configuration snippet that works.
* NEW: 'Attach Backup File' option to control whether the scheduled backup e-mail carries the database file. Existing sites keep attaching it.
* NEW: New backups are gzipped by default.
* CHANGED: New installs no longer prefill the backup e-mail address, so scheduled backup e-mails are opt-in.
* CHANGED: Translations now come from the WordPress.org language packs. The bundled .pot file and the load_plugin_textdomain() call are gone, both were redundant.
* FIXED: Escape all output on the admin screens to prevent XSS.
* FIXED: Validate table names against the database before emptying, dropping, optimizing or repairing.
* FIXED: The database password is no longer passed on the command line.
* FIXED: Require the `install_plugins` capability for the backup folder notice, the download and the folder fix.
* FIXED: Only download backup files that resolve inside the backup folder.
* FIXED: Validate the mysqldump, mysql and backup paths independently.
* FIXED: Every file now refuses to run when loaded directly.
* FIXED: Cron backups no longer rename and e-mail a dump that failed, an empty file is no longer passed off as a backup.
* FIXED: Restoring no longer reports success when the restore did not run.
* FIXED: Keep pruning old backups until the maximum is met, and treat a maximum below 1 as no limit.
* FIXED: Two backups written in the same second are no longer invisible to pruning and the manage screen.
* FIXED: Network activation and uninstall now cover every site, not just the first 100.
* FIXED: Uninstalling on multisite no longer overwrites the current site ID while it works.
* FIXED: Removed jQuery dependency.

### Version 2.80.10
* FIXED: Don't throw fatal error if unknown .sql files are inside the database backup folder.

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
* FIXED: Use intval() instead of is_int() when checking for port number. Props [Webby Scots](https://webbyscots.com/ "Webby Scots")

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
