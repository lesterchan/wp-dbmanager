# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

WP-DBManager follows `_standards/STANDARDS.md` in the parent folder, which is
the contract for all nineteen plugins in the collection. Where this file and
that one disagree, that one wins.

## What it is

Backs up, restores, optimizes, repairs and empties the WordPress database, runs
arbitrary SQL, and schedules the first three. It shells out to the `mysqldump`
and `mysql` binaries — that is the feature, not an implementation detail.

Screens: Backup, Manage Backups, Optimize, Repair, Empty/Drop Tables, Run SQL
Query, Settings, under one top-level menu with the `archive` dashicon.

**§4.3 names wp-dbmanager as the reference implementation for `WP_List_Table`.**

## Version

**4.0.0, not 3.0.1** (§14). 3.0.0 is already live on wordpress.org, so the
repo's unreleased entry collided with shipped history.

## Data

`wp_dbmanager_options` (from the released `dbmanager_options`) and
`wp_dbmanager_version`. No custom table — the backups are files on disk.

`htaccess.txt` and `Web.config.txt` at the plugin root are **shipped payloads**,
copied into the backup folder to protect it. §1 exempts them from the layout
rule; do not move or delete them.

## The two things that make this plugin dangerous

**1. Everything that shells out goes through `WP_DBManager_Database`.** Before
3.0.0 the connection arguments and the `mysqldump` invocation were written out
three separate times — the cron callback, the Backup screen, and in mirror image
on the Manage screen for restores — and they had already drifted: only two of the
three checked whether the dump had produced a file. Do not add a fourth.

* **The password goes in a `--defaults-extra-file`, not on the command line**,
  because a command line is visible in `ps` to every user on the box. The file is
  `chmod 0600` **before** it is written, not after — that ordering is the whole
  point, and it is why the two `phpcs:ignore`s reject `WP_Filesystem` (which
  re-chmods after writing, reopening the window, and is not initialised at all
  during cron on an FTP-method host).
* `--defaults-extra-file` must come **immediately after the binary** or the
  client ignores it.
* `wp_dbmanager_before_escapeshellcmd` fires before each command is assembled and
  is unchanged from 3.0.0 — it is public API.
* `detect_binaries()` shells `which` only after `is_function_disabled( 'exec' )`
  has said `exec()` is available.

**2. `install_plugins` is the gate, and it is deliberately as high as installing
code.** The screens restore over a live database, drop tables and run arbitrary
SQL. Under multisite core's `map_meta_cap()` returns `do_not_allow` for
`install_plugins` unless the user is a super admin (§7.2.2) — **that is correct.**
`_standards/RESUME.md` calls the Run SQL Query console the worst case in the
whole programme for weakening a gate to make a test pass. Fix the test (commit
`9860c22`). Delegation goes through `wp_dbmanager_capability`.

## The backup folder probe

`WP_DBManager_Folder::is_public()` asks the server over HTTP whether the backup
folder is reachable. A dump contains the users table, so "we copied an
`.htaccess` in" is not evidence of anything — on nginx it is evidence of nothing.

Three details that look like over-engineering and are not:

* **It makes two requests.** A second HEAD for a filename that cannot exist is
  what tells a real 200 apart from a catch-all that answers 200 for everything.
  Both 200 means inconclusive, cached as `unknown`, reported as `null`.
* **It probes a real backup file, not `.htaccess`.** Plenty of nginx configs deny
  dotfiles while happily serving `.sql`, which reads as a false all-clear.
* **The three-state answer is true / false / null**, cached in a transient for an
  hour. `null` is not "no".

`server_type()` distinguishes nginx from apache because only apache honours a
dropped-in `.htaccess`; `SERVER_SOFTWARE` is unset under WP-CLI and during cron
outside a web request.

## Traps

* **The three cron jobs and their three recurrences were all renamed** —
  `dbmanager_cron_*` → `wp_dbmanager_cron_*`, `dbmanager_backup|optimize|repair`
  → `wp_dbmanager_*`. `WP_DBManager_Cron::legacy_jobs()` exists to clear the old
  events. The schedule is driven off one `jobs()` list precisely so the "clear,
  test, reschedule" block is not written three times with one copy quietly
  reading the wrong option.
* **The schedule follows the settings however they change** — settings screen,
  WP-CLI, another plugin — because the rebuild hangs off the option update, not
  off the form submit.
* **Two of §4.3's normal rules are waived here, and the commit messages say
  why.** No pagination: the tables list carries a totals row, so paging would
  reduce "select all" to "select this page" and its totals to per-page sums, and
  the backups list is already capped by `max_backup`. No hover row actions: every
  action is destructive, a row action is a GET, and one browser prefetch away
  from restoring over a live database. Destructive operations are POST bulk
  actions.
* **`WP_DBManager_Screens` methods are functions wrapping template markup**, kept
  that way so their locals do not leak into the global namespace. It is not a
  half-finished class.
* `SHOW VARIABLES` rows are read as arrays (`ARRAY_A`), matching wp-serverinfo —
  the column names are MySQL's spellings, not ours to rename.
* The `NoCaching` suppression on the `basedir` lookup is deliberate: caching where
  the server keeps its binaries would make a moved installation undetectable.

## Tests

`test-database.php` covers command assembly and the defaults file;
`test-folder.php` the probe's three states; `test-cron.php` the job renaming and
rescheduling. `tests/e2e/` is 5 specs and 56 tests, and every one of them was
green on 2026-08-05 — **but across two runs, not one**: the whole file was run
and the 50 older tests passed, then `upgrade.spec.js` was fixed and re-run on
its own. A single all-green pass of the file has not happened.

`_standards/RESUME.md` measures this plugin at 86.7% of assertions carrying a
failure message — second only to wp-email.

## Pending, not started

Task #17 renames the settings screen heading from "Options" to
"WP-DBManager Settings".
