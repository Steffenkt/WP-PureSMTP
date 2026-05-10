<?php
/**
 * Queue – rate limiting + SMTP-failure retry queue.
 *
 * Uses `pre_wp_mail` filter (WP 5.7+) to intercept outgoing mail.
 * Processes the queue via WP-Cron.
 *
 * @package WP_PureSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PureSMTP_Queue {

	private PureSMTP_Options $options;
	private PureSMTP_Logger  $logger;

	/** @var bool Set to true while the queue processor is running to avoid loops. */
	private bool $processing_queue = false;

	public function __construct( PureSMTP_Options $options, PureSMTP_Logger $logger ) {
		$this->options = $options;
		$this->logger  = $logger;

		// Intercept wp_mail() before it runs (WP 5.7+).
		add_filter( 'pre_wp_mail', [ $this, 'maybe_intercept' ], 10, 2 );

		// Capture failed emails for retry queue.
		add_action( 'wp_mail_failed', [ $this, 'handle_smtp_failure' ], 20 );
	}

	// -------------------------------------------------------------------------
	// Intercept hook
	// -------------------------------------------------------------------------

	/**
	 * Decide whether to let wp_mail() proceed or queue the message.
	 *
	 * Returning non-null short-circuits wp_mail() and returns that value.
	 *
	 * @param mixed                $return Existing pre_wp_mail return value.
	 * @param array<string,mixed>  $atts   wp_mail() args: to, subject, message, headers, attachments.
	 * @return mixed null = proceed normally; false = silently blocked.
	 */
	public function maybe_intercept( $return, array $atts ) {
		// Never block recursive calls from our own queue processor.
		if ( $this->processing_queue ) {
			return $return;
		}

		// Already intercepted upstream.
		if ( null !== $return ) {
			return $return;
		}

		// Kill-switch: stop all outgoing mail.
		if ( $this->options->get( 'stop_sending' ) ) {
			$this->enqueue( $atts, 'stopped' );
			return false;
		}

		// Rate limiting.
		if ( $this->options->get( 'rate_limit_enabled' ) && $this->is_rate_limited() ) {
			$this->enqueue( $atts, 'rate_limit' );
			return false;
		}

		// Mail will be sent now: count it against the rate limit so subsequent
		// sends within the same interval are correctly throttled. Counting at
		// dispatch time (rather than only on success) is what protects the
		// SMTP provider from hitting their own per-account limit.
		$this->increment_send_count();

		return null;
	}

	/**
	 * When SMTP fails and the retry queue is enabled, save the email for later.
	 *
	 * @param \WP_Error $error The WP_Error from wp_mail_failed.
	 */
	public function handle_smtp_failure( \WP_Error $error ): void {
		if ( $this->processing_queue ) {
			return;
		}

		if ( ! $this->options->get( 'retry_queue_enabled', '1' ) ) {
			return;
		}

		// Don't queue if the logger already recorded this send as successful
		// (can happen when PHPMailer fires wp_mail_failed as a false positive).
		if ( $this->logger->last_send_succeeded() ) {
			return;
		}

		$mail_data = $error->get_error_data();
		if ( ! is_array( $mail_data ) || empty( $mail_data['to'] ) ) {
			return;
		}

		$this->enqueue( $mail_data, 'smtp_failure' );
	}

	// -------------------------------------------------------------------------
	// Rate limit helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether the current send count has exceeded the configured limit.
	 *
	 * @return bool True if limit is reached.
	 */
	public function is_rate_limited(): bool {
		$max = (int) $this->options->get( 'rate_limit_count', '100' );
		return $this->get_send_count() >= $max;
	}

	/**
	 * Return how many emails have been sent in the current interval.
	 *
	 * @return int
	 */
	public function get_send_count(): int {
		$transient_key = $this->rate_limit_transient_key();
		$count         = get_transient( $transient_key );
		return false !== $count ? (int) $count : 0;
	}

	/**
	 * Increment the send counter (called after each successful send from the queue).
	 */
	public function increment_send_count(): void {
		$transient_key = $this->rate_limit_transient_key();
		$count         = $this->get_send_count();

		set_transient( $transient_key, $count + 1, $this->interval_seconds() );
	}

	/**
	 * Unique transient key for the current time interval.
	 *
	 * @return string
	 */
	private function rate_limit_transient_key(): string {
		$interval = $this->options->get( 'rate_limit_interval', 'hour' );
		$map      = [
			'minute' => gmdate( 'YmdHi' ),
			'hour'   => gmdate( 'YmdH' ),
			'day'    => gmdate( 'Ymd' ),
			'week'   => gmdate( 'YW' ),
			'month'  => gmdate( 'Ym' ),
		];
		$bucket = $map[ $interval ] ?? gmdate( 'YmdH' );
		return 'puresmtp_ratelimit_' . $bucket;
	}

	/**
	 * Convert the configured rate-limit interval to seconds.
	 *
	 * @return int
	 */
	private function interval_seconds(): int {
		$interval = $this->options->get( 'rate_limit_interval', 'hour' );
		$map      = [
			'minute' => MINUTE_IN_SECONDS,
			'hour'   => HOUR_IN_SECONDS,
			'day'    => DAY_IN_SECONDS,
			'week'   => WEEK_IN_SECONDS,
			'month'  => 30 * DAY_IN_SECONDS,
		];
		return $map[ $interval ] ?? HOUR_IN_SECONDS;
	}

	// -------------------------------------------------------------------------
	// Queue CRUD
	// -------------------------------------------------------------------------

	/**
	 * Save an email to the queue table.
	 *
	 * @param array<string,mixed> $mail_data wp_mail() args.
	 * @param string              $reason    'stopped' | 'rate_limit' | 'smtp_failure'.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function enqueue( array $mail_data, string $reason ): int {
		global $wpdb;

		$to = $mail_data['to'] ?? '';
		if ( is_array( $to ) ) {
			$to = implode( ', ', $to );
		}

		$headers = $mail_data['headers'] ?? [];
		if ( is_array( $headers ) ) {
			$headers = implode( "\r\n", $headers );
		}

		$attachments = $mail_data['attachments'] ?? [];

		$retry_seconds = $this->retry_interval_seconds();
		$max_attempts  = (int) $this->options->get( 'retry_max_attempts', '5' );
		$now           = current_time( 'mysql' );
		// Use site timezone (matches current_time('mysql') used by the cron comparison).
		$next_retry    = wp_date( 'Y-m-d H:i:s', time() + $retry_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'puresmtp_queue',
			[
				'date_added'    => $now,
				'next_retry'    => $next_retry,
				'attempt_count' => 0,
				'max_attempts'  => $max_attempts,
				'recipient'     => (string) $to,
				'subject'       => (string) ( $mail_data['subject'] ?? '' ),
				'headers'       => (string) $headers,
				'message'       => (string) ( $mail_data['message'] ?? '' ),
				'attachments'   => wp_json_encode( $attachments ),
				'reason'        => sanitize_key( $reason ),
				'status'        => 'pending',
			],
			[ '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retry a single queued item. On success removes it; on failure increments count.
	 *
	 * @param int $queue_id Queue row ID.
	 * @return bool True if the send succeeded.
	 */
	public function retry( int $queue_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}puresmtp_queue WHERE id = %d AND status = 'pending'",
				$queue_id
			),
			ARRAY_A
		);

		if ( ! $item ) {
			return false;
		}

		$attachments = json_decode( $item['attachments'] ?? '[]', true );
		if ( ! is_array( $attachments ) ) {
			$attachments = [];
		}

		// Only pass attachments that still exist on disk.
		$attachments = array_filter( $attachments, 'file_exists' );

		$this->processing_queue = true;

		$result = wp_mail(
			$item['recipient'],
			$item['subject'],
			$item['message'],
			$item['headers'],
			array_values( $attachments )
		);

		$this->processing_queue = false;

		if ( $result ) {
			$this->increment_send_count();

			// Remove successfully sent item.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete(
				$wpdb->prefix . 'puresmtp_queue',
				[ 'id' => $queue_id ],
				[ '%d' ]
			);

			return true;
		}

		// Send failed: increment attempt count.
		$new_attempts = (int) $item['attempt_count'] + 1;
		$max_attempts = (int) $item['max_attempts'];
		$retry_secs   = $this->retry_interval_seconds();

		$new_status = ( $new_attempts >= $max_attempts ) ? 'failed' : 'pending';
		// Use site timezone (matches current_time('mysql') used by the cron comparison).
		$next_retry = wp_date( 'Y-m-d H:i:s', time() + $retry_secs );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->prefix . 'puresmtp_queue',
			[
				'attempt_count' => $new_attempts,
				'next_retry'    => $next_retry,
				'status'        => $new_status,
			],
			[ 'id' => $queue_id ],
			[ '%d', '%s', '%s' ],
			[ '%d' ]
		);

		// Notify admin if all retries exhausted.
		if ( 'failed' === $new_status && $this->options->get( 'retry_notify_admin', '1' ) ) {
			$this->notify_admin_failure( $item );
		}

		return false;
	}

	/**
	 * WP-Cron callback: processes all due pending queue items.
	 */
	public function process_queue(): void {
		global $wpdb;

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}puresmtp_queue
				 WHERE status = 'pending' AND next_retry <= %s
				 ORDER BY date_added ASC
				 LIMIT 50",
				$now
			),
			ARRAY_A
		) ?: [];

		foreach ( $items as $row ) {
			$this->retry( (int) $row['id'] );

			// Honour rate limit even during queue processing.
			if ( $this->options->get( 'rate_limit_enabled' ) && $this->is_rate_limited() ) {
				break;
			}
		}
	}

	// -------------------------------------------------------------------------
	// Queue query API
	// -------------------------------------------------------------------------

	/**
	 * Return queue items with optional filters.
	 *
	 * @param array<string,mixed> $filters Keys: status, per_page, page.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_items( array $filters = [] ): array {
		global $wpdb;

		$conditions = [];
		$values     = [];

		if ( ! empty( $filters['status'] ) ) {
			$conditions[] = 'status = %s';
			$values[]     = sanitize_text_field( $filters['status'] );
		}

		$where = empty( $conditions ) ? '1=1' : implode( ' AND ', $conditions );
		$order = 'ORDER BY date_added DESC';
		$limit = '';

		if ( ! empty( $filters['per_page'] ) && ! empty( $filters['page'] ) ) {
			$offset = ( absint( $filters['page'] ) - 1 ) * absint( $filters['per_page'] );
			$limit  = $wpdb->prepare( 'LIMIT %d OFFSET %d', absint( $filters['per_page'] ), $offset );
		}

		$table = $wpdb->prefix . 'puresmtp_queue';

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} {$order} {$limit}", ...$values ),
				ARRAY_A
			) ?: [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} {$order} {$limit}", ARRAY_A ) ?: [];
	}

	/**
	 * Get a single queue item by ID.
	 *
	 * @param int $id Queue row ID.
	 * @return array<string,mixed>|null
	 */
	public function get_item( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}puresmtp_queue WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Update an editable queue item.
	 *
	 * @param int                 $id   Queue row ID.
	 * @param array<string,mixed> $data Updated fields.
	 * @return bool
	 */
	public function update_item( int $id, array $data ): bool {
		global $wpdb;

		$allowed = [ 'recipient', 'subject', 'message' ];
		$update  = [];
		$formats = [];

		foreach ( $allowed as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$update[ $field ] = sanitize_textarea_field( $data[ $field ] );
				$formats[]        = '%s';
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->update(
			$wpdb->prefix . 'puresmtp_queue',
			$update,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);
	}

	/**
	 * Delete a queue item.
	 *
	 * @param int $id Queue row ID.
	 * @return bool
	 */
	public function delete_item( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->delete(
			$wpdb->prefix . 'puresmtp_queue',
			[ 'id' => $id ],
			[ '%d' ]
		);
	}

	/**
	 * Delete multiple queue items.
	 *
	 * @param int[] $ids Row IDs.
	 * @return bool
	 */
	public function delete_items( array $ids ): bool {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}puresmtp_queue WHERE id IN ({$placeholders})",
				...$ids
			)
		);
	}

	/**
	 * Remove all queue items.
	 *
	 * @return bool
	 */
	public function clear_queue(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}puresmtp_queue" );
	}

	/**
	 * Drop both custom tables (used on uninstall).
	 */
	public static function drop_tables(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}puresmtp_log" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}puresmtp_queue" );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert the retry_interval option to seconds.
	 *
	 * @return int
	 */
	private function retry_interval_seconds(): int {
		$interval = $this->options->get( 'retry_interval', '15min' );
		$map      = [
			'5min'  => 5 * MINUTE_IN_SECONDS,
			'15min' => 15 * MINUTE_IN_SECONDS,
			'30min' => 30 * MINUTE_IN_SECONDS,
			'1hour' => HOUR_IN_SECONDS,
		];
		return $map[ $interval ] ?? ( 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Send the admin an email when a queue item exhausts all retries.
	 *
	 * @param array<string,mixed> $item Queue row.
	 */
	private function notify_admin_failure( array $item ): void {
		$admin_email = get_option( 'admin_email' );
		$blog_name   = get_option( 'blogname' );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] WP PureSMTP: Email permanently failed', 'wp-puresmtp' ), $blog_name );

		$message = sprintf(
			/* translators: 1: recipient, 2: subject, 3: attempts */
			__(
				"An email could not be delivered after %3\$s attempts and has been marked as permanently failed.\n\nTo: %1\$s\nSubject: %2\$s\n\nPlease check your SMTP settings in WP PureSMTP.",
				'wp-puresmtp'
			),
			$item['recipient'],
			$item['subject'],
			$item['attempt_count']
		);

		// Use WordPress's own wp_mail to notify (bypasses our intercept since
		// we are inside processing_queue = false here — intentional, so the
		// notification itself goes through).
		wp_mail( $admin_email, $subject, $message );
	}
}
