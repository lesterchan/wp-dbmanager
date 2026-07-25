<?php
/**
 * Uninstall routine, removes the plugin options and scheduled events.
 *
 * @package WP-DBManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	// number => 0 lifts the default limit of 100, a network can be larger.
	$ms_sites = get_sites( array( 'number' => 0 ) );

	if ( 0 < count( $ms_sites ) ) {
		foreach ( $ms_sites as $ms_site ) {
			switch_to_blog( $ms_site->blog_id );
			dbmanager_uninstalled();
			// Paired inside the loop, switch_to_blog() stacks.
			restore_current_blog();
		}
	}
} else {
	dbmanager_uninstalled();
}

/**
 * Delete plugin data when uninstalled
 *
 * The database backup files are deliberately left on disk. They are the
 * user's data, not the plugin's, and removing the plugin should not
 * destroy the only copy of their backups.
 *
 * @access public
 * @return void
 */
function dbmanager_uninstalled() {
	$option_name = 'dbmanager_options';

	delete_option( $option_name );

	wp_clear_scheduled_hook( 'dbmanager_cron_backup' );
	wp_clear_scheduled_hook( 'dbmanager_cron_optimize' );
	wp_clear_scheduled_hook( 'dbmanager_cron_repair' );
}
