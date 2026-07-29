<?php
/**
 * Uninstall WP-DBManager.
 *
 * Runs with the plugin inactive, so nothing here may depend on the plugin's
 * own classes or constants being loaded. Every row name is therefore spelled
 * out rather than read from WP_DBManager_Options.
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
 * The pre-4.0.0 names are removed too: a site that never loaded the plugin
 * again after upgrading still carries them, and the migration that would have
 * cleared them never got the chance to run.
 *
 * @return void
 */
function wp_dbmanager_uninstall_site() {
	delete_option( 'wp_dbmanager_options' );
	delete_option( 'wp_dbmanager_version' );
	delete_option( 'dbmanager_options' );

	delete_transient( 'wp_dbmanager_backup_folder_public' );
	delete_transient( 'dbmanager_backup_folder_public' );

	foreach ( array( 'backup', 'optimize', 'repair' ) as $wp_dbmanager_job ) {
		wp_clear_scheduled_hook( 'wp_dbmanager_cron_' . $wp_dbmanager_job );
		wp_clear_scheduled_hook( 'dbmanager_cron_' . $wp_dbmanager_job );
	}
}

if ( is_multisite() ) {
	/*
	 * 'number' => 0 lifts WP_Site_Query's default cap of 100. Without it a
	 * network larger than that keeps its options and its cron events on every
	 * site past the hundredth, and uninstall still reports success.
	 */
	$wp_dbmanager_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $wp_dbmanager_site_ids as $wp_dbmanager_site_id ) {
		// switch_to_blog() pushes onto a stack, so the restore belongs inside
		// the loop -- restoring once at the end leaves it unwound by one.
		switch_to_blog( (int) $wp_dbmanager_site_id );

		wp_dbmanager_uninstall_site();

		restore_current_blog();
	}
} else {
	wp_dbmanager_uninstall_site();
}
