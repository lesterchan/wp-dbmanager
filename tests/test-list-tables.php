<?php
/**
 * The two list tables.
 *
 * @package WP-DBManager
 */

/**
 * Columns, sorting and bulk actions on the screens that were hand-rolled
 * tables before 3.0.0.
 */
class WP_DBManager_List_Tables_Test extends WP_DBManager_TestCase {

	/**
	 * Drop a fake backup into the scratch folder.
	 *
	 * @param string $name  File name.
	 * @param int    $mtime Modification time.
	 * @param string $body  Contents.
	 * @return string Full path.
	 */
	protected function make_backup( $name, $mtime = null, $body = 'dump' ) {
		$path = $this->backup_dir . '/' . $name;
		self::write_file( $path, $body );

		if ( null !== $mtime ) {
			self::touch_file( $path, $mtime );
		}

		return $path;
	}

	/**
	 * Render a list table and hand back its markup.
	 *
	 * @param WP_List_Table $table Prepared table.
	 * @return string
	 */
	protected function render_table( $table ) {
		return $this->render(
			function () use ( $table ) {
				$table->prepare_items();
				$table->display();
			}
		);
	}

	/**
	 * The information screen reports, so it offers nothing to select.
	 */
	public function test_the_information_table_has_no_checkboxes() {
		$table = new WP_DBManager_Tables_Table( 'info' );

		$this->assertArrayNotHasKey( 'cb', $table->get_columns(), 'The information table offers no checkboxes; it acts on nothing.' );
		$this->assertSame( array(), $table->get_bulk_actions() );
	}

	/**
	 * The three action screens offer exactly the actions they are for.
	 *
	 * The Optimize screen must not be able to drop a table.
	 *
	 * @dataProvider data_modes
	 *
	 * @param string $mode    Screen mode.
	 * @param array  $actions Bulk actions it should offer.
	 */
	public function test_each_mode_offers_its_own_actions( $mode, $actions ) {
		$table = new WP_DBManager_Tables_Table( $mode );

		$this->assertSame( $actions, array_keys( $table->get_bulk_actions() ) );
		$this->assertArrayHasKey( 'cb', $table->get_columns(), 'An action table offers checkboxes, since it acts on what is ticked.' );
	}

	/**
	 * Modes and the bulk actions each one owns.
	 *
	 * @return array
	 */
	public function data_modes() {
		return array(
			'optimize' => array( 'optimize', array( 'optimize' ) ),
			'repair'   => array( 'repair', array( 'repair' ) ),
			'empty'    => array( 'empty', array( 'empty', 'drop' ) ),
		);
	}

	/**
	 * The tables table lists real tables with their stats.
	 */
	public function test_the_tables_table_lists_tables() {
		global $wpdb;

		$table = new WP_DBManager_Tables_Table( 'optimize' );
		$html  = $this->render_table( $table );

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( $wpdb->posts, $html );
		$this->assertStringContainsString( 'name="tables[]"', $html );
		$this->assertStringContainsString( 'value="' . $wpdb->posts . '"', $html );
	}

	/**
	 * The information screen totals its columns.
	 */
	public function test_the_information_table_totals_its_columns() {
		$table = new WP_DBManager_Tables_Table( 'info' );
		$html  = $this->render_table( $table );

		$this->assertScreenIsClean( $html );
		$this->assertMatchesRegularExpression( '/[0-9,]+ Tables?/', $html, 'The information table totals its table count.' );
		$this->assertMatchesRegularExpression( '/[0-9,]+ Records?/', $html, 'The information table totals its record count.' );
	}

	/**
	 * The action screens do not print a totals row.
	 */
	public function test_the_action_tables_have_no_totals_row() {
		$html = $this->render_table( new WP_DBManager_Tables_Table( 'optimize' ) );

		$this->assertDoesNotMatchRegularExpression( '/[0-9,]+ Tables?</', $html, 'An action table draws no totals row; the numbers belong to the information screen.' );
	}

	/**
	 * Tables sort by name by default, and the order can be reversed.
	 */
	public function test_tables_sort_by_name() {
		$_GET  = array();
		$table = new WP_DBManager_Tables_Table( 'info' );
		$table->prepare_items();
		$ascending = wp_list_pluck( $table->items, 'name' );

		$sorted = $ascending;
		sort( $sorted );
		$this->assertSame( $sorted, $ascending );

		$_GET  = array(
			'orderby' => 'name',
			'order'   => 'desc',
		);
		$table = new WP_DBManager_Tables_Table( 'info' );
		$table->prepare_items();

		$this->assertSame( array_reverse( $ascending ), wp_list_pluck( $table->items, 'name' ) );

		$_GET = array();
	}

	/**
	 * An orderby the table does not offer falls back rather than sorting oddly.
	 */
	public function test_an_unknown_orderby_falls_back() {
		$_GET  = array( 'orderby' => 'nonsense' );
		$table = new WP_DBManager_Tables_Table( 'info' );
		$table->prepare_items();

		$names  = wp_list_pluck( $table->items, 'name' );
		$sorted = $names;
		sort( $sorted );

		$this->assertSame( $sorted, $names );

		$_GET = array();
	}

	/**
	 * The backups table lists the backup folder.
	 */
	public function test_the_backups_table_lists_backups() {
		$name = str_repeat( 'a', 32 ) . '_-_1700000000_-_sitedb.sql';
		$this->make_backup( $name, 1700000000, 'SOME SQL' );

		$table = new WP_DBManager_Backups_Table();
		$html  = $this->render_table( $table );

		$this->assertScreenIsClean( $html );
		$this->assertStringContainsString( 'sitedb.sql', $html );
		$this->assertStringContainsString( str_repeat( 'a', 32 ), $html );
		$this->assertStringContainsString( 'name="backups[]"', $html );
		$this->assertSame( 8, $table->total_size() );
	}

	/**
	 * An empty folder says so.
	 */
	public function test_the_backups_table_reports_an_empty_folder() {
		$html = $this->render_table( new WP_DBManager_Backups_Table() );

		$this->assertStringContainsString( 'There Are No Database Backup Files Available.', $html );
	}

	/**
	 * Backups sort newest first by default.
	 *
	 * Sorting on the formatted date string would put April before January, so
	 * the mtime behind it is the real key.
	 */
	public function test_backups_sort_newest_first() {
		$this->make_backup( 'a_-_1700000000_-_older.sql', 1700000000 );
		$this->make_backup( 'b_-_1700009999_-_newer.sql', 1700009999 );

		$_GET  = array();
		$table = new WP_DBManager_Backups_Table();
		$table->prepare_items();

		$this->assertSame( 'newer.sql', $table->items[0]['database'] );
		$this->assertSame( 'older.sql', $table->items[1]['database'] );
	}

	/**
	 * Backups can be sorted by size.
	 */
	public function test_backups_sort_by_size() {
		$this->make_backup( 'a_-_1700000000_-_small.sql', 1700000000, 'x' );
		$this->make_backup( 'b_-_1700000001_-_big.sql', 1700000001, str_repeat( 'x', 500 ) );

		$_GET  = array(
			'orderby' => 'size',
			'order'   => 'asc',
		);
		$table = new WP_DBManager_Backups_Table();
		$table->prepare_items();

		$this->assertSame( 'small.sql', $table->items[0]['database'] );

		$_GET = array();
	}

	/**
	 * The bulk actions cover everything the old buttons did.
	 */
	public function test_the_backups_table_offers_every_action() {
		$actions = array_keys( ( new WP_DBManager_Backups_Table() )->get_bulk_actions() );

		sort( $actions );

		$this->assertSame( array( 'delete', 'download', 'email', 'restore' ), $actions );
	}

	/**
	 * The nonce actions match what WP_List_Table emits.
	 *
	 * WP_List_Table::display_tablenav() writes wp_nonce_field( 'bulk-' . $plural ), so a
	 * handler checking anything else rejects every submission.
	 */
	public function test_the_nonce_actions_match_the_rendered_field() {
		$this->assertSame( 'bulk-backups', WP_DBManager_Backups_Table::nonce_action() );
		$this->assertSame( 'bulk-tables', WP_DBManager_Tables_Table::nonce_action() );

		$html = $this->render_table( new WP_DBManager_Backups_Table() );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );

		$this->assertNotFalse(
			wp_verify_nonce(
				$this->nonce_from( $html ),
				WP_DBManager_Backups_Table::nonce_action()
			),
			'The rendered nonce does not verify against the action the handler checks.'
		);
	}

	/**
	 * Pull the nonce value out of rendered markup.
	 *
	 * @param string $html Rendered table.
	 * @return string
	 */
	protected function nonce_from( $html ) {
		preg_match( '/name="_wpnonce" value="([0-9a-f]+)"/', $html, $m );

		return isset( $m[1] ) ? $m[1] : '';
	}
}
