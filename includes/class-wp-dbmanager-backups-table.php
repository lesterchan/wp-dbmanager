<?php
/**
 * The backup files list table.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The backup files, on core's list table.
 *
 * Replaces the hand-rolled table and its single radio column. Every action is
 * still a POST: a restore that could be triggered by following a link would be
 * one prefetch away from overwriting the database, so none of these are row
 * action links.
 *
 * @since 3.0.0
 */
class WP_DBManager_Backups_Table extends WP_List_Table {

	/**
	 * Total size of every backup listed.
	 *
	 * @var int
	 */
	protected $total_size = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'backup',
				'plural'   => 'backups',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The nonce action guarding this screen's bulk actions.
	 *
	 * @return string
	 */
	public static function nonce_action() {
		return 'bulk-backups';
	}

	/**
	 * The table's columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'database' => __( 'Database File', 'wp-dbmanager' ),
			'checksum' => __( 'MD5 Checksum', 'wp-dbmanager' ),
			'date'     => __( 'Date/Time', 'wp-dbmanager' ),
			'size'     => __( 'Size', 'wp-dbmanager' ),
		);
	}

	/**
	 * The sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'database' => array( 'database', false ),
			'date'     => array( 'date', true ),
			'size'     => array( 'size', false ),
		);
	}

	/**
	 * The bulk actions.
	 *
	 * Restore and Download act on exactly one backup; the screen says so, and
	 * the handler refuses more than one rather than guessing.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array(
			'download' => __( 'Download', 'wp-dbmanager' ),
			'restore'  => __( 'Restore', 'wp-dbmanager' ),
			'email'    => __( 'E-Mail', 'wp-dbmanager' ),
			'delete'   => __( 'Delete', 'wp-dbmanager' ),
		);
	}

	/**
	 * Total size of the backups on screen.
	 *
	 * @return int
	 */
	public function total_size() {
		return $this->total_size;
	}

	/**
	 * Load the backup files.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$path  = WP_DBManager_Options::backup_path();
		$items = array();

		foreach ( WP_DBManager_Backups::all( $path ) as $entry ) {
			$file = WP_DBManager_Backups::parse_file( $path . '/' . $entry['name'] );

			$items[]           = array(
				'name'     => $entry['name'],
				'database' => $file['database'],
				'checksum' => $file['checksum'],
				'date'     => $file['formatted_date'],
				'mtime'    => $entry['mtime'],
				'size'     => (int) $file['size'],
			);
			$this->total_size += (int) $file['size'];
		}

		$orderby = WP_DBManager_Admin::sort_arg( 'orderby', 'date' );
		$order   = WP_DBManager_Admin::sort_arg( 'order', 'desc' );

		if ( ! array_key_exists( $orderby, $this->get_sortable_columns() ) ) {
			$orderby = 'date';
		}

		usort(
			$items,
			static function ( $a, $b ) use ( $orderby ) {
				if ( 'database' === $orderby ) {
					return strcmp( $a['database'], $b['database'] );
				}

				// Sorting by the formatted date would sort alphabetically, which
				// puts April before January. The mtime behind it is the real key.
				$key = ( 'date' === $orderby ) ? 'mtime' : 'size';

				return $a[ $key ] <=> $b[ $key ];
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
	 * @param array $item Backup row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="backups[]" value="%s" />',
			esc_attr( $item['name'] )
		);
	}

	/**
	 * The database file name.
	 *
	 * @param array $item Backup row.
	 * @return string
	 */
	public function column_database( $item ) {
		return '<strong><span dir="ltr">' . esc_html( $item['database'] ) . '</span></strong>';
	}

	/**
	 * The size, formatted.
	 *
	 * @param array $item Backup row.
	 * @return string
	 */
	public function column_size( $item ) {
		return esc_html( WP_DBManager_Backups::format_size( $item['size'] ) );
	}

	/**
	 * Any other column.
	 *
	 * @param array  $item        Backup row.
	 * @param string $column_name Column being rendered.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * Shown when the backup folder holds nothing.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'There Are No Database Backup Files Available.', 'wp-dbmanager' );
	}
}
