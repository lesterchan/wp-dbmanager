<?php
/**
 * Optimize Database admin page.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

// Check Whether User Can Manage Database.
if ( ! current_user_can( 'install_plugins' ) ) {
	die( 'Access Denied' );
}

dbmanager_page_optimize();

/**
 * Renders the page.
 *
 * Wrapped in a function because admin.php includes this file at global scope,
 * which would otherwise leak every variable below into the global namespace.
 */
function dbmanager_page_optimize() {
	global $wpdb;

	// Variables Variables Variables.
	$base_name = plugin_basename( 'wp-dbmanager/database-manager.php' );
	$base_page = 'admin.php?page=' . $base_name;

	$messages = array();

	// Form Processing.
	if ( ! empty( $_POST['do'] ) ) {
		// Verified before any request data is read, not part way down the switch.
		check_admin_referer( 'wp-dbmanager_optimize' );

		// Lets Prepare The Variables.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys are validated against SHOW TABLES before use.
		$optimize = ( ! empty( $_POST['optimize'] ) ? wp_unslash( $_POST['optimize'] ) : array() );

		// Decide What To Do.
		switch ( $_POST['do'] ) {
			case __( 'Optimize', 'wp-dbmanager' ):
				// The table names arrive as request keys, only act on ones that really exist.
				$valid_tables    = $wpdb->get_col( 'SHOW TABLES' );
				$selected_tables = array();
				foreach ( $optimize as $key => $value ) {
					if ( 'yes' === $value && in_array( $key, $valid_tables, true ) ) {
						$selected_tables[] = $key;
					}
				}
				if ( empty( $selected_tables ) ) {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'No Tables Selected', 'wp-dbmanager' ),
					);
				} else {
					$optimize2 = $wpdb->query( 'OPTIMIZE TABLE `' . implode( '`, `', $selected_tables ) . '`' );
					if ( ! $optimize2 ) {
						$messages[] = array(
							'type' => 'error',
							/* translators: %s: comma separated table names. */
							'text' => sprintf( __( 'Table(s) \'%s\' NOT Optimized', 'wp-dbmanager' ), implode( ', ', $selected_tables ) ),
						);
					} else {
						$messages[] = array(
							'type' => 'success',
							/* translators: %s: comma separated table names. */
							'text' => sprintf( __( 'Table(s) \'%s\' Optimized', 'wp-dbmanager' ), implode( ', ', $selected_tables ) ),
						);
					}
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
<!-- Optimize Database -->
<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . plugin_basename( __FILE__ ) ) ); ?>">
	<?php wp_nonce_field( 'wp-dbmanager_optimize' ); ?>
	<div class="wrap">
		<h2><?php esc_html_e( 'Optimize Database', 'wp-dbmanager' ); ?></h2>
		<br style="clear" />
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tables', 'wp-dbmanager' ); ?></th>
					<th><?php esc_html_e( 'Options', 'wp-dbmanager' ); ?></th>
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
						'<td><input type="radio" id="%1$s-no" name="optimize[%1$s]" value="no" />&nbsp;<label for="%1$s-no">%2$s</label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="%1$s-yes" name="optimize[%1$s]" value="yes" checked="checked" />&nbsp;<label for="%1$s-yes">%3$s</label></td></tr>',
						esc_attr( $table_name ),
						esc_html__( 'No', 'wp-dbmanager' ),
						esc_html__( 'Yes', 'wp-dbmanager' )
					);
				}
				?>
			<tr>
				<td colspan="2" align="center"><?php esc_html_e( 'Database should be optimized once every month.', 'wp-dbmanager' ); ?></td>
			</tr>
			<tr>
				<td colspan="2" align="center"><input type="submit" name="do" value="<?php esc_html_e( 'Optimize', 'wp-dbmanager' ); ?>" class="button" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php esc_html_e( 'Cancel', 'wp-dbmanager' ); ?>" class="button" onclick="javascript:history.go(-1)" /></td>
			</tr>
		</table>
	</div>
</form>

	<?php
}
