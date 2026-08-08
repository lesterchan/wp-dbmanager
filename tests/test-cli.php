<?php
/**
 * Tests for the `wp dbmanager` WP-CLI command.
 *
 * @package WP-DBManager
 */

/**
 * The command backs up, restores, empties and drops, with no browser, no nonce
 * and no capability check in front of it, so every subcommand is pinned here --
 * and so are the two the plugin deliberately does not offer.
 *
 * The WP_CLI facade these tests read is the stand-in from helper-wp-cli.php: it
 * records what the command reported instead of printing it, and both its error()
 * and its confirm() throw, because the real ones end the process and every line
 * after a call to them is unreachable.
 *
 * The scratch table is the same arrangement test-tables.php uses. Nothing here
 * empties, drops or optimizes a table WordPress owns: a test that did would take
 * the rest of the suite down with it.
 */
class WP_DBManager_CLI_Test extends WP_DBManager_TestCase {

	/**
	 * Scratch table name.
	 *
	 * @var string
	 */
	protected $scratch = '';

	/**
	 * Set up.
	 */
	public function set_up() {
		global $wpdb;

		parent::set_up();

		WP_CLI::$successes     = array();
		WP_CLI::$warnings      = array();
		WP_CLI::$logs          = array();
		WP_CLI::$confirmations = array();
		WP_CLI::$commands      = array();
		WP_CLI::$items         = array();

		$this->scratch = $wpdb->prefix . 'dbm_cli_scratch';

		$wpdb->query( "DROP TABLE IF EXISTS `{$this->scratch}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE `{$this->scratch}` (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(32)) ENGINE=MyISAM" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "INSERT INTO `{$this->scratch}` (v) VALUES ('a'), ('b')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS `{$this->scratch}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tear_down();
	}

	/**
	 * Runs one subcommand the way WP-CLI would.
	 *
	 * @param string $subcommand Method to call.
	 * @param array  $args       Positional arguments.
	 * @param array  $assoc_args Associative arguments.
	 * @return void
	 */
	protected function run_command( $subcommand, $args = array(), $assoc_args = array() ) {
		$command = new WP_DBManager_Command();
		$command->$subcommand( $args, $assoc_args );
	}

	/**
	 * Run a subcommand and report the message it stopped with.
	 *
	 * Both halves of the stand-in that end a command -- error() and an
	 * unanswered confirm() -- throw, so "what did it stop with" is the question
	 * worth asking. An empty string means it did not stop at all, which every
	 * caller here asserts on rather than swallows: PHPUnit's own failure
	 * exception is a RuntimeException too, so a fail() inside the try would be
	 * caught by the catch and the test would pass for the wrong reason.
	 *
	 * @param string $subcommand Method to call.
	 * @param array  $args       Positional arguments.
	 * @param array  $assoc_args Associative arguments.
	 * @return string
	 */
	protected function stopped_with( $subcommand, $args = array(), $assoc_args = array() ) {
		try {
			$this->run_command( $subcommand, $args, $assoc_args );
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}

		return '';
	}

	/**
	 * The rows the last format_items() call was given.
	 *
	 * @return array
	 */
	protected function listed_rows() {
		$this->assertNotEmpty( WP_CLI::$items, 'The command formatted a table.' );

		$last = end( WP_CLI::$items );

		return $last['items'];
	}

	/**
	 * Drop a fake backup into the scratch folder.
	 *
	 * @param string   $name  File name.
	 * @param int|null $mtime Modification time, or null to leave it as written.
	 * @param string   $body  Contents.
	 * @return string Full path.
	 */
	protected function make_backup( $name, $mtime = null, $body = "-- dump\nCREATE TABLE x (id INT);\n" ) {
		$path = $this->backup_dir . '/' . $name;

		self::write_file( $path, $body );

		if ( null !== $mtime ) {
			self::touch_file( $path, $mtime );
		}

		return $path;
	}

	/**
	 * How many rows the scratch table is holding.
	 *
	 * @return string
	 */
	protected function scratch_rows() {
		global $wpdb;

		return (string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->scratch}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// --- registration and what is deliberately absent ---------------------

	/**
	 * The command registers under the bare noun, not the plugin slug.
	 *
	 * @return void
	 */
	public function test_the_command_registers_as_dbmanager() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		WP_DBManager::register_command();

		$this->assertArrayHasKey( 'dbmanager', WP_CLI::$commands, 'The command is registered as `wp dbmanager`.' );
		$this->assertSame( 'WP_DBManager_Command', WP_CLI::$commands['dbmanager'], 'WP_DBManager_Command is what handles it.' );
		$this->assertArrayNotHasKey( 'wp-dbmanager', WP_CLI::$commands, 'The plugin slug is not also claimed as a command.' );
	}

	/**
	 * There is no SQL console, and the omission is the decision.
	 *
	 * WP-CLI already ships `wp db query`, which reaches the same database
	 * through the same client and refuses nothing. A second console with a
	 * different allow list would be a smaller version of a tool the operator
	 * has anyway -- and the allow list guards a form in a browser, not a shell
	 * that can already read wp-config.php.
	 *
	 * @return void
	 */
	public function test_the_command_omits_the_sql_console() {
		$methods = get_class_methods( 'WP_DBManager_Command' );

		$this->assertNotEmpty( $methods, 'The command declares subcommands at all, so the check below means something.' );

		foreach ( array( 'run', 'run_query', 'query', 'sql', 'console' ) as $forbidden ) {
			$this->assertNotContains(
				$forbidden,
				$methods,
				'Running arbitrary SQL is left to `wp db query`, so there is no ' . $forbidden . ' subcommand.'
			);
		}

		$this->assertTrue(
			method_exists( 'WP_DBManager_Tables', 'run_query' ),
			'The Run SQL Query screen itself is untouched; it is only the command that does not offer one.'
		);
	}

	/**
	 * There is no download subcommand either, because there is nothing to send.
	 *
	 * @return void
	 */
	public function test_the_command_omits_the_browser_download() {
		$this->assertNotContains(
			'download',
			get_class_methods( 'WP_DBManager_Command' ),
			'Download streams a file to a browser; from a shell the file is already on disk and `backups` names it.'
		);
	}

	/**
	 * Every subcommand that changes anything asks before it does.
	 *
	 * Nothing this plugin does can be undone, so the contract is that a script
	 * has to pass --yes and mean it. This walks all eight rather than trusting
	 * that whoever adds the ninth will remember.
	 *
	 * @return void
	 */
	public function test_every_subcommand_that_changes_anything_asks_first() {
		$file = 'a_-_1700000000_-_db.sql';
		$this->make_backup( $file );

		$cases = array(
			'backup'   => array(),
			'restore'  => array( $file ),
			'delete'   => array( $file ),
			'email'    => array( $file ),
			'optimize' => array( $this->scratch ),
			'repair'   => array( $this->scratch ),
			'empty_'   => array( $this->scratch ),
			'drop'     => array( $this->scratch ),
		);

		foreach ( $cases as $subcommand => $args ) {
			WP_CLI::$confirmations = array();

			$stopped = $this->stopped_with( $subcommand, $args );

			$this->assertNotSame( '', $stopped, $subcommand . ' went ahead without being answered.' );
			$this->assertNotEmpty( WP_CLI::$confirmations, $subcommand . ' asked before doing anything.' );
		}

		$this->assertSame( '2', $this->scratch_rows(), 'Nothing was emptied while nobody was answering.' );
		$this->assertContains( $this->scratch, WP_DBManager_Tables::all(), 'And nothing was dropped.' );
		$this->assertCount( 1, WP_DBManager_Backups::all(), 'The backup folder holds the one fixture file and no new dump.' );
		$this->assertFileExists( $this->backup_dir . '/' . $file, 'And that file was not deleted either.' );
	}

	// --- tables -----------------------------------------------------------

	/**
	 * Listing reports every table with what it is costing.
	 *
	 * @return void
	 */
	public function test_tables_lists_every_table_with_its_costs() {
		global $wpdb;

		$this->run_command( 'tables' );

		$rows  = $this->listed_rows();
		$names = wp_list_pluck( $rows, 'name' );

		$this->assertContains( $this->scratch, $names, 'The scratch table is listed.' );
		$this->assertContains( $wpdb->posts, $names, 'And so are the tables WordPress owns.' );

		foreach ( array( 'name', 'rows', 'data', 'index', 'overhead' ) as $column ) {
			$this->assertArrayHasKey( $column, $rows[0], 'Each row carries the ' . $column . ' column the screen prints.' );
		}

		$scratch = null;

		foreach ( $rows as $row ) {
			if ( $this->scratch === $row['name'] ) {
				$scratch = $row;
			}
		}

		$this->assertNotNull( $scratch, 'The scratch table is among the rows, or the counts below are vacuous.' );
		$this->assertSame( 2, $scratch['rows'], 'Its record count is the number of rows in it.' );
		$this->assertIsInt( $scratch['data'], 'Sizes are reported as bytes rather than as a formatted string.' );
	}

	/**
	 * --format=ids reduces the rows to names, so the output can be piped.
	 *
	 * WP-CLI's ids format prints whatever array it is handed, so a list of rows
	 * has to be reduced first or every line reads "Array".
	 *
	 * @return void
	 */
	public function test_tables_format_ids_reduces_to_names() {
		$this->run_command( 'tables', array(), array( 'format' => 'ids' ) );

		$printed = $this->listed_rows();

		$this->assertContains( $this->scratch, $printed, 'The names are printed as a flat list.' );
		$this->assertIsString( $printed[0], 'Each entry is a table name rather than a row array.' );
	}

	// --- backups ----------------------------------------------------------

	/**
	 * The backup listing reports the files in the folder, oldest first.
	 *
	 * @return void
	 */
	public function test_backups_lists_the_backup_folder_oldest_first() {
		// Two parts rather than three, which is the shape a pre-4.0.0 backup has
		// and the one that reports no checksum.
		$this->make_backup( '1700000000_-_db.sql', 1700000000 );
		$this->make_backup( str_repeat( 'b', 32 ) . '_-_1700000900_-_db.sql', 1700000900 );

		$this->run_command( 'backups' );

		$rows = $this->listed_rows();

		$this->assertCount( 2, $rows, 'Both backup files are listed.' );
		$this->assertSame( '1700000000_-_db.sql', $rows[0]['file'], 'The oldest backup is listed first, which is the order they are pruned in.' );
		$this->assertSame( '-', $rows[0]['checksum'], 'A file whose name carries no checksum reports none rather than half a name.' );
		$this->assertSame( str_repeat( 'b', 32 ), $rows[1]['checksum'], 'And one that does reports it.' );
		$this->assertGreaterThan( 0, $rows[0]['size'], 'Each row carries the size of the file on disk.' );
	}

	/**
	 * An empty folder is an answer, and the answer names the folder.
	 *
	 * An empty listing is far more often a misconfigured path than a site that
	 * has never taken a backup, so the path is the useful half of the message.
	 *
	 * @return void
	 */
	public function test_backups_on_an_empty_folder_names_the_folder() {
		$this->run_command( 'backups' );

		$this->assertEmpty( WP_CLI::$items, 'No table is printed when there is nothing to put in it.' );
		$this->assertNotEmpty( WP_CLI::$successes, 'Finding nothing is reported on the success channel.' );
		$this->assertStringContainsString( $this->backup_dir, end( WP_CLI::$successes ), 'And the message names the folder that was looked in.' );
	}

	// --- backup -----------------------------------------------------------

	/**
	 * A backup that cannot run says why and leaves nothing behind.
	 *
	 * An empty file is worse than no file: it looks like a backup right up until
	 * somebody needs it.
	 *
	 * @return void
	 */
	public function test_backup_reports_why_it_failed_and_leaves_nothing_behind() {
		$options                  = WP_DBManager_Options::get();
		$options['mysqldumppath'] = '/nonexistent/mysqldump';
		WP_DBManager_Options::update( $options );

		$stopped = $this->stopped_with( 'backup', array(), array( 'yes' => true ) );

		$this->assertStringContainsString( 'not backed up', $stopped, 'A failed backup stops with an error rather than reporting success.' );
		$this->assertSame( array(), WP_DBManager_Backups::all(), 'And leaves no half written file behind pretending to be one.' );
		$this->assertEmpty( WP_CLI::$successes, 'Nothing is reported on the success channel.' );
	}

	/**
	 * With --yes a backup really runs and reports where it landed.
	 *
	 * @group requires-mysqldump
	 *
	 * @return void
	 */
	public function test_backup_writes_a_dump() {
		if ( ! is_executable( $this->mysqldump_path() ) ) {
			$this->markTestSkipped( 'mysqldump is not available in this environment.' );
		}

		$this->run_command(
			'backup',
			array(),
			array(
				'yes'  => true,
				'gzip' => false,
			)
		);

		$files = WP_DBManager_Backups::all();

		$this->assertCount( 1, $files, 'One dump was written.' );
		$this->assertStringEndsWith( '.sql', $files[0]['name'], 'And --no-gzip wrote it uncompressed whatever the setting says.' );
		$this->assertNotEmpty( WP_CLI::$successes, 'The command reports where the backup landed.' );
		$this->assertStringContainsString( $files[0]['name'], end( WP_CLI::$successes ), 'Naming the file it actually wrote.' );
	}

	// --- restore ----------------------------------------------------------

	/**
	 * A name that is not a backup file stops the command before anything runs.
	 *
	 * @return void
	 */
	public function test_restore_refuses_a_name_that_is_not_a_backup() {
		$stopped = $this->stopped_with( 'restore', array( 'nothing_-_1700000000_-_db.sql' ), array( 'yes' => true ) );

		$this->assertStringContainsString( 'not a backup file', $stopped, 'A name matching no file in the backup folder is refused.' );
		$this->assertEmpty( WP_CLI::$confirmations, 'And is refused before the confirmation, so there is nothing to answer yes to.' );
	}

	/**
	 * A name that tries to climb out of the backup folder is refused.
	 *
	 * A dump holds the users table, and the name arrives as an argument, which
	 * makes it whatever the caller says it is.
	 *
	 * @return void
	 */
	public function test_restore_refuses_a_name_that_climbs_out_of_the_backup_folder() {
		$stopped = $this->stopped_with( 'restore', array( '../../wp-config.sql' ), array( 'yes' => true ) );

		$this->assertStringContainsString( 'not a backup file', $stopped, 'A name climbing out of the backup folder resolves to nothing.' );
	}

	// --- delete -----------------------------------------------------------

	/**
	 * Deleting removes the files it was given and leaves the rest.
	 *
	 * @return void
	 */
	public function test_delete_removes_only_the_files_it_was_given() {
		$doomed   = $this->make_backup( 'doomed_-_1700000000_-_db.sql' );
		$survivor = $this->make_backup( 'survivor_-_1700000001_-_db.sql' );

		$this->run_command( 'delete', array( 'doomed_-_1700000000_-_db.sql' ), array( 'yes' => true ) );

		$this->assertFileDoesNotExist( $doomed, 'The named backup is gone.' );
		$this->assertFileExists( $survivor, 'The other one is not.' );
		$this->assertNotEmpty( WP_CLI::$successes, 'And the command says what it deleted.' );
	}

	/**
	 * One bad name in the list deletes nothing at all.
	 *
	 * Every name is resolved before any file is removed, so a list with a typo
	 * in it is refused whole rather than half carried out.
	 *
	 * @return void
	 */
	public function test_delete_with_a_bad_name_deletes_nothing_at_all() {
		$real = $this->make_backup( 'real_-_1700000000_-_db.sql' );

		$stopped = $this->stopped_with(
			'delete',
			array( 'real_-_1700000000_-_db.sql', 'imaginary_-_1700000001_-_db.sql' ),
			array( 'yes' => true )
		);

		$this->assertStringContainsString( 'not a backup file', $stopped, 'The name that matches nothing is refused.' );
		$this->assertFileExists( $real, 'And the one that did match is still there.' );
	}

	/**
	 * Naming no file at all is an error rather than a quiet success.
	 *
	 * @return void
	 */
	public function test_delete_without_a_file_name_is_an_error() {
		$stopped = $this->stopped_with( 'delete', array(), array( 'yes' => true ) );

		$this->assertStringContainsString( 'at least one backup file', $stopped, 'An empty list is a mistyped command, not a request to delete nothing.' );
	}

	// --- email ------------------------------------------------------------

	/**
	 * Sending mails the dump to the address given, with the file attached.
	 *
	 * @return void
	 */
	public function test_email_sends_the_dump_to_the_address_given() {
		$path = $this->make_backup( 'a_-_1700000000_-_db.sql' );

		$sent = array();

		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit, $atts ) use ( &$sent ) {
				unset( $short_circuit );
				$sent = $atts;

				return true;
			},
			10,
			2
		);

		$this->run_command(
			'email',
			array( 'a_-_1700000000_-_db.sql' ),
			array(
				'to'  => 'ops@example.org',
				'yes' => true,
			)
		);

		$this->assertSame( 'ops@example.org', $sent['to'], 'The mail goes to the address that was asked for.' );
		// realpath(), because the name is resolved through the backup folder
		// before it is used and that is what comes back.
		$this->assertSame( realpath( $path ), $sent['attachments'], 'With the dump itself attached, as the Manage screen sends it.' );
		$this->assertNotEmpty( WP_CLI::$successes, 'And the command reports that it went.' );
	}

	/**
	 * An address that is not one stops the command before anything is sent.
	 *
	 * @return void
	 */
	public function test_email_refuses_an_address_that_is_not_one() {
		$this->make_backup( 'a_-_1700000000_-_db.sql' );

		$stopped = $this->stopped_with(
			'email',
			array( 'a_-_1700000000_-_db.sql' ),
			array(
				'to'  => 'not-an-address',
				'yes' => true,
			)
		);

		$this->assertStringContainsString( 'not an e-mail address', $stopped, 'A malformed address is refused.' );
		$this->assertEmpty( WP_CLI::$confirmations, 'Before the confirmation, so nobody is asked about a message that could not be sent.' );
	}

	// --- optimize and repair ----------------------------------------------

	/**
	 * Optimizing runs against the table it was given and keeps its rows.
	 *
	 * @return void
	 */
	public function test_optimize_runs_against_the_table_it_was_given() {
		$this->run_command( 'optimize', array( $this->scratch ), array( 'yes' => true ) );

		$this->assertNotEmpty( WP_CLI::$successes, 'The command reports the table it optimized.' );
		$this->assertStringContainsString( $this->scratch, end( WP_CLI::$successes ), 'Naming the table it was given.' );
		$this->assertSame( '2', $this->scratch_rows(), 'Optimizing rebuilds the table without losing what is in it.' );
	}

	/**
	 * Repairing runs against the table it was given.
	 *
	 * @return void
	 */
	public function test_repair_runs_against_the_table_it_was_given() {
		$this->run_command( 'repair', array( $this->scratch ), array( 'yes' => true ) );

		$this->assertNotEmpty( WP_CLI::$successes, 'The command reports the table it repaired.' );
		$this->assertSame( '2', $this->scratch_rows(), 'And an undamaged table comes out of a repair with its rows.' );
	}

	/**
	 * A table that does not exist is reported rather than quietly dropped.
	 *
	 * The screens drop an unmatched name silently, because their names came
	 * from their own checkboxes. A name typed at a prompt is a typo worth
	 * reporting.
	 *
	 * @return void
	 */
	public function test_a_table_that_does_not_exist_is_refused() {
		$stopped = $this->stopped_with( 'optimize', array( 'wp_not_a_table' ), array( 'yes' => true ) );

		$this->assertStringContainsString( 'wp_not_a_table', $stopped, 'The name that matched nothing is named back.' );
		$this->assertEmpty( WP_CLI::$successes, 'And nothing is reported as optimized.' );
	}

	/**
	 * A name carrying SQL never reaches a statement.
	 *
	 * Table names cannot be bound as query parameters, so the only check that
	 * means anything is the match against SHOW TABLES -- the same one the
	 * screens make, through the same method.
	 *
	 * @return void
	 */
	public function test_an_injection_attempt_never_reaches_a_statement() {
		global $wpdb;

		$stopped = $this->stopped_with( 'drop', array( 'wp_posts`; DROP TABLE wp_users; --' ), array( 'yes' => true ) );

		$this->assertNotSame( '', $stopped, 'A name carrying SQL is refused rather than escaped.' );
		$this->assertContains( $wpdb->posts, WP_DBManager_Tables::all(), 'The posts table is still there.' );
		$this->assertContains( $wpdb->users, WP_DBManager_Tables::all(), 'And so is the users table the name was reaching for.' );
	}

	/**
	 * --all covers every table, and asks about them by number.
	 *
	 * A confirmation that lists sixty table names is one nobody reads, so the
	 * question counts them instead.
	 *
	 * @return void
	 */
	public function test_all_asks_about_every_table_by_number() {
		$total = count( WP_DBManager_Tables::all() );

		$stopped = $this->stopped_with( 'repair', array(), array( 'all' => true ) );

		$this->assertNotSame( '', $stopped, '--all still asks before it acts.' );
		$this->assertStringContainsString( number_format_i18n( $total ), $stopped, 'The question covers every table in the database.' );
		$this->assertStringNotContainsString( $this->scratch, $stopped, 'By counting them rather than listing them.' );
		$this->assertSame( '2', $this->scratch_rows(), 'And nothing ran, because nobody answered.' );
	}

	// --- empty and drop ---------------------------------------------------

	/**
	 * Emptying deletes the rows and leaves the table standing.
	 *
	 * @return void
	 */
	public function test_empty_deletes_the_rows_and_keeps_the_table() {
		$this->run_command( 'empty_', array( $this->scratch ), array( 'yes' => true ) );

		$this->assertSame( '0', $this->scratch_rows(), 'The table has no rows left.' );
		$this->assertContains( $this->scratch, WP_DBManager_Tables::all(), 'But the table itself is still there, which is what makes empty different from drop.' );
	}

	/**
	 * Dropping removes the table itself.
	 *
	 * @return void
	 */
	public function test_drop_removes_the_table() {
		$this->run_command( 'drop', array( $this->scratch ), array( 'yes' => true ) );

		$this->assertNotContains( $this->scratch, WP_DBManager_Tables::all(), 'The table is gone.' );
		$this->assertNotEmpty( WP_CLI::$successes, 'And the command says which one went.' );
	}

	/**
	 * Neither empty nor drop honours --all, and that is deliberate.
	 *
	 * Emptying or dropping every table in a database is not maintenance, it is
	 * deleting the site. The screen at least shows the list before it is ticked;
	 * a shell has no such moment, so the tables have to be named.
	 *
	 * @return void
	 */
	public function test_empty_and_drop_have_no_all_flag() {
		foreach ( array( 'empty_', 'drop' ) as $subcommand ) {
			$stopped = $this->stopped_with(
				$subcommand,
				array(),
				array(
					'all' => true,
					'yes' => true,
				)
			);

			$this->assertStringContainsString( 'at least one table', $stopped, $subcommand . ' does not act on every table for a flag.' );
		}

		$this->assertSame( '2', $this->scratch_rows(), 'Nothing was emptied.' );
		$this->assertContains( $this->scratch, WP_DBManager_Tables::all(), 'And nothing was dropped.' );
	}
}
