<?php
/**
 * Removes Pressdock data on uninstall, only when the admin opted in.
 *
 * @package Pressdock
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Installer.php';

/**
 * Clean one site.
 *
 * @return void
 */
function pdock_uninstall_site() {
	$settings = get_option( 'pdock_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}
	wp_clear_scheduled_hook( 'pdock_daily_prune' );
	Pressdock\Installer::drop_all();
	delete_option( 'pdock_baseline' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $pdock_site_id ) {
		switch_to_blog( (int) $pdock_site_id );
		pdock_uninstall_site();
		restore_current_blog();
	}
} else {
	pdock_uninstall_site();
}
