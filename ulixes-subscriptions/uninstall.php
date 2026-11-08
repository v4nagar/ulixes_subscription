<?php
/**
 * Uninstall Ulixes Subscriptions.
 *
 * Cleans up scheduled hooks. Subscription records and settings are preserved by default unless
 * `ULX_REMOVE_ALL_DATA` constant is defined as true in `wp-config.php`.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'ulx_daily_maintenance' );

if ( defined( 'ULX_REMOVE_ALL_DATA' ) && true === ULX_REMOVE_ALL_DATA ) {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ulx\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

