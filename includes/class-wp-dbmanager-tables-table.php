<?php
/**
 * The database tables list table.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The site's tables, on core's list table.
 *
 * Four screens listed the same tables four slightly different ways before
 * 3.0.0, each with its own hand-rolled markup and its own alternating-row
 * counter. They share this one class now, differing only in which bulk actions
 * they offer - which is also what stops the Optimize screen offering a table
 * the Empty screen would refuse.
 *
 * The stats columns come along for free on all of them. Deciding what is worth
 * optimizing is much easier when the overhead is on screen next to the name.
 *
 * @since 3.0.0
 */
class WP_DBManager_Tables_Table extends WP_List_Table {

	/**
	 * Which screen this instance is serving.
	 *
	 * One of info, optimize, repair or empty.
	 *
	 * @var string
	 */
	protected $mode;

	/**
	 * Column totals, filled in by prepare_items().
	 *
	 * @var array
	 */
	protected $totals = array(
		'rows'     => 0,
		'data'     => 0,
		'index'    => 0,
		'overhead' => 0,
	);

	/**
	 * Constructor.
	 *
	 * @param string $mode One of info, optimize, repair or empty.
	 */
	public function __construct( $mode = 'info' ) {
		$this->mode = $mode;

		parent::__construct(
			array(
				'singular' => 'table',
				'plural'   => 'tables',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The nonce action guarding this screen's bulk actions.
	 *
	 * WP_List_Table emits wp_nonce_field( 'bulk-' . $plural ) from
	 * display_tablenav(), so the check has to use the same string.
	 *
	 * @return string
	 */
	public static function nonce_action() {
		return 'bulk-tables';
	}

	/**
	 * The table's columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array();

		// The information screen reports, it does not act, so it has nothing to
		// select.
		if ( 'info' !== $this->mode ) {
			$columns['cb'] = '<input type="checkbox" />';
		}

		$columns['name']     = __( 'Tables', 'wp-dbmanager' );
		$columns['rows']     = __( 'Records', 'wp-dbmanager' );
		$columns['data']     = __( 'Data Usage', 'wp-dbmanager' );
		$columns['index']    = __( 'Index Usage', 'wp-dbmanager' );
		$columns['overhead'] = __( 'Overhead', 'wp-dbmanager' );

		return $columns;
	}

	/**
	 * The sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'     => array( 'name', true ),
			'rows'     => array( 'rows', false ),
			'data'     => array( 'data', false ),
			'index'    => array( 'index', false ),
			'overhead' => array( 'overhead', false ),
		);
	}

	/**
	 * The bulk actions this screen offers.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		switch ( $this->mode ) {
			case 'optimize':
				return array( 'optimize' => __( 'Optimize', 'wp-dbmanager' ) );
			case 'repair':
				return array( 'repair' => __( 'Repair', 'wp-dbmanager' ) );
			case 'empty':
				return array(
					'empty' => __( 'Empty', 'wp-dbmanager' ),
					'drop'  => __( 'Drop', 'wp-dbmanager' ),
				);
		}

		return array();
	}

	/**
	 * Load the tables.
	 *
	 * SHOW TABLE STATUS returns everything in one go and a site has tens of
	 * tables rather than thousands, so this sorts in PHP rather than asking the
	 * database to do it again per column.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$items = array();

		foreach ( WP_DBManager_Tables::status() as $status ) {
			// SHOW TABLE STATUS names its columns Name, Rows, Data_length and so
			// on, which are MySQL's spellings and not ours to rename. Read as an
			// array rather than as object properties so the coding standard is
			// not being asked to accept a property name nobody here chose.
			$status = (array) $status;

			$item = array(
				'name'     => $status['Name'],
				'rows'     => (int) $status['Rows'],
				'data'     => (int) $status['Data_length'],
				'index'    => (int) $status['Index_length'],
				'overhead' => (int) $status['Data_free'],
			);

			$this->totals['rows']     += $item['rows'];
			$this->totals['data']     += $item['data'];
			$this->totals['index']    += $item['index'];
			$this->totals['overhead'] += $item['overhead'];

			$items[] = $item;
		}

		$orderby = WP_DBManager_Admin::sort_arg( 'orderby', 'name' );
		$order   = 'desc' === WP_DBManager_Admin::sort_arg( 'order', 'asc' ) ? 'desc' : 'asc';

		if ( ! array_key_exists( $orderby, $this->get_sortable_columns() ) ) {
			$orderby = 'name';
		}

		usort(
			$items,
			static function ( $a, $b ) use ( $orderby ) {
				if ( 'name' === $orderby ) {
					return strcmp( $a['name'], $b['name'] );
				}

				return $a[ $orderby ] <=> $b[ $orderby ];
			}
		);

		if ( 'desc' === $order ) {
			$items = array_reverse( $items );
		}

		$this->items = $items;
	}

	/**
	 * The selection checkbox.
	 *
	 * @param array $item Table row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="tables[]" value="%s" />',
			esc_attr( $item['name'] )
		);
	}

	/**
	 * The table name.
	 *
	 * @param array $item Table row.
	 * @return string
	 */
	public function column_name( $item ) {
		return '<strong>' . esc_html( $item['name'] ) . '</strong>';
	}

	/**
	 * Any other column.
	 *
	 * @param array  $item        Table row.
	 * @param string $column_name Column being rendered.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		if ( 'rows' === $column_name ) {
			return esc_html( number_format_i18n( $item['rows'] ) );
		}

		if ( isset( $item[ $column_name ] ) ) {
			return esc_html( WP_DBManager_Backups::format_size( $item[ $column_name ] ) );
		}

		return '';
	}

	/**
	 * Shown when the database has no tables at all.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No tables found.', 'wp-dbmanager' );
	}

	/**
	 * The rows, followed by a totals row.
	 *
	 * WP_List_Table has no notion of a footer total, and the totals are the
	 * point of the information screen, so they are appended here rather than
	 * printed outside the table where they would not line up with the columns.
	 *
	 * @return void
	 */
	public function display_rows() {
		parent::display_rows();

		if ( 'info' !== $this->mode || empty( $this->items ) ) {
			return;
		}

		printf(
			'<tr class="thead"><th scope="row">%1$s</th><th>%2$s</th><th>%3$s</th><th>%4$s</th><th>%5$s</th></tr>',
			esc_html(
				sprintf(
					/* translators: %s: number of tables. */
					_n( '%s Table', '%s Tables', count( $this->items ), 'wp-dbmanager' ),
					number_format_i18n( count( $this->items ) )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: number of records. */
					_n( '%s Record', '%s Records', $this->totals['rows'], 'wp-dbmanager' ),
					number_format_i18n( $this->totals['rows'] )
				)
			),
			esc_html( WP_DBManager_Backups::format_size( $this->totals['data'] ) ),
			esc_html( WP_DBManager_Backups::format_size( $this->totals['index'] ) ),
			esc_html( WP_DBManager_Backups::format_size( $this->totals['overhead'] ) )
		);
	}
}
