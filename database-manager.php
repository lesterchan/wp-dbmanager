<?php
/**
 * Database information admin page.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

// Check Whether User Can Manage Database.
if ( ! current_user_can( 'install_plugins' ) ) {
	die( 'Access Denied' );
}

dbmanager_page_manager();

/**
 * Renders the page.
 *
 * Wrapped in a function because admin.php includes this file at global scope,
 * which would otherwise leak every variable below into the global namespace.
 */
function dbmanager_page_manager() {
	global $wpdb;

	// Variables Variables Variables.
	$base_name               = plugin_basename( 'wp-dbmanager/database-manager.php' );
	$base_page               = 'admin.php?page=' . $base_name;
	$backup                  = array();
	$backup_options          = get_option( 'dbmanager_options' );
	$backup['date']          = time();
	$backup['mysqldumppath'] = $backup_options['mysqldumppath'];
	$backup['mysqlpath']     = $backup_options['mysqlpath'];
	$backup['path']          = $backup_options['path'];

	// Get MYSQL Version.
	$sqlversion = $wpdb->get_var( 'SELECT VERSION() AS version' );
	?>
<!-- Database Information -->
<div class="wrap">
	<h2><?php esc_html_e( 'Database', 'wp-dbmanager' ); ?></h2>
	<h3><?php esc_html_e( 'Database Information', 'wp-dbmanager' ); ?></h3>
	<br style="clear" />
	<table class="widefat">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Setting', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Value', 'wp-dbmanager' ); ?></th>
			</tr>
		</thead>
		<tr>
			<td><?php esc_html_e( 'Database Host', 'wp-dbmanager' ); ?></td>
			<td><?php echo esc_html( DB_HOST ); ?></td>
		</tr>
		<tr class="alternate">
			<td><?php esc_html_e( 'Database Name', 'wp-dbmanager' ); ?></td>
			<td><?php echo esc_html( DB_NAME ); ?></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Database User', 'wp-dbmanager' ); ?></td>
			<td><?php echo esc_html( DB_USER ); ?></td>
		</tr>
		<tr class="alternate">
			<td><?php esc_html_e( 'Database Type', 'wp-dbmanager' ); ?></td>
			<td>MYSQL</td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Database Version', 'wp-dbmanager' ); ?></td>
			<td>v<?php echo esc_html( $sqlversion ); ?></td>
		</tr>
	</table>
</div>
<p>&nbsp;</p>

<div class="wrap">
	<h3><?php esc_html_e( 'Tables Information', 'wp-dbmanager' ); ?></h3>
	<br style="clear" />
	<table class="widefat">
		<thead>
			<tr>
				<th><?php esc_html_e( 'No.', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Tables', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Records', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Data Usage', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Index Usage', 'wp-dbmanager' ); ?></th>
				<th><?php esc_html_e( 'Overhead', 'wp-dbmanager' ); ?></th>
			</tr>
		</thead>
		<?php
			$no             = 0;
			$row_usage      = 0;
			$data_usage     = 0;
			$index_usage    = 0;
			$overhead_usage = 0;
			$tablesstatus   = $wpdb->get_results( 'SHOW TABLE STATUS' );
		foreach ( $tablesstatus as  $tablestatus ) {
			if ( 0 === $no % 2 ) {
				$style = '';
			} else {
				$style = 'alternate';
			}
			++$no;
			printf(
				"<tr class=\"%1\$s\"><td>%2\$s</td><td>%3\$s</td><td>%4\$s</td><td>%5\$s</td><td>%6\$s</td><td>%7\$s</td></tr>\n",
				esc_attr( $style ),
				esc_html( number_format_i18n( $no ) ),
				esc_html( $tablestatus->Name ),
				esc_html( number_format_i18n( $tablestatus->Rows ) ),
				esc_html( format_size( $tablestatus->Data_length ) ),
				esc_html( format_size( $tablestatus->Index_length ) ),
				esc_html( format_size( $tablestatus->Data_free ) )
			);
			$row_usage      += $tablestatus->Rows;
			$data_usage     += $tablestatus->Data_length;
			$index_usage    += $tablestatus->Index_length;
			$overhead_usage += $tablestatus->Data_free;
		}
			/* translators: %s: number of tables. */
			$total_tables = sprintf( _n( '%s Table', '%s Tables', $no, 'wp-dbmanager' ), number_format_i18n( $no ) );
			/* translators: %s: number of records. */
			$total_records = sprintf( _n( '%s Record', '%s Records', $row_usage, 'wp-dbmanager' ), number_format_i18n( $row_usage ) );
			printf(
				"<tr class=\"thead\"><th>%1\$s</th><th>%2\$s</th><th>%3\$s</th><th>%4\$s</th><th>%5\$s</th><th>%6\$s</th></tr>\n",
				esc_html__( 'Total:', 'wp-dbmanager' ),
				esc_html( $total_tables ),
				esc_html( $total_records ),
				esc_html( format_size( $data_usage ) ),
				esc_html( format_size( $index_usage ) ),
				esc_html( format_size( $overhead_usage ) )
			);
		?>
	</table>
</div>
	<?php
}
