<?php
/**
 * Empty/Drop Tables admin page.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

// Check Whether User Can Manage Database.
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

	// Variables Variables Variables.
	$base_name               = plugin_basename( 'wp-dbmanager/database-manager.php' );
	$base_page               = 'admin.php?page=' . $base_name;
	$backup                  = array();
	$backup_options          = get_option( 'dbmanager_options' );
	$backup['date']          = time();
	$backup['mysqldumppath'] = $backup_options['mysqldumppath'];
	$backup['mysqlpath']     = $backup_options['mysqlpath'];
	$backup['path']          = $backup_options['path'];

	$messages = array();

	// Form Processing.
	if ( ! empty( $_POST['do'] ) ) {
		// Verified before any request data is read, not part way down the switch.
		check_admin_referer( 'wp-dbmanager_empty' );

		// Lets Prepare The Variables.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys are validated against SHOW TABLES before use.
		$emptydrop = ( ! empty( $_POST['emptydrop'] ) ? wp_unslash( $_POST['emptydrop'] ) : array() );

		// Decide What To Do.
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
					if ( 'empty' === $value ) {
						$empty_tables[] = $key;
					} elseif ( 'drop' === $value ) {
						$drop_tables[] = $key;
					}
				}
				if ( empty( $empty_tables ) && empty( $drop_tables ) ) {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'No Tables Selected.', 'wp-dbmanager' ),
					);
				}
				if ( ! empty( $empty_tables ) ) {
					foreach ( $empty_tables as $empty_table ) {
						$empty_query = $wpdb->query( "TRUNCATE `$empty_table`" );
						$messages[]  = array(
							'type' => 'success',
							/* translators: %s: table name. */
							'text' => sprintf( __( 'Table \'%s\' Emptied', 'wp-dbmanager' ), $empty_table ),
						);
					}
				}
				if ( ! empty( $drop_tables ) ) {
					$drop_query = $wpdb->query( 'DROP TABLE `' . implode( '`, `', $drop_tables ) . '`' );
					$messages[] = array(
						'type' => 'success',
						/* translators: %s: comma separated table names. */
						'text' => sprintf( __( 'Table(s) \'%s\' Dropped', 'wp-dbmanager' ), implode( ', ', $drop_tables ) ),
					);
				}
				break;
		}
	}

	// Show Tables.
	$tables = $wpdb->get_col( 'SHOW TABLES' );
	?>
	<?php
	dbmanager_render_messages( $messages );
	?>
<!-- Empty/Drop Tables -->
<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . plugin_basename( __FILE__ ) ) ); ?>">
	<?php wp_nonce_field( 'wp-dbmanager_empty' ); ?>
	<div class="wrap">
		<h2><?php esc_html_e( 'Empty/Drop Tables', 'wp-dbmanager' ); ?></h2>
		<br style="clear" />
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tables', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'Empty', 'wp-dbmanager' ); ?> <sup><?php esc_html_e( '1', 'wp-dbmanager' ); ?></sup></th>
					<th><?php esc_html_e( 'Drop', 'wp-dbmanager' ); ?> <sup><?php esc_html_e( '2', 'wp-dbmanager' ); ?></sup></th>
				</tr>
			</thead>
				<?php
					$no = 0;
				foreach ( $tables as $table_name ) {
					if ( 0 === $no % 2 ) {
						$style = '';
					} else {
						$style = 'alternate';
					}
					++$no;
					printf(
						'<tr class="%1$s"><th align="left" scope="row">%2$s</th>',
						esc_attr( $style ),
						esc_html( $table_name )
					);
					printf(
						'<td><input type="radio" id="%1$s-empty" name="emptydrop[%1$s]" value="empty" />&nbsp;<label for="%1$s-empty">%2$s</label></td>',
						esc_attr( $table_name ),
						esc_html__( 'Empty', 'wp-dbmanager' )
					);
					printf(
						'<td><input type="radio" id="%1$s-drop" name="emptydrop[%1$s]" value="drop" />&nbsp;<label for="%1$s-drop">%2$s</label></td></tr>',
						esc_attr( $table_name ),
						esc_html__( 'Drop', 'wp-dbmanager' )
					);
				}
				?>
			<tr>
				<td colspan="3">
					<?php esc_html_e( '1. EMPTYING a table means all the rows in the table will be deleted. This action is not REVERSIBLE.', 'wp-dbmanager' ); ?>
					<br />
					<?php esc_html_e( '2. DROPPING a table means deleting the table. This action is not REVERSIBLE.', 'wp-dbmanager' ); ?>
				</td>
			</tr>
			<tr>
				<td colspan="3" align="center"><input type="submit" name="do" value="<?php esc_html_e( 'Empty/Drop', 'wp-dbmanager' ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'You Are About To Empty Or Drop The Selected Databases.\nThis Action Is Not Reversible.\n\n Choose [Cancel] to stop, [Ok] to delete.', 'wp-dbmanager' ) ); ?>')" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php esc_html_e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" onclick="javascript:history.go(-1)" /></td>
			</tr>
		</table>
	</div>
</form>
	<?php
}
