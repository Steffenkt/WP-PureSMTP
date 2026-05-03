<?php
/**
 * Uninstall hook – only runs when the user deletes the plugin via WP admin.
 *
 * Removes all plugin data (options + DB tables) ONLY when the
 * "Remove data on uninstall" option is enabled.
 *
 * @package WP_PureSMTP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'puresmtp_uninstall' ) ) {
	return;
}

global $wpdb;

// Delete all plugin options.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'puresmtp_' ) . '%'
	)
);

// Drop custom tables.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}puresmtp_log" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}puresmtp_queue" );

// Remove scheduled cron event.
wp_clear_scheduled_hook( 'puresmtp_process_queue' );
