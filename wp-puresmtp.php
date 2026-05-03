<?php
/**
 * Plugin Name:       WP PureSMTP
 * Plugin URI:        https://github.com/Steffenkt/WP-PureSMTP
 * Description:       Replace WordPress default mail with a clean, pure SMTP connection. No third-party APIs, no bloat — just SMTP.
 * Version:           1.0.0
 * Requires at least: 5.7
 * Requires PHP:      7.4
 * Author:            Steffenkt
 * Author URI:        https://github.com/Steffenkt
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-puresmtp
 * Domain Path:       /languages
 *
 * @package WP_PureSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PURESMTP_VERSION',     '1.0.0' );
define( 'PURESMTP_PLUGIN_FILE', __FILE__ );
define( 'PURESMTP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PURESMTP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Load classes.
require_once PURESMTP_PLUGIN_DIR . 'includes/class-puresmtp-options.php';
require_once PURESMTP_PLUGIN_DIR . 'includes/class-puresmtp-logger.php';
require_once PURESMTP_PLUGIN_DIR . 'includes/class-puresmtp-queue.php';
require_once PURESMTP_PLUGIN_DIR . 'includes/class-puresmtp-mailer.php';
require_once PURESMTP_PLUGIN_DIR . 'includes/class-puresmtp-admin.php';
require_once PURESMTP_PLUGIN_DIR . 'includes/class-puresmtp-testmail.php';

// Activation & deactivation hooks.
register_activation_hook( __FILE__, 'puresmtp_activate' );
register_deactivation_hook( __FILE__, 'puresmtp_deactivate' );

/**
 * Runs on plugin activation: creates DB tables and schedules WP-Cron.
 */
function puresmtp_activate(): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	$log_table = $wpdb->prefix . 'puresmtp_log';
	$sql_log   = "CREATE TABLE {$log_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		date DATETIME NOT NULL,
		recipient TEXT NOT NULL,
		subject TEXT NOT NULL,
		status VARCHAR(10) NOT NULL DEFAULT 'sent',
		error_code VARCHAR(100) DEFAULT NULL,
		error_message TEXT DEFAULT NULL,
		debug_trace LONGTEXT DEFAULT NULL,
		source_plugin VARCHAR(200) DEFAULT NULL,
		PRIMARY KEY  (id)
	) {$charset_collate};";

	$queue_table = $wpdb->prefix . 'puresmtp_queue';
	$sql_queue   = "CREATE TABLE {$queue_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		date_added DATETIME NOT NULL,
		next_retry DATETIME NOT NULL,
		attempt_count INT NOT NULL DEFAULT 0,
		max_attempts INT NOT NULL DEFAULT 5,
		recipient TEXT NOT NULL,
		subject TEXT NOT NULL,
		headers TEXT DEFAULT NULL,
		message LONGTEXT NOT NULL,
		attachments TEXT DEFAULT NULL,
		reason VARCHAR(50) NOT NULL DEFAULT 'smtp_failure',
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		PRIMARY KEY  (id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_log );
	dbDelta( $sql_queue );

	if ( ! wp_next_scheduled( 'puresmtp_process_queue' ) ) {
		wp_schedule_event( time(), 'puresmtp_retry_interval', 'puresmtp_process_queue' );
	}
}

/**
 * Runs on plugin deactivation.
 */
function puresmtp_deactivate(): void {
	wp_clear_scheduled_hook( 'puresmtp_process_queue' );
}

/**
 * Register custom WP-Cron intervals.
 */
add_filter(
	'cron_schedules',
	function ( array $schedules ): array {
		$retry_interval = get_option( 'puresmtp_retry_interval', '15min' );
		$interval_map   = [
			'5min'  => 5 * MINUTE_IN_SECONDS,
			'15min' => 15 * MINUTE_IN_SECONDS,
			'30min' => 30 * MINUTE_IN_SECONDS,
			'1hour' => HOUR_IN_SECONDS,
		];

		$interval_seconds = $interval_map[ $retry_interval ] ?? ( 15 * MINUTE_IN_SECONDS );

		$schedules['puresmtp_retry_interval'] = [
			'interval' => $interval_seconds,
			'display'  => __( 'WP PureSMTP Retry Interval', 'wp-puresmtp' ),
		];

		return $schedules;
	}
);

/**
 * Bootstrap plugin after all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	function (): void {
		load_plugin_textdomain(
			'wp-puresmtp',
			false,
			dirname( plugin_basename( PURESMTP_PLUGIN_FILE ) ) . '/languages'
		);

		$options = new PureSMTP_Options();
		$logger  = new PureSMTP_Logger();
		$queue   = new PureSMTP_Queue( $options, $logger );

		// Mailer + TestMail register hooks in their constructors.
		new PureSMTP_Mailer( $options, $logger );
		new PureSMTP_TestMail( $options );

		if ( is_admin() ) {
			new PureSMTP_Admin( $options, $logger, $queue );
		}

		add_action( 'puresmtp_process_queue', [ $queue, 'process_queue' ] );
	}
);
