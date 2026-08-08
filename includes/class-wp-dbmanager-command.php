<?php
/**
 * The WP-CLI command.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backs up, restores, optimizes, repairs, empties and drops from the command line.
 *
 * Registered as `wp dbmanager` rather than `wp wp-dbmanager`. A `wp-` prefix is
 * a wordpress.org directory convention for keeping one plugin's download page
 * apart from another's, not a command-naming one, and after the `wp` binary it
 * only stutters; WP-CLI's own ecosystem names commands after the brand -- `wp
 * wc`, `wp yoast`, `wp jetpack`. WP-CLI gives a collision to whichever plugin
 * registered last and a bare noun is claimable by anyone; that is the accepted
 * trade for a word as distinctive as `dbmanager`.
 *
 * Every subcommand goes through the same classes the admin screens go through
 * -- WP_DBManager_Database for anything that shells out, WP_DBManager_Tables
 * for anything that touches a table, WP_DBManager_Backups for anything that
 * touches a file -- so a backup taken here is pruned, checksummed and named
 * exactly as one taken through the browser, and a table name is matched against
 * SHOW TABLES here for the same reason it is there.
 *
 * **Everything that changes anything asks first.** Nothing this plugin does can
 * be undone: a restore overwrites a live database, an empty and a drop are
 * final, and even a backup deletes the oldest existing backups to stay inside
 * the configured maximum. So every subcommand except the two that only read
 * goes through WP_CLI::confirm(), which means a script has to pass `--yes` and
 * say out loud that it meant it. Sending a dump by e-mail asks too: it cannot be
 * recalled, and the attachment holds the users table.
 *
 * **The Run SQL Query console is deliberately not here.** WP-CLI already ships
 * `wp db query`, which pipes straight into the mysql client and can do
 * everything the console can and a good deal it refuses. The console's allow
 * list -- INSERT, UPDATE, REPLACE, DELETE, CREATE and ALTER, with SELECT, DROP,
 * SHOW, GRANT and LOAD_FILE turned away -- is a guard around a form in a
 * browser, reachable by anybody the site has handed the capability to. On a
 * command line the caller already has wp-config.php and can reach the database
 * without asking this plugin, so the same list would stop nothing and would
 * only be a smaller, stranger version of a tool the operator already has.
 * Two spellings of "run arbitrary SQL", differing in which statements they
 * allow, is worse than one.
 *
 * **Downloading is not here either**, for the plain reason that it has nothing
 * to do: the Manage screen's Download streams a file to a browser, and from a
 * shell the file is already on disk. `wp dbmanager backups` names it.
 *
 * **No capability is checked, and that is not an oversight.** The screens are
 * gated on install_plugins because a browser session is a person who may or may
 * not be trusted with a restore. WP-CLI is not a browser session: it has no
 * logged-in user unless one is asked for with `--user`, and whoever can run it
 * can already read the credentials in wp-config.php. A capability check here
 * would refuse every scheduled backup script while protecting nothing.
 */
class WP_DBManager_Command extends WP_CLI_Command {

	/**
	 * List the tables in this database.
	 *
	 * Sizes are in bytes rather than the KiB and MiB the admin screen prints,
	 * because a shell is much better at arithmetic than it is at parsing
	 * "1.2 MiB" back into a number.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Every table, with what it is costing.
	 *     $ wp dbmanager tables
	 *
	 *     # Optimize the lot, one table at a time.
	 *     $ wp dbmanager tables --format=ids | xargs -n1 wp dbmanager optimize --yes
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function tables( $args, $assoc_args ) {
		unset( $args );

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$rows   = array();

		foreach ( WP_DBManager_Tables::status() as $status ) {
			// SHOW TABLE STATUS names its own columns, so they are read as an
			// array rather than as properties nobody here chose.
			$status = (array) $status;

			$rows[] = array(
				'name'     => $status['Name'],
				'rows'     => (int) $status['Rows'],
				'data'     => (int) $status['Data_length'],
				'index'    => (int) $status['Index_length'],
				'overhead' => (int) $status['Data_free'],
			);
		}

		if ( empty( $rows ) ) {
			// A database with no tables is an answer rather than a failure,
			// however unlikely it is under WordPress.
			WP_CLI::success( __( 'No tables found.', 'wp-dbmanager' ) );

			return;
		}

		$this->print_rows( $format, $rows, array( 'name', 'rows', 'data', 'index', 'overhead' ) );
	}

	/**
	 * List the backup files in the backup folder.
	 *
	 * Oldest first, which is the order the plugin prunes them in. Sizes are in
	 * bytes.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # What is in the backup folder.
	 *     $ wp dbmanager backups
	 *
	 *     # How many backups are being kept.
	 *     $ wp dbmanager backups --format=count
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function backups( $args, $assoc_args ) {
		unset( $args );

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$path   = WP_DBManager_Options::backup_path();
		$files  = WP_DBManager_Backups::all( $path );

		if ( empty( $files ) ) {
			// Naming the folder is the useful half of this message: an empty
			// listing is far more often a misconfigured path than a site that
			// has never taken a backup.
			/* translators: %s: backup folder path. */
			WP_CLI::success( sprintf( __( 'No backups in %s', 'wp-dbmanager' ), $path ) );

			return;
		}

		$rows = array();

		foreach ( $files as $file ) {
			$parsed = WP_DBManager_Backups::parse_file( $path . '/' . $file['name'] );

			$rows[] = array(
				'file'     => $parsed['name'],
				'date'     => $parsed['formatted_date'],
				'size'     => (int) $parsed['size'],
				'checksum' => $parsed['checksum'],
			);
		}

		$this->print_rows( $format, $rows, array( 'file', 'date', 'size', 'checksum' ) );
	}

	/**
	 * Back up the database.
	 *
	 * ## OPTIONS
	 *
	 * [--gzip]
	 * : Compress the dump. Defaults to the GZIP setting; --no-gzip turns it off.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp dbmanager backup
	 *
	 *     # In a script, where there is nobody to answer the prompt.
	 *     $ wp dbmanager backup --yes
	 *
	 *     # An uncompressed dump, whatever the setting says.
	 *     $ wp dbmanager backup --no-gzip --yes
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function backup( $args, $assoc_args ) {
		unset( $args );

		$gzip = isset( $assoc_args['gzip'] )
			? (bool) $assoc_args['gzip']
			: (bool) WP_DBManager_Options::get( 'backup_gzip' );

		// Taking a backup deletes the oldest ones to stay inside the configured
		// maximum, so even this asks: what it removes to make room are backups.
		WP_CLI::confirm(
			/* translators: %s: database name. */
			sprintf( __( 'Back up the %s database? Backups past the configured maximum are deleted to make room.', 'wp-dbmanager' ), DB_NAME ),
			$assoc_args
		);

		$result = WP_DBManager_Database::backup( $gzip );

		if ( ! $result['success'] ) {
			$reasons = array(
				'path'    => __( 'The backup folder is not writable.', 'wp-dbmanager' ),
				'empty'   => __( 'The dump came out empty and was deleted rather than kept as a backup.', 'wp-dbmanager' ),
				'missing' => __( 'No dump was written. Check the mysqldump path and the backup folder.', 'wp-dbmanager' ),
				'command' => __( 'mysqldump reported a failure.', 'wp-dbmanager' ),
			);

			$reason = isset( $reasons[ $result['reason'] ] ) ? $reasons[ $result['reason'] ] : $reasons['command'];

			/* translators: %s: reason the backup failed. */
			WP_CLI::error( sprintf( __( 'The database was not backed up. %s', 'wp-dbmanager' ), $reason ) );
		}

		/* translators: %s: full path to the finished backup file. */
		WP_CLI::success( sprintf( __( 'Database backed up to %s', 'wp-dbmanager' ), $result['filepath'] ) );
	}

	/**
	 * Restore a backup over the live database.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file to restore, as `wp dbmanager backups` names it.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp dbmanager restore 5f4dcc3b5aa765d61d8327deb882cf99_-_1700000000_-_wordpress.sql.gz
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$file = $this->backup_or_die( $args[0] );
		$name = basename( $file );

		// The one operation in the collection that can lose a site's entire
		// history, which is why it asks in the browser as well.
		WP_CLI::confirm(
			/* translators: %s: backup file name. */
			sprintf( __( 'Restore %s over the live database? Everything written since that backup is gone.', 'wp-dbmanager' ), $name ),
			$assoc_args
		);

		$result = WP_DBManager_Database::restore( $name );

		if ( ! $result['ran'] ) {
			WP_CLI::error( implode( ' ', $result['errors'] ) );
		}

		if ( $result['error'] ) {
			/* translators: %s: backup file name. */
			WP_CLI::error( sprintf( __( '%s failed to restore.', 'wp-dbmanager' ), $name ) );
		}

		/* translators: %s: backup file name. */
		WP_CLI::success( sprintf( __( 'Database restored from %s', 'wp-dbmanager' ), $name ) );
	}

	/**
	 * Delete backup files.
	 *
	 * ## OPTIONS
	 *
	 * <file>...
	 * : Backup files to delete, as `wp dbmanager backups` names them.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp dbmanager delete 1700000000_-_wordpress.sql --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function delete( $args, $assoc_args ) {
		if ( empty( $args ) ) {
			// An empty list would otherwise read as "delete nothing" and report
			// success, which is the wrong answer to a mistyped command.
			WP_CLI::error( __( 'Name at least one backup file to delete.', 'wp-dbmanager' ) );
		}

		// Every name is resolved before any file is removed, so a list with a
		// typo in it deletes nothing rather than half of what was asked for.
		$paths = array();

		foreach ( $args as $name ) {
			$paths[] = $this->backup_or_die( $name );
		}

		$names = array_map( 'basename', $paths );

		WP_CLI::confirm(
			/* translators: %s: comma separated backup file names. */
			sprintf( __( 'Delete backup file(s) %s?', 'wp-dbmanager' ), implode( ', ', $names ) ),
			$assoc_args
		);

		foreach ( $paths as $path ) {
			// wp_delete_file() returns nothing, so ask the filesystem whether it
			// actually worked rather than reporting a success nobody checked.
			wp_delete_file( $path );

			if ( file_exists( $path ) ) {
				/* translators: %s: backup file name. */
				WP_CLI::warning( sprintf( __( 'Unable to delete %s', 'wp-dbmanager' ), basename( $path ) ) );

				continue;
			}

			/* translators: %s: backup file name. */
			WP_CLI::success( sprintf( __( '%s deleted.', 'wp-dbmanager' ), basename( $path ) ) );
		}
	}

	/**
	 * E-mail a backup file.
	 *
	 * The dump is attached, exactly as the Manage Backup DB screen sends it.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file to send, as `wp dbmanager backups` names it.
	 *
	 * [--to=<address>]
	 * : Where to send it. Defaults to the site's administration address.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp dbmanager email 1700000000_-_wordpress.sql --to=ops@example.org --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function email( $args, $assoc_args ) {
		$file = $this->backup_or_die( $args[0] );
		$name = basename( $file );
		$to   = isset( $assoc_args['to'] ) ? $assoc_args['to'] : get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			/* translators: %s: the address that was given. */
			WP_CLI::error( sprintf( __( '%s is not an e-mail address.', 'wp-dbmanager' ), $to ) );
		}

		// This changes nothing and still asks. A dump holds the users table and
		// every password hash in it, and a sent message cannot be recalled.
		WP_CLI::confirm(
			/* translators: 1: backup file name, 2: e-mail address. */
			sprintf( __( 'E-mail %1$s, which contains the whole database, to %2$s?', 'wp-dbmanager' ), $name, $to ),
			$assoc_args
		);

		if ( ! WP_DBManager_Mailer::send( $to, $file ) ) {
			/* translators: 1: backup file name, 2: e-mail address. */
			WP_CLI::error( sprintf( __( 'Unable to e-mail %1$s to %2$s', 'wp-dbmanager' ), $name, $to ) );
		}

		/* translators: 1: backup file name, 2: e-mail address. */
		WP_CLI::success( sprintf( __( '%1$s e-mailed to %2$s', 'wp-dbmanager' ), $name, $to ) );
	}

	/**
	 * Optimize tables.
	 *
	 * ## OPTIONS
	 *
	 * [<table>...]
	 * : Tables to optimize. Ignored when --all is given.
	 *
	 * [--all]
	 * : Optimize every table in the database.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # The monthly pass the Optimize screen recommends.
	 *     $ wp dbmanager optimize --all --yes
	 *
	 *     $ wp dbmanager optimize wp_posts wp_postmeta --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function optimize( $args, $assoc_args ) {
		$all    = ! empty( $assoc_args['all'] );
		$tables = $this->tables_or_die( $args, $all );

		// Optimizing rebuilds a table and holds a lock on it while it does, so a
		// large one is offline for as long as it takes.
		WP_CLI::confirm(
			$this->table_question(
				$all,
				$tables,
				/* translators: %s: number of tables. */
				__( 'Optimize all %s tables? Each is locked while it is rebuilt.', 'wp-dbmanager' ),
				/* translators: %s: comma separated table names. */
				__( 'Optimize table(s) %s? Each is locked while it is rebuilt.', 'wp-dbmanager' )
			),
			$assoc_args
		);

		if ( ! WP_DBManager_Tables::optimize( $tables ) ) {
			/* translators: %s: comma separated table names. */
			WP_CLI::error( sprintf( __( 'Table(s) %s NOT optimized.', 'wp-dbmanager' ), implode( ', ', $tables ) ) );
		}

		/* translators: %s: comma separated table names. */
		WP_CLI::success( sprintf( __( 'Table(s) %s optimized.', 'wp-dbmanager' ), implode( ', ', $tables ) ) );
	}

	/**
	 * Repair tables.
	 *
	 * ## OPTIONS
	 *
	 * [<table>...]
	 * : Tables to repair. Ignored when --all is given.
	 *
	 * [--all]
	 * : Repair every table in the database.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp dbmanager repair wp_options --yes
	 *
	 *     $ wp dbmanager repair --all --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function repair( $args, $assoc_args ) {
		$all    = ! empty( $assoc_args['all'] );
		$tables = $this->tables_or_die( $args, $all );

		// REPAIR TABLE is not a read: on a table it finds damaged it discards
		// what it cannot rebuild, and the rows it discards are gone.
		WP_CLI::confirm(
			$this->table_question(
				$all,
				$tables,
				/* translators: %s: number of tables. */
				__( 'Repair all %s tables? A repair discards rows it cannot rebuild.', 'wp-dbmanager' ),
				/* translators: %s: comma separated table names. */
				__( 'Repair table(s) %s? A repair discards rows it cannot rebuild.', 'wp-dbmanager' )
			),
			$assoc_args
		);

		if ( ! WP_DBManager_Tables::repair( $tables ) ) {
			/* translators: %s: comma separated table names. */
			WP_CLI::error( sprintf( __( 'Table(s) %s NOT repaired.', 'wp-dbmanager' ), implode( ', ', $tables ) ) );
		}

		/* translators: %s: comma separated table names. */
		WP_CLI::success( sprintf( __( 'Table(s) %s repaired.', 'wp-dbmanager' ), implode( ', ', $tables ) ) );
	}

	/**
	 * Delete every row in a table, leaving the table itself.
	 *
	 * There is no --all here, and that is deliberate: emptying every table in a
	 * database is not maintenance, it is deleting the site, and the screen at
	 * least shows you the list before you tick it. Name the tables.
	 *
	 * ## OPTIONS
	 *
	 * <table>...
	 * : Tables to empty.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp dbmanager empty wp_dbmanager_scratch --yes
	 *
	 * @subcommand empty
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function empty_( $args, $assoc_args ) {
		// Named with the trailing underscore the way list_() is, because empty
		// is a language construct and cannot be a method name. The @subcommand
		// tag above is what WP-CLI reads.
		$tables = $this->tables_or_die( $args, false );

		WP_CLI::confirm(
			/* translators: %s: comma separated table names. */
			sprintf( __( 'Empty table(s) %s? Every row in them is deleted and this is not reversible.', 'wp-dbmanager' ), implode( ', ', $tables ) ),
			$assoc_args
		);

		foreach ( $tables as $table ) {
			WP_DBManager_Tables::truncate( $table );

			/* translators: %s: table name. */
			WP_CLI::success( sprintf( __( 'Table %s emptied.', 'wp-dbmanager' ), $table ) );
		}
	}

	/**
	 * Drop tables.
	 *
	 * As with `empty`, there is no --all: dropping every table in a database is
	 * not something to be one flag away from.
	 *
	 * ## OPTIONS
	 *
	 * <table>...
	 * : Tables to drop.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp dbmanager drop wp_leftover_from_a_removed_plugin --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function drop( $args, $assoc_args ) {
		$tables = $this->tables_or_die( $args, false );

		WP_CLI::confirm(
			/* translators: %s: comma separated table names. */
			sprintf( __( 'Drop table(s) %s? The tables and everything in them are deleted and this is not reversible.', 'wp-dbmanager' ), implode( ', ', $tables ) ),
			$assoc_args
		);

		if ( ! WP_DBManager_Tables::drop( $tables ) ) {
			/* translators: %s: comma separated table names. */
			WP_CLI::error( sprintf( __( 'Table(s) %s NOT dropped.', 'wp-dbmanager' ), implode( ', ', $tables ) ) );
		}

		/* translators: %s: comma separated table names. */
		WP_CLI::success( sprintf( __( 'Table(s) %s dropped.', 'wp-dbmanager' ), implode( ', ', $tables ) ) );
	}

	/**
	 * Print rows, reducing them to one column for --format=ids.
	 *
	 * WP-CLI's ids format prints the array it is handed rather than picking a
	 * column out of each row, so a list of rows has to be reduced to that column
	 * before it goes in. Without this, `--format=ids` prints the word Array once
	 * per table, which is worse than not offering the format at all.
	 *
	 * @param string $format Requested output format.
	 * @param array  $rows   Rows to print.
	 * @param array  $fields Columns in order. The first is what ids reduces to.
	 * @return void
	 */
	private function print_rows( $format, array $rows, array $fields ) {
		if ( 'ids' === $format ) {
			WP_CLI\Utils\format_items( $format, wp_list_pluck( $rows, $fields[0] ), $fields );

			return;
		}

		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * The question to ask before acting on a set of tables.
	 *
	 * --all is asked about by number rather than by name: a confirmation that
	 * lists sixty table names is one nobody reads.
	 *
	 * @param bool   $all      Whether every table was asked for.
	 * @param array  $tables   Tables that will be acted on.
	 * @param string $all_text Question for --all, taking the count.
	 * @param string $named    Question for a named list, taking the names.
	 * @return string
	 */
	private function table_question( $all, array $tables, $all_text, $named ) {
		if ( $all ) {
			return sprintf( $all_text, number_format_i18n( count( $tables ) ) );
		}

		return sprintf( $named, implode( ', ', $tables ) );
	}

	/**
	 * Resolve submitted names to real tables, or stop the command.
	 *
	 * A table name cannot be bound as a query parameter and cannot be sanitized
	 * into safety either, so it is matched against SHOW TABLES -- the same check
	 * the admin screens make, through the same method. What differs is the
	 * answer to a name that does not match: a screen quietly drops it, because
	 * its names came from its own checkboxes, while a name typed at a prompt is
	 * far more likely to be a typo worth reporting than an attack worth
	 * ignoring. Either way nothing unmatched reaches a statement.
	 *
	 * @param array $names Table names as they arrived on the command line.
	 * @param bool  $all   Whether every table was asked for instead.
	 * @return array Table names that exist.
	 */
	private function tables_or_die( array $names, $all ) {
		if ( $all ) {
			$existing = WP_DBManager_Tables::all();

			if ( empty( $existing ) ) {
				WP_CLI::error( __( 'This database has no tables.', 'wp-dbmanager' ) );
			}

			return $existing;
		}

		if ( empty( $names ) ) {
			WP_CLI::error( __( 'Name at least one table.', 'wp-dbmanager' ) );
		}

		$names  = array_map( 'strval', $names );
		$tables = WP_DBManager_Tables::filter( array_fill_keys( $names, 'yes' ), 'yes' );

		$unknown = array_values( array_diff( $names, $tables ) );

		if ( ! empty( $unknown ) ) {
			/* translators: %s: comma separated table names. */
			WP_CLI::error( sprintf( __( 'No such table(s): %s', 'wp-dbmanager' ), implode( ', ', $unknown ) ) );
		}

		return $tables;
	}

	/**
	 * Resolve a submitted name to a backup file, or stop the command.
	 *
	 * Resolution goes through WP_DBManager_Backups::resolve(), which is what
	 * confirms the file really sits inside the backup folder rather than
	 * somewhere a name with .. in it points at.
	 *
	 * @param string $filename Backup file name as it arrived on the command line.
	 * @return string Absolute path to the file.
	 */
	private function backup_or_die( $filename ) {
		$path = WP_DBManager_Backups::resolve( $filename );

		if ( false === $path ) {
			/* translators: %s: the file name that was asked for. */
			WP_CLI::error( sprintf( __( '%s is not a backup file in the backup folder.', 'wp-dbmanager' ), $filename ) );
		}

		return $path;
	}
}
