# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What it is

Backs up, restores, optimizes, repairs and empties the WordPress database, runs
arbitrary SQL, and schedules the first three. It shells out to the `mysqldump`
and `mysql` binaries — that is the feature, not an implementation detail.

Screens: Backup, Manage Backups, Optimize, Repair, Empty/Drop Tables, Run SQL
Query, Settings, under one top-level menu with the `archive` dashicon.

## Version

**4.0.0 — the major skipped a number.** An earlier 3.0.0 was already live on
wordpress.org, so reusing it would have collided with shipped history.

## Data

`wp_dbmanager_options` (from the released `dbmanager_options`) and
`wp_dbmanager_version`, which holds the `plugin` and `db` upgrade markers and
nothing else. No custom table — the backups are files on disk.

`htaccess.txt` and `Web.config.txt` at the plugin root are **shipped payloads**,
copied into the backup folder to protect it. They are not stray files; do not
move or delete them.

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
`install_plugins` unless the user is a super admin — **that is correct.** If a
multisite test fails on it, fix the test (commit `9860c22`); weakening this gate
would hand the Run SQL Query console to every site administrator on a network.
Delegation goes through `wp_dbmanager_capability`.

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
* **`jobs()` and `legacy_jobs()` are both keyed by job name**, so merging them
  with `array_merge()` keeps three entries rather than six. Walk their values
  when you want all six hook names.
* **The schedule follows the settings however they change** — settings screen,
  WP-CLI, another plugin — because the rebuild hangs off the option update, not
  off the form submit.
* **Two normal list-table rules are waived here, and the commit messages say
  why.** No pagination: the tables list carries a totals row, so paging would
  reduce "select all" to "select this page" and its totals to per-page sums, and
  the backups list is already capped by `max_backup`. No hover row actions: every
  action is destructive, a row action is a GET, and one browser prefetch away
  from restoring over a live database. Destructive operations are POST bulk
  actions.
* **`WP_DBManager_Screens` methods are functions wrapping template markup**, kept
  that way so their locals do not leak into the global namespace. It is not a
  half-finished class.
* `SHOW VARIABLES` rows are read as arrays (`ARRAY_A`) — the column names are
  MySQL's spellings, not ours to rename.
* The `NoCaching` suppression on the `basedir` lookup is deliberate: caching where
  the server keeps its binaries would make a moved installation undetectable.

## WP-CLI

`wp dbmanager tables|backups|backup|restore|delete|email|optimize|repair|empty|drop`,
registered from `add_hooks()` and therefore outside its `is_admin()` block —
WP-CLI is not an admin request. The class file is required only once `WP_CLI` is
defined, because it extends `WP_CLI_Command`, which does not exist on a web
request.

**Every subcommand except the two that read goes through `WP_CLI::confirm()`.**
Nothing here can be undone, and that includes the two that look harmless:
`backup` prunes the oldest existing backups to stay inside `max_backup`, and
`email` attaches a dump holding the users table to a message nobody can recall.
A test walks all eight rather than trusting the next person to remember.

**Three things are deliberately absent, and each has its own reason.** There is
no SQL console, because `wp db query` already pipes into the same client and
refuses less; the allow list in `WP_DBManager_Tables::run_query()` guards a
browser form, and repeating it here would be a smaller, stranger version of a
tool the caller already has. There is no `download`, because that streams a file
to a browser and from a shell the file is already on disk. And `empty` and
`drop` take no `--all`: the screen shows you the list before you tick it, and a
shell has no such moment.

**No capability is checked, and that is not an oversight** — the screens' gate
protects a browser session, while whoever runs WP-CLI can already read
`wp-config.php`. A check would refuse every scheduled backup script and protect
nothing.

`--format=ids` reduces the rows to their first column before printing. WP-CLI's
ids format prints whatever array it is handed, so a list of row arrays comes out
as the word `Array` once per row.

## Migrations, and why they are tested through a browser

`maybe_upgrade()` runs from `add_hooks()`, so **every** request reaches it —
activation hooks do not fire on a plugin update, which is the usual reason a
migration never runs at all.

Two consequences for `tests/e2e/upgrade.spec.js`, both of which cost a run
before they were understood:

* **A `wp eval` call is itself an upgrade request.** WP-CLI boots the plugin
  before running anything, so seeding the legacy rows in one call and reading
  them back in a second finds them already migrated — the browser request then
  has nothing left to do and the test is quietly testing WP-CLI. Seed and read
  back inside one call, and put everything the fixture needs (the legacy row, an
  already-current row, the legacy cron events) into that same call.
* **Read the row raw when the question is "was it written".**
  `WP_DBManager_Options::get()` merges over the defaults, so it answers
  identically for a row holding the defaults and for no row at all — which is
  the state a migration that read, deleted and never wrote leaves behind. Seed
  the *shipped* defaults for the same reason: a customised fixture's result
  differs from the defaults, so its write lands whatever the read before it did.

The cron half is the part only a browser can answer: an event cannot be renamed
in place, so the migration clears three and reschedules three against
recurrences `WP_DBManager_Cron::init()` must already have registered.

## Tests

`bin/test.sh` runs PHPUnit, `bin/test-multisite.sh` the network pass, and
`bin/test-e2e.sh` the Playwright suite. **Run them rather than trusting a note
about their last result** — CI is the authority, and this file cannot be.

`test-database.php` covers command assembly and the defaults file;
`test-folder.php` the probe's three states; `test-cron.php` the job renaming and
rescheduling; `test-cli.php` the command, including the subcommands it
deliberately does not have.

## Pending

Nothing outstanding. The settings screen heading reads "Database Settings" —
check `class-wp-dbmanager-settings.php` before renaming anything.
