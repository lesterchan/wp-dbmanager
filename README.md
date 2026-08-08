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
WP-DBManager looks after the database behind your site: it backs it up, restores it, optimizes and repairs it, empties or drops tables and runs queries you write, all from wp-admin rather than from a shell or phpMyAdmin. Backups, optimization and repair can be left to run on a schedule, and the backup can be emailed to you when it finishes.

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Installation

1. Install and activate the plugin.
1. The plugin creates a `backup-db` folder inside `wp-content` if that folder is writable. If it does not appear, create it yourself and make it writable.
1. **Secure the backup folder**, as described under Usage. Anyone who can reach it over HTTP can download your entire database.
1. Go to `WP-Admin -> Database -> Settings` to configure the backup, optimize and repair schedules.
1. Go to `WP-Admin -> Database -> Backup DB`, which checks whether the folder is actually reachable over HTTP and tells you if it is.

## Usage
### Securing The Backup Folder
A database backup contains everything, including your users table. Anyone who can guess a backup file name can download the lot, so the folder must not be served over HTTP.

**The reliable option, on any server:** set `Path To Backup` under `WP-Admin -> Database -> Settings` to a folder outside your web root, for example `/var/www/example.com/backup-db` when WordPress lives in `/var/www/example.com/public_html`. Nothing served, nothing to configure.

If the folder has to stay inside the web root:

* **Apache** — move `htaccess.txt` from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/.htaccess`
* **IIS** — move `Web.config.txt` from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/Web.config`
* **nginx** — nginx does not read `.htaccess` files, so the file above does nothing. Add this to your server block and reload nginx:

```
location ^~ /wp-content/backup-db/ { deny all; }
```

Move `index.php` from `Folder: wp-content/plugins/wp-dbmanager` to `Folder: wp-content/backup-db/index.php` as well, so the folder cannot be listed.

The `Backup DB` page requests a file from the folder and reports what the server actually returns, so you can confirm the folder is closed rather than assume it.

### WP-CLI
```
wp dbmanager tables
wp dbmanager backups
wp dbmanager backup --yes
wp dbmanager backup --no-gzip --yes
wp dbmanager restore <file> --yes
wp dbmanager delete <file>... --yes
wp dbmanager email <file> --to=ops@example.org --yes
wp dbmanager optimize --all --yes
wp dbmanager repair wp_options --yes
wp dbmanager empty <table>... --yes
wp dbmanager drop <table>... --yes
```

**Everything that changes anything asks first**, so a script has to pass `--yes`. That includes `backup`, which deletes the oldest backups to stay inside `Maximum Backup Files`, and `email`, because a dump holds your users table and a sent message cannot be recalled. `tables` and `backups` only read, and take a `--format` of `table`, `csv`, `json`, `yaml`, `count` or `ids`; their sizes are in bytes rather than the KiB and MiB the screens print, because a shell is better at arithmetic than at parsing `1.2 MiB`.

`optimize` and `repair` take table names or `--all`. `empty` and `drop` take names only: emptying or dropping every table in a database is not maintenance, and the screen at least shows you the list before you tick it.

**There is no `run` subcommand.** WP-CLI already ships `wp db query`, which reaches the same database through the same client, so the `Run SQL Query` screen has no command counterpart. That screen is unchanged and still works.

`wp dbmanager` checks no capability. WP-CLI has no logged-in user unless you ask for one with `--user`, and whoever can run it can already read the credentials in `wp-config.php`, so a check would refuse every scheduled backup script while protecting nothing. The `install_plugins` gate on the admin screens is unchanged.

## Frequently Asked Questions

### My database is not backed up / My backup file is 0Kb
* Go to `WP-Admin -> Database -> Backup DB`. The top of that page checks the backup folder, both binary paths, and whether `passthru()`, `system()` and `exec()` are available, and tells you which one is the problem.
* The usual answer is that the host does not allow `mysqldump` to be run at all, or that the path under `WP-Admin -> Database -> Settings` is wrong. Your host can tell you the correct path.
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
* The most reliable fix on any server is to move the folder outside your web root — set `Path To Backup` under `WP-Admin -> Database -> Settings` to something like `/var/www/example.com/backup-db`. Nothing is served, so there is nothing to protect.
* If it has to stay inside the web root, see *Securing The Backup Folder* above. On nginx the bundled `.htaccess` does nothing at all; you need a `location` block.
* The `Backup DB` page requests a file from the folder and reports what your server actually returned, so it is telling you what a visitor would get rather than guessing. If you have verified it yourself and want the notice gone anyway, set `Hide Admin Notices` to `Yes` under `Settings`.

## Screenshots

1. Database, the server it runs on and the size of every table
2. Backup DB, which checks the paths and the folder before it offers to run
3. Manage Backup DB, every backup with its checksum, date and size
4. Optimize DB, which reclaims the space MySQL still holds after deletions
5. Repair DB, for tables the server has marked as crashed
6. Empty/Drop Tables, one row per table and what emptying it would remove
7. Run SQL Query, for the statement no screen has a button for
8. Database Settings: the paths, the schedule, and where a backup is mailed

## Changelog
### 4.0.0
* FIXED: Changing the backup folder on the settings screen left the new folder unprotected. The `.htaccess`, the `Web.config`, the silence-is-golden `index.php` and the `0750` were only ever written on activation and from the "try to fix" notice, so backups were then written to a bare directory — and the shipped default lives inside `wp-content`, which is served. A dump contains the users table
* FIXED: The download and folder-repair handlers were registered on every request, front end included, where neither can legitimately arrive. Appending `?try_fix=1` to any URL on the site turned it into an "Access Denied" page, because the capability check runs before the nonce
* FIXED: `.sql` was added to the site-wide upload types for everyone, so any Author gained a file type they could put in the public uploads directory — for a feature they cannot reach. Only users who may actually restore a database get it now
* BREAKING: Requires WordPress 6.8 and PHP 8.2, up from 4.0 and 5.2.
* BREAKING: The settings row is renamed from `dbmanager_options` to `wp_dbmanager_options`, and a second row, `wp_dbmanager_version`, records the plugin and schema versions. The rename happens by itself the first time the plugin loads.
* BREAKING: The three cron hooks are renamed `wp_dbmanager_cron_backup`, `wp_dbmanager_cron_optimize` and `wp_dbmanager_cron_repair`, and their recurrences `wp_dbmanager_backup`, `wp_dbmanager_optimize` and `wp_dbmanager_repair`. The scheduled events are rebuilt automatically.
* BREAKING: The Database admin pages have new addresses. `admin.php?page=wp-dbmanager/database-backup.php` is now `admin.php?page=wp-dbmanager-backup`, and so on for every screen. Update any bookmarks. The menu itself is unchanged, and the plugin no longer cares what its folder is called.
* BREAKING: Every class is renamed from `DBManager_*` to `WP_DBManager_*`, and the files under `includes/` with them.
* BREAKING: A gzipped backup could be written, checksummed and e-mailed to you even when mysqldump had failed and produced nothing at all. See the FAQ. Check that your recent `.sql.gz` backups are not around 20 bytes.
* BREAKING: `Optimize DB` and `Repair DB` no longer tick every table for you. Select the tables you want, then choose the action from `Bulk actions`. Previously every table was pre-selected, so one click acted on all of them.
* BREAKING: `Empty/Drop Tables` can no longer empty some tables and drop others in the same submit. Choose `Empty` or `Drop` from `Bulk actions`, then submit again for the other.
* NEW: A `wp dbmanager` WP-CLI command — `tables`, `backups`, `backup`, `restore`, `delete`, `email`, `optimize`, `repair`, `empty` and `drop`. Everything that changes anything asks first, so scripts need `--yes`. There is no `run` subcommand: `wp db query` already does that, and the `Run SQL Query` screen is unchanged.
* NEW: A `wp_dbmanager_capability` filter. Every capability check in the plugin goes through it, so the Database screens can be handed to another role in one place. The default is still `install_plugins`.
* NEW: Every table on the Database, Manage Backup DB, Optimize, Repair and Empty/Drop screens is now a standard WordPress list table. Columns sort, the select-all box works, and the Optimize and Repair screens show each table's size and overhead so you can see what is actually worth acting on.
* NEW: Several backups can be deleted or e-mailed in one go. Restore and Download still act on one at a time, and say so rather than guessing.
* NEW: Settings is a Settings API screen, built from registered sections and fields, and the backup, optimize and repair schedules now follow the settings however they are changed, including from WP-CLI.
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
* FIXED: The Windows and Linux path hints under Settings displayed `'<strong>mysqldump.exe</strong>'` as literal text.
* FIXED: Backups larger than 1 GiB reported their size in the wrong unit, so a 2 GiB backup showed as `2048.0 GiB`.
* FIXED: The mysqldump command, its connection arguments and the restore command were written out three separate times and had drifted apart; only two of the three checked that the dump had produced a file.
* FIXED: Escape all output on the admin screens to prevent XSS.
* FIXED: Validate table names against the database before emptying, dropping, optimizing or repairing.
* FIXED: The database password is no longer passed on the command line.
* FIXED: Require the `install_plugins` capability for the backup folder notice, the download and the folder fix.
* FIXED: Only download backup files that resolve inside the backup folder.
* FIXED: A download the plugin refuses no longer ends in a blank page. `Manage Backup DB` now reports `Invalid Database Backup File` instead of returning an empty response with no explanation.
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
* NOTE: The mysqldump and mysql paths are now passed as a single argument. If you added extra flags to either path under Settings, move them out or your backups will fail.
* NOTE: Backup file names now carry a real Unix timestamp. Backups taken before this release will show a date shifted by your timezone offset. The files themselves are fine.
* NOTE: The plugin's PHP functions are no longer global. Everything now lives in `WP_DBManager_*` classes under `includes/`. The `wp_dbmanager_before_escapeshellcmd` action is unchanged.

## Upgrade Notice

### 4.0.0

Requires WordPress 6.8 and PHP 8.2.

Written against 3.0.0, the version currently on WordPress.org. It is 4.0.0 rather than 3.0.1 because 3.0.0 had already shipped; nothing was skipped.

**Settings migrate on the first load after updating.** `dbmanager_options` becomes `wp_dbmanager_options`, and a second row, `wp_dbmanager_version`, records which version last ran. The old row is deleted once copied. Point any code calling `get_option( 'dbmanager_options' )` at the new name.

**All thirteen classes are renamed** from `DBManager_*` to `WP_DBManager_*`.

**The three scheduled jobs have new hook names.** `dbmanager_cron_backup`, `dbmanager_cron_optimize` and `dbmanager_cron_repair` are now `wp_dbmanager_cron_backup`, `wp_dbmanager_cron_optimize` and `wp_dbmanager_cron_repair`; the recurrences behind them — `dbmanager_backup`, `dbmanager_optimize`, `dbmanager_repair` — are now `wp_dbmanager_backup`, `wp_dbmanager_optimize` and `wp_dbmanager_repair`. The plugin clears the old events and rebuilds the schedule from your `Settings` settings on first load; check the three "Next ... date" lines afterwards. Point any `wp_schedule_event()` call or WP-CLI cron entry of your own at the new names.

**`wp_dbmanager_before_escapeshellcmd` is unchanged.**

**New `wp_dbmanager_capability` filter.** The screens still require `install_plugins`, as they have since 2.80.7 — they restore, empty and drop tables, so the gate is deliberately as high as installing code. To delegate the Database menu:

```php
add_filter( 'wp_dbmanager_capability', function ( $capability, $context ) {
	return 'manage_database';
}, 10, 2 );
```
