# WP-DBManager
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: database, backup, restore, optimize, repair  
Requires at least: 6.8  
Tested up to: 7.0  
Stable tag: 4.0.0  
Requires PHP: 8.2  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manages your WordPress database.

## Description
Allows you to optimize database, repair database, backup database, restore database, delete backup database , drop/empty tables and run selected queries. Supports automatic scheduling of backing up, optimizing and repairing of database.

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Usage
1. Activate `WP-DBManager` Plugin
1. The script will automatically create a folder called `backup-db` in the wp-content folder if that folder is writable. If it is not created, please create the folder and ensure that the folder is writable
1. Go to `WP-Admin -> Database -> DB Options` to configure the database options
1. Secure the backup folder, see below
1. Go to `WP-Admin -> Database -> Backup DB`, which checks whether the folder is actually reachable over HTTP and tells you if it is

### Securing The Backup Folder
A database backup contains everything, including your users table. Anyone who can guess a backup file name can download the lot, so the folder must not be served over HTTP.

**The reliable option, on any server:** set `Path To Backup` under `WP-Admin -> Database -> DB Options` to a folder outside your web root, for example `/var/www/example.com/backup-db` when WordPress lives in `/var/www/example.com/public_html`. Nothing served, nothing to configure.

If the folder has to stay inside the web root:

* **Apache** — move `htaccess.txt` from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/.htaccess`
* **IIS** — move `Web.config.txt` from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/Web.config`
* **nginx** — nginx does not read `.htaccess` files, so the file above does nothing. Add this to your server block and reload nginx:

```
location ^~ /wp-content/backup-db/ { deny all; }
```

Move `index.php` from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/index.php` as well, so the folder cannot be listed.

The `Backup DB` page requests a file from the folder and reports what the server actually returns, so you can confirm the folder is closed rather than assume it.

## Frequently Asked Questions

### My database is not backed up / My backup file is 0Kb
* Go to `WP-Admin -> Database -> Backup DB`. The top of that page checks the backup folder, both binary paths, and whether `passthru()`, `system()` and `exec()` are available, and tells you which one is the problem.
* The usual answer is that the host does not allow `mysqldump` to be run at all, or that the path under `WP-Admin -> Database -> DB Options` is wrong. Your host can tell you the correct path.
* If you added extra flags to the mysqldump or mysql path, remove them. From 3.0.0 the path is passed as a single argument, so anything after the binary name is treated as part of the file name.

### My gzipped backup file is about 20 bytes
* That is an empty gzip stream: `mysqldump` failed and produced nothing, and `gzip` compressed the nothing.
* Before 3.0.0 the plugin could not tell. `mysqldump | gzip` reports gzip's exit status rather than mysqldump's, and the file it leaves behind is not empty, so the check for a zero byte backup never fired. The file was renamed with a checksum and, if you had backup e-mails on, sent to you.
* From 3.0.0 the dump is read back before it is accepted, and one with nothing in it is deleted and reported as a failure. **Check any recent `.sql.gz` backups you are relying on** — a real one is far larger than 20 bytes, and `gunzip -c yourbackup.sql.gz | head` should show SQL.

### I clicked Optimize (or Repair) and it says "No Tables Selected"

* From 3.0.0 these screens no longer tick every table for you. Tick the tables you want — or the box in the header row to take the lot — then pick `Optimize` from `Bulk actions` and press `Apply`.
* The old screens pre-selected everything, so a single click acted on the whole database whether or not that was what you meant. Selecting first is the WordPress convention and is a good deal harder to do by accident.
* The same applies to `Empty/Drop Tables`, which additionally can no longer empty some tables and drop others in one submit — pick one action, apply it, then pick the other.

### The Database pages say "Sorry, you are not allowed to access this page"
* Your bookmark points at the old address. In 3.0.0 the screens moved from `admin.php?page=wp-dbmanager/database-backup.php` to `admin.php?page=wp-dbmanager-backup`, and likewise for the others.
* Reach them from the `Database` menu in the sidebar and re-bookmark. Nothing has been removed.
* The old addresses embedded the plugin's folder name, which meant the plugin only worked when installed as `wp-dbmanager`. It no longer cares what the folder is called.

### What is the difference between WP-DBManager and WP-DB-Backup?
* WP-DBManager uses the `mysqldump` application to generate the backup and the `mysql` application to restore it, via the shell.
* WP-DB-Backup uses PHP to generate the backup. In some cases WP-DB-Backup will work better for you because it requires fewer permissions — not every host allows `mysqldump`/`mysql` to be run directly.
* WP-DBManager also gives you automatic optimizing and repairing of the database on top of backing it up.

### My backup folder is reported as visible to the public
* Anyone who guesses a backup file name can download your entire database, including your users table, so this is worth fixing rather than hiding.
* The most reliable fix on any server is to move the folder outside your web root — set `Path To Backup` under `WP-Admin -> Database -> DB Options` to something like `/var/www/example.com/backup-db`. Nothing is served, so there is nothing to protect.
* If it has to stay inside the web root, see *Securing The Backup Folder* above. On nginx the bundled `.htaccess` does nothing at all; you need a `location` block.
* The `Backup DB` page requests a file from the folder and reports what your server actually returned, so it is telling you what a visitor would get rather than guessing. If you have verified it yourself and want the notice gone anyway, set `Hide Admin Notices` to `Yes` under `DB Options`.

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

## Changelog
### 4.0.0
* BREAKING: Requires WordPress 6.8 or later, up from 4.0, and PHP 8.2 or later, up from 5.2. Sites on an older stack will not be offered the update at all.
* BREAKING: The settings row is renamed from `dbmanager_options` to `wp_dbmanager_options`, and a second row, `wp_dbmanager_version`, records the plugin and schema versions. The rename happens by itself the first time the plugin loads.
* BREAKING: The three cron hooks are renamed `wp_dbmanager_cron_backup`, `wp_dbmanager_cron_optimize` and `wp_dbmanager_cron_repair`, and their recurrences `wp_dbmanager_backup`, `wp_dbmanager_optimize` and `wp_dbmanager_repair`. The scheduled events are rebuilt automatically.
* BREAKING: The Database admin pages have new addresses. `admin.php?page=wp-dbmanager/database-backup.php` is now `admin.php?page=wp-dbmanager-backup`, and so on for every screen. Update any bookmarks. The menu itself is unchanged, and the plugin no longer cares what its folder is called.
* BREAKING: Every class is renamed from `DBManager_*` to `WP_DBManager_*`, and the files under `includes/` with them.
* BREAKING: A gzipped backup could be written, checksummed and e-mailed to you even when mysqldump had failed and produced nothing at all. See the FAQ. Check that your recent `.sql.gz` backups are not around 20 bytes.
* BREAKING: `Optimize DB` and `Repair DB` no longer tick every table for you. Select the tables you want, then choose the action from `Bulk actions`. Previously every table was pre-selected, so one click acted on all of them.
* BREAKING: `Empty/Drop Tables` can no longer empty some tables and drop others in the same submit. Choose `Empty` or `Drop` from `Bulk actions`, then submit again for the other.
* NEW: A `wp_dbmanager_capability` filter. Every capability check in the plugin goes through it, so the Database screens can be handed to another role in one place. The default is still `install_plugins`.
* NEW: Every table on the Database, Manage Backup DB, Optimize, Repair and Empty/Drop screens is now a standard WordPress list table. Columns sort, the select-all box works, and the Optimize and Repair screens show each table's size and overhead so you can see what is actually worth acting on.
* NEW: Several backups can be deleted or e-mailed in one go. Restore and Download still act on one at a time, and say so rather than guessing.
* NEW: DB Options is a Settings API screen, built from registered sections and fields, and the backup, optimize and repair schedules now follow the settings however they are changed, including from WP-CLI.
* NEW: The Backup Database page now asks the server whether the backup folder is actually reachable over HTTP, instead of assuming a dropped in .htaccess protects it. nginx is detected and given a configuration snippet that works.
* NEW: 'Attach Backup File' option to control whether the scheduled backup e-mail carries the database file. Existing sites keep attaching it.
* NEW: New backups are gzipped by default.
* NEW: A PHPUnit test suite and GitHub Actions CI covering every admin screen, the settings, the schedules and the backup handling, on six WordPress and PHP combinations in both single site and multisite, plus a vitest suite for the admin script.
* NEW: WordPress 7.0
* CHANGED: The admin screens use core's own notice, table and form markup throughout. The hand-coloured green and red status lines are gone, which is what makes them legible in dark mode and to a screen reader.
* CHANGED: New installs no longer prefill the backup e-mail address, so scheduled backup e-mails are opt-in.
* CHANGED: Translations now come from the WordPress.org language packs. The bundled .pot file and the load_plugin_textdomain() call are gone, both were redundant.
* FIXED: A failed gzipped backup is no longer passed off as a real one. `mysqldump | gzip` reports gzip's exit status rather than mysqldump's, and gzip turns empty input into a valid 20 byte file, so the old size check never saw the failure. Gzip is the default, so this was the common case.
* FIXED: The confirmation dialogs before restoring, dropping and emptying showed `Database.nThis Action Is Not Reversible.` instead of proper line breaks.
* FIXED: The Windows and Linux path hints under DB Options displayed `'<strong>mysqldump.exe</strong>'` as literal text.
* FIXED: Backups larger than 1 GiB reported their size in the wrong unit, so a 2 GiB backup showed as `2048.0 GiB`.
* FIXED: The mysqldump command, its connection arguments and the restore command were written out three separate times and had drifted apart; only two of the three checked that the dump had produced a file.
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
* NOTE: This release is numbered 4.0.0. 3.0.0 is already on WordPress.org, so the work that was going to be 3.0.0 ships as 4.0.0 instead.
* NOTE: The mysqldump and mysql paths are now passed as a single argument. If you added extra flags to either path under DB Options, move them out or your backups will fail.
* NOTE: Backup file names now carry a real Unix timestamp. Backups taken before this release will show a date shifted by your timezone offset. The files themselves are fine.
* NOTE: The plugin's PHP functions are no longer global. Everything now lives in `WP_DBManager_*` classes under `includes/`. The `wp_dbmanager_before_escapeshellcmd` action is unchanged.

### 2.80.10
* FIXED: Don't throw fatal error if unknown .sql files are inside the database backup folder.

### 2.80.9
* FIXED: Handle folder permissions properly

### 2.80.8
* FIXED: Miss out database-backup.php.

### 2.80.7
* FIXED: Changed 'edit_files' capability to 'install_plugins' instead.

### 2.80.6
* FIXED: Remove 'manage_database' and use 'edit_files' to check for capability instead.

### 2.80.5
* FIXED: Changed utf8 to utf8mb4 for backing up

### 2.80.4
* FIXED: Clear WP-DBManager cron jobs on uninstall

### 2.80.3
* FIXED: Poly fill array_key_first() for PHP < 7.3

### 2.80.2
* FIXED: Newer backup is being replaced instead of older backup

### 2.80.1
* FIXED: 1970 date issues.
* FIXED: Sorting order of backup files. 

### 2.80
* NEW: Prefix MD5 checksum to the database backup file to prevent user from guessing the filename.
 
### 2.79.2
* FIXED: Arbitrary file delete bug by sanitizing filename. Props [RIPS Technologies](https://www.ripstech.com).

### 2.79.1
* FIXED: Added default utf8 charset

### 2.79
* FIXED: Proper check for disabled functions

### 2.78.1
* NEW: Bump WordPress 4.7
* FIXED: Undefined index: repair and repair_period

### 2.78
* FIXED: escapeshellcmd on Windows. Props Gregory Karpinsky. 
* FIXED: Move wp_mkdir_p() up before if check. Props Scott Allen.

### 2.77
* FIXED: Blank screen downloading backup
* FIXED: Remove MySQL Version check to display tables stats

### 2.76
* NEW: Add wp_dbmanager_before_escapeshellcmd action just before escapeshellcmd()
* FIXED: Missing / for Windows

### 2.75
* FIXED: When activating the plugin, copy index.php to the backup folder
* FIXED: If you are on Apache, .htaccess will be copied to the backup folder, if you are on IIS, Web.config will be copied to the backup folder
* FIXED: When choosing 1 Month(s) for Backup/Optimize/Repair, the next date calculation is wrong

### 2.74
* FIXED: escapeshellarg() already escape $, no need to double escape it

### 2.73
* FIXED: Unable to backup/restore database if user database password has certain special characters in them

### 2.72
* FIXED: Use escapeshellcmd() to escape shell commands. Props Larry W. Cashdollari.
* FIXED: Do not allow LOAD_FILE to be run. Props Larry W. Cashdollari.
* FIXED: Uses dbmanager_is_valid_path() to check for mysql and mysqldump path. Fixes arbitrary command injection using backup path. Props Larry W. Cashdollari.
* FIXED: Uses realpath() to check for backup path. Fixes arbitrary command injection using backup path. Props Larry W. Cashdollari.

### 2.71
* NEW: Bump to 4.0

### 2.70
* NEW: Uses WordPress 3.9 Dashicons
* NEW: Allow you to hide admin notices in the DB Options page
* NEW: Allow Multisite Network Activate
* NEW: Uses WordPress uninstall.php file to uninstall the plugin
* NEW: Uses wp_mail() to send email instead of PHP mail()
* NEW: New From E-mail, From Name & Subject template
* FIXED: Issues with email from field if site title contains , (comma)
* FIXED: Notices

### 2.65
* FIXED: Set default character set to UTF-8. Props Karsonito

### 2.64
* FIXED: Use intval() instead of is_int() when checking for port number. Props [Webby Scots](https://webbyscots.com/ "Webby Scots")

### 2.63 (03-05-2011)
* NEW: Added Auto Repair Functionality
* NEW: Added nonce To All Forms For Added Security

## Upgrade Notice

### 4.0.0. Everything below is written against **3.0.0**, the version currently on WordPress.org. If you are updating from 2.x, read the 3.0.0 notes first — the admin page addresses, the pre-selected tables on `Optimize DB` and `Repair DB`, and the gzipped-backup fix all landed there.

**This release is 4.0.0, not 3.0.0.** 3.0.0 shipped to WordPress.org already, so the number could not be reused. Nothing was skipped: 4.0.0 is simply the next release after 3.0.0.

**WP-DBManager now requires WordPress 6.8 and PHP 8.2**. This is the change most likely to affect you, and it affects you before anything else does: if your site is on an older WordPress or an older PHP, WordPress will not offer you this update at all, and you will stay on 3.0.0. Check `WP-Admin -> Tools -> Site Health -> Info -> Server` for your PHP version. If it is below 8.2, ask your host to move you up — every host offers it, and PHP 8.1 and everything before it stopped getting security fixes some time ago. Nothing in the plugin changes what your database backups contain, so there is no rush beyond the usual one.

**Your settings move to a new row, by themselves.** The `dbmanager_options` row in `wp_options` becomes `wp_dbmanager_options`, and a second row, `wp_dbmanager_version`, is added to record which version last ran. The first time the plugin loads after the update it copies your settings across and deletes the old row. You do not have to do anything, and your `DB Options` screen will look exactly as you left it. If you had code of your own calling `get_option( 'dbmanager_options' )`, point it at `wp_dbmanager_options`.

**The plugin's PHP classes are renamed** from `DBManager_*` to `WP_DBManager_*` — `DBManager_Options` is `WP_DBManager_Options`, and so on for all thirteen. Only code of your own that referenced them directly is affected; nothing in WordPress does.

**The three scheduled jobs have new hook names.** `dbmanager_cron_backup`, `dbmanager_cron_optimize` and `dbmanager_cron_repair` are now `wp_dbmanager_cron_backup`, `wp_dbmanager_cron_optimize` and `wp_dbmanager_cron_repair`, and the recurrences behind them, `dbmanager_backup`, `dbmanager_optimize` and `dbmanager_repair`, are now `wp_dbmanager_backup`, `wp_dbmanager_optimize` and `wp_dbmanager_repair`. You do not have to do anything: the first time the plugin loads after the update it clears the old events and rebuilds the schedule from your `DB Options` settings. Check `WP-Admin -> Database -> DB Options` afterwards and confirm the three "Next ... date" lines look right. If you had `wp_schedule_event()` or a WP-CLI cron entry of your own hanging off the old names, point it at the new ones.

**The `wp_dbmanager_before_escapeshellcmd` action is unchanged.** It was already prefixed and keeps its name.

**There is a new `wp_dbmanager_capability` filter.** WP-DBManager has required the `install_plugins` capability since 2.80.7 and still does — these screens restore, empty and drop tables, so the gate is deliberately as high as installing code. If you need to hand the Database menu to somebody else, filter it in one place rather than editing the plugin:

```php
add_filter( 'wp_dbmanager_capability', function ( $capability, $context ) {
	return 'manage_database';
}, 10, 2 );
```
