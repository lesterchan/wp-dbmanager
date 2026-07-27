<?php
/**
 * Uninstall routine, removes the plugin options and scheduled events.
 *
 * @package WP-DBManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the plugin's data for the current site.
 *
 * The database backup files are deliberately left on disk. They are the user's
 * data, not the plugin's, and removing the plugin should not destroy the only
 * copy of their backups.
 *
 * @return void
 */
function dbmanager_uninstall_site() {
	delete_option( 'dbmanager_options' );
	delete_transient( 'dbmanager_backup_folder_public' );

	wp_clear_scheduled_hook( 'dbmanager_cron_backup' );
	wp_clear_scheduled_hook( 'dbmanager_cron_optimize' );
	wp_clear_scheduled_hook( 'dbmanager_cron_repair' );
}

if ( is_multisite() ) {
	// 'number' => 0 lifts WP_Site_Query's default cap of 100. Without it a
	// network larger than that keeps its options and its cron events on every
	// site past the hundredth, and uninstall still reports success.
	$dbmanager_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $dbmanager_site_ids as $dbmanager_site_id ) {
		switch_to_blog( (int) $dbmanager_site_id );
		dbmanager_uninstall_site();
		// Paired inside the loop: switch_to_blog() pushes onto a stack, so
		// restoring once at the end would leave it unwound by exactly one.
		restore_current_blog();
	}
} else {
	dbmanager_uninstall_site();
}
