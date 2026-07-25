<?php
defined( 'ABSPATH' ) || exit;

// Check Whether User Can Manage Database
if ( ! current_user_can( 'install_plugins' ) ) {
	die( 'Access Denied' );
}

dbmanager_page_empty();

/**
 * Renders the page.
 *
 * Wrapped in a function because admin.php includes this file at global scope,
 * which would otherwise leak every variable below into the global namespace.
 */
function dbmanager_page_empty() {
	global $wpdb;



// Variables Variables Variables
$base_name               = plugin_basename( 'wp-dbmanager/database-manager.php' );
$base_page               = 'admin.php?page=' . $base_name;
$backup                  = array();
$backup_options          = get_option( 'dbmanager_options' );
$backup['date']          = current_time( 'timestamp' );
$backup['mysqldumppath'] = $backup_options['mysqldumppath'];
$backup['mysqlpath']     = $backup_options['mysqlpath'];
$backup['path']          = $backup_options['path'];


// Form Processing
if ( ! empty( $_POST['do'] ) ) {
	// Verified before any request data is read, not part way down the switch.
	check_admin_referer( 'wp-dbmanager_empty' );

	// Lets Prepare The Variables
	$emptydrop = ( ! empty( $_POST['emptydrop'] ) ? $_POST['emptydrop'] : array() );
	$text      = '';

	// Decide What To Do
	switch ( $_POST['do'] ) {
		case __( 'Empty/Drop', 'wp-dbmanager' ):
			// The table names arrive as request keys, only act on ones that really exist.
			$valid_tables = $wpdb->get_col( 'SHOW TABLES' );
			$empty_tables = array();
			$drop_tables  = array();
			foreach ( $emptydrop as $key => $value ) {
				if ( ! in_array( $key, $valid_tables, true ) ) {
					continue;
				}
				if ( $value == 'empty' ) {
					$empty_tables[] = $key;
				} elseif ( $value == 'drop' ) {
					$drop_tables[] = $key;
				}
			}
			if ( empty( $empty_tables ) && empty( $drop_tables ) ) {
				$text = '<p style="color: red;">' . __( 'No Tables Selected.', 'wp-dbmanager' ) . '</p>';
			}
			if ( ! empty( $empty_tables ) ) {
				foreach ( $empty_tables as $empty_table ) {
					$empty_query = $wpdb->query( "TRUNCATE `$empty_table`" );
					$text       .= '<p style="color: green;">' . sprintf( __( 'Table \'%s\' Emptied', 'wp-dbmanager' ), esc_html( $empty_table ) ) . '</p>';
				}
			}
			if ( ! empty( $drop_tables ) ) {
				$drop_query = $wpdb->query( 'DROP TABLE `' . implode( '`, `', $drop_tables ) . '`' );
				$text       = '<p style="color: green;">' . sprintf( __( 'Table(s) \'%s\' Dropped', 'wp-dbmanager' ), esc_html( implode( ', ', $drop_tables ) ) ) . '</p>';
			}
			break;
	}
}


// Show Tables
$tables = $wpdb->get_col( 'SHOW TABLES' );
?>
<?php
if ( ! empty( $text ) ) {
	echo '<!-- Last Action --><div id="message" class="updated fade"><p>' . $text . '</p></div>'; }
?>
<!-- Empty/Drop Tables -->
<form method="post" action="<?php echo admin_url( 'admin.php?page=' . plugin_basename( __FILE__ ) ); ?>">
	<?php wp_nonce_field( 'wp-dbmanager_empty' ); ?>
	<div class="wrap">
		<h2><?php _e( 'Empty/Drop Tables', 'wp-dbmanager' ); ?></h2>
		<br style="clear" />
		<table class="widefat">
			<thead>
				<tr>
					<th><?php _e( 'Tables', 'wp-dbmanager' ); ?></th>
					<th><?php _e( 'Empty', 'wp-dbmanager' ); ?> <sup><?php _e( '1', 'wp-dbmanager' ); ?></sup></th>
					<th><?php _e( 'Drop', 'wp-dbmanager' ); ?> <sup><?php _e( '2', 'wp-dbmanager' ); ?></sup></th>
				</tr>
			</thead>
				<?php
					$no = 0;
				foreach ( $tables as $table_name ) {
					if ( $no % 2 == 0 ) {
						$style = '';
					} else {
						$style = ' class="alternate"';
					}
					++$no;
					$table_attr = esc_attr( $table_name );
					echo "<tr $style><th align=\"left\" scope=\"row\">" . esc_html( $table_name ) . "</th>\n";
					echo "<td><input type=\"radio\" id=\"$table_attr-empty\" name=\"emptydrop[$table_attr]\" value=\"empty\" />&nbsp;<label for=\"$table_attr-empty\">" . __( 'Empty', 'wp-dbmanager' ) . '</label></td>';
					echo "<td><input type=\"radio\" id=\"$table_attr-drop\" name=\"emptydrop[$table_attr]\" value=\"drop\" />&nbsp;<label for=\"$table_attr-drop\">" . __( 'Drop', 'wp-dbmanager' ) . '</label></td></tr>';
				}
				?>
			<tr>
				<td colspan="3">
					<?php _e( '1. EMPTYING a table means all the rows in the table will be deleted. This action is not REVERSIBLE.', 'wp-dbmanager' ); ?>
					<br />
					<?php _e( '2. DROPPING a table means deleting the table. This action is not REVERSIBLE.', 'wp-dbmanager' ); ?>
				</td>
			</tr>
			<tr>
				<td colspan="3" align="center"><input type="submit" name="do" value="<?php _e( 'Empty/Drop', 'wp-dbmanager' ); ?>" class="button" onclick="return confirm('<?php _e( 'You Are About To Empty Or Drop The Selected Databases.\nThis Action Is Not Reversible.\n\n Choose [Cancel] to stop, [Ok] to delete.', 'wp-dbmanager' ); ?>')" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php _e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" onclick="javascript:history.go(-1)" /></td>
			</tr>
		</table>
	</div>
</form>
<?php
}
