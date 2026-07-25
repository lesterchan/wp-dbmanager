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
	$ms_sites = function_exists( 'get_sites' ) ? get_sites() : wp_get_sites();

	if ( 0 < count( $ms_sites ) ) {
		foreach ( $ms_sites as $ms_site ) {
			// Not $blog_id, that is a WordPress global and this file runs at global scope.
			$dbmanager_blog_id = class_exists( 'WP_Site' ) ? $ms_site->blog_id : $ms_site['blog_id'];
			switch_to_blog( $dbmanager_blog_id );
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
