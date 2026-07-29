<?php
/**
 * Table operations and the query console.
 *
 * @package WP-DBManager
 */

/**
 * Table names arrive as request keys, so they cannot be sanitized into safety -
 * they can only be matched against the real list. That match is the security
 * boundary for four screens, which is why most of this file is about it.
 */
class WP_DBManager_Tables_Test extends WP_DBManager_TestCase {

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

		$this->scratch = $wpdb->prefix . 'dbm_unit_scratch';

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
	 * A real table with the wanted value is kept.
	 */
	public function test_filter_keeps_a_real_table() {
		$selected = WP_DBManager_Tables::filter( array( $this->scratch => 'yes' ), 'yes' );

		$this->assertSame( array( $this->scratch ), $selected );
	}

	/**
	 * A table that does not exist never reaches a query.
	 *
	 * This is the one that matters. Removing the SHOW TABLES check makes the
	 * Optimize screen report success for a name the submitter invented.
	 */
	public function test_filter_drops_a_table_that_does_not_exist() {
		$this->assertSame( array(), WP_DBManager_Tables::filter( array( 'wp_not_a_table' => 'yes' ), 'yes' ) );
	}

	/**
	 * Names carrying SQL are dropped rather than escaped.
	 *
	 * @dataProvider data_injections
	 *
	 * @param string $name Submitted table name.
	 */
	public function test_filter_drops_injection_attempts( $name ) {
		$this->assertSame( array(), WP_DBManager_Tables::filter( array( $name => 'yes' ), 'yes' ) );
	}

	/**
	 * Table names that must never survive filtering.
	 *
	 * @return array
	 */
	public function data_injections() {
		return array(
			'backtick escape' => array( 'wp_posts`; DROP TABLE wp_users; --' ),
			'quoted'          => array( "wp_posts'" ),
			'subquery'        => array( 'wp_posts) UNION SELECT 1' ),
			'wildcard'        => array( '*' ),
			'empty'           => array( '' ),
		);
	}

	/**
	 * Only entries matching the wanted value are kept.
	 */
	public function test_filter_respects_the_wanted_value() {
		$submitted = array( $this->scratch => 'no' );

		$this->assertSame( array(), WP_DBManager_Tables::filter( $submitted, 'yes' ) );
		$this->assertSame( array( $this->scratch ), WP_DBManager_Tables::filter( $submitted, 'no' ) );
	}

	/**
	 * Empty and non-array input is handled.
	 */
	public function test_filter_handles_nothing_submitted() {
		$this->assertSame( array(), WP_DBManager_Tables::filter( array(), 'yes' ) );
		$this->assertSame( array(), WP_DBManager_Tables::filter( 'not an array', 'yes' ) );
	}

	/**
	 * The empty and drop screens read the same submission differently.
	 */
	public function test_empty_and_drop_are_separated_from_one_submission() {
		$submitted = array( $this->scratch => 'drop' );

		$this->assertSame( array(), WP_DBManager_Tables::filter( $submitted, 'empty' ) );
		$this->assertSame( array( $this->scratch ), WP_DBManager_Tables::filter( $submitted, 'drop' ) );
	}

	/**
	 * Truncating really empties the table.
	 */
	public function test_truncate_empties_the_table() {
		global $wpdb;

		WP_DBManager_Tables::truncate( $this->scratch );

		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->scratch}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Dropping really removes the table.
	 */
	public function test_drop_removes_the_table() {
		WP_DBManager_Tables::drop( array( $this->scratch ) );

		$this->assertNotContains( $this->scratch, WP_DBManager_Tables::all() );
	}

	/**
	 * Optimize and repair run against a real table.
	 */
	public function test_optimize_and_repair_run() {
		$this->assertTrue( WP_DBManager_Tables::optimize( array( $this->scratch ) ) );
		$this->assertTrue( WP_DBManager_Tables::repair( array( $this->scratch ) ) );
	}

	/**
	 * An empty list is a no-op rather than a malformed query.
	 */
	public function test_operations_on_an_empty_list_do_nothing() {
		$this->assertFalse( WP_DBManager_Tables::optimize( array() ) );
		$this->assertFalse( WP_DBManager_Tables::repair( array() ) );
		$this->assertFalse( WP_DBManager_Tables::drop( array() ) );
	}

	/**
	 * Status rows carry the columns the information screen prints.
	 *
	 * These come back with MySQL's own capitalisation, which is why the plugin
	 * cannot rename them to snake_case.
	 */
	public function test_status_returns_the_columns_the_screen_uses() {
		$rows = WP_DBManager_Tables::status();

		$this->assertNotEmpty( $rows );

		$row = $rows[0];

		foreach ( array( 'Name', 'Rows', 'Data_length', 'Index_length', 'Data_free' ) as $column ) {
			// property_exists() rather than assertObjectHasAttribute(), which is
			// deprecated in PHPUnit 9.6 and gone in 10.
			$this->assertTrue( property_exists( $row, $column ), $column );
		}
	}

	/**
	 * The server version is reported.
	 */
	public function test_version_is_reported() {
		$version = WP_DBManager_Tables::version();

		$this->assertNotEmpty( $version );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+/', $version );
	}

	/**
	 * The scratch table is among the tables listed.
	 */
	public function test_all_lists_the_real_tables() {
		global $wpdb;

		$tables = WP_DBManager_Tables::all();

		$this->assertContains( $this->scratch, $tables );
		$this->assertContains( $wpdb->posts, $tables );
	}

	/**
	 * The console classifies statements the way the screen reports them.
	 *
	 * @dataProvider data_statements
	 *
	 * @param string $query    Statement.
	 * @param string $expected Outcome.
	 */
	public function test_run_query_classification( $query, $expected ) {
		$this->assertSame( $expected, WP_DBManager_Tables::run_query( $query ) );
	}

	/**
	 * Statements and how the console must treat them.
	 *
	 * @return array
	 */
	public function data_statements() {
		return array(
			'select is refused'      => array( 'SELECT * FROM wp_posts', 'rejected' ),
			'drop is refused'        => array( 'DROP TABLE wp_posts', 'rejected' ),
			'show is refused'        => array( 'SHOW TABLES', 'rejected' ),
			'grant is refused'       => array( 'GRANT ALL ON *.* TO x', 'rejected' ),
			'lowercase select'       => array( 'select 1 from wp_posts', 'rejected' ),
			'load_file is refused'   => array( "INSERT INTO t VALUES (LOAD_FILE('/etc/passwd'))", 'rejected' ),
			'load_file any case'     => array( "insert into t values (load_file('/etc/passwd'))", 'rejected' ),
			'nonsense is ignored'    => array( 'BANANA', 'ignored' ),
			'a bare word is ignored' => array( 'COMMIT', 'ignored' ),
		);
	}

	/**
	 * An allowed statement really runs.
	 */
	public function test_run_query_executes_an_allowed_statement() {
		global $wpdb;

		$this->assertSame( 'ok', WP_DBManager_Tables::run_query( "INSERT INTO `{$this->scratch}` (v) VALUES ('c')" ) );
		$this->assertSame( '3', $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->scratch}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * LOAD_FILE is refused rather than executed.
	 */
	public function test_load_file_never_runs() {
		global $wpdb;

		$before = $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->scratch}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		WP_DBManager_Tables::run_query( "INSERT INTO `{$this->scratch}` (v) VALUES (LOAD_FILE('/etc/passwd'))" );

		$this->assertSame( $before, $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->scratch}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Blank lines between statements are not counted as queries.
	 */
	public function test_split_queries_drops_blank_lines() {
		$queries = WP_DBManager_Tables::split_queries( "SELECT 1\n\n  \nSELECT 2\r\n" );

		$this->assertSame( array( 'SELECT 1', 'SELECT 2' ), $queries );
	}
}
