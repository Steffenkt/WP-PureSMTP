<?php
/**
 * Logger – records every wp_mail() attempt (success & failure) in the
 * custom {prefix}_puresmtp_log DB table.
 *
 * Hooks used:
 *   wp_mail          (filter, priority 10) – capture mail data before send
 *   wp_mail_failed   (action)              – record SMTP/PHPMailer failure
 *   log_success()    is called directly by PureSMTP_Mailer via the SMTPDebug callback
 *
 * @package WP_PureSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PureSMTP_Logger {

	/** @var array<string,mixed>|null Mail args captured from wp_mail filter. */
	private ?array $current_mail = null;

	/** @var string SMTP debug trace accumulated during the current send. */
	private string $debug_trace = '';

	/** @var bool True after log_success() was called for the current send. */
	private bool $last_send_succeeded = false;

	public function __construct() {
		add_filter( 'wp_mail', [ $this, 'capture_mail_data' ], 10 );
		add_action( 'wp_mail_failed', [ $this, 'log_failure' ], 10 );
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Capture mail args before the email is sent.
	 *
	 * @param array<string,mixed> $args wp_mail() arguments.
	 * @return array<string,mixed>
	 */
	public function capture_mail_data( array $args ): array {
		$this->current_mail         = $args;
		$this->debug_trace          = '';
		$this->last_send_succeeded  = false;
		return $args;
	}

	/**
	 * Append a line to the current SMTP debug trace.
	 * Called from PureSMTP_Mailer's Debugoutput callback.
	 *
	 * @param string $line One line of SMTP conversation.
	 */
	public function append_debug_trace( string $line ): void {
		$this->debug_trace .= $line . "\n";
	}

	/**
	 * Called by PureSMTP_Mailer when "Message sent" is detected in SMTP trace.
	 */
	public function log_success(): void {
		if ( null === $this->current_mail ) {
			return;
		}

		$this->insert_entry( [
			'status'      => 'sent',
			'debug_trace' => $this->debug_trace ?: null,
		] );

		$this->last_send_succeeded = true;
		$this->current_mail        = null;
		$this->debug_trace         = '';
	}

	/**
	 * Whether the most recent send was already recorded as successful.
	 * Used by PureSMTP_Queue to avoid double-queuing.
	 *
	 * @return bool
	 */
	public function last_send_succeeded(): bool {
		return $this->last_send_succeeded;
	}

	/**
	 * Called on wp_mail_failed.
	 *
	 * @param \WP_Error $error WP_Error containing SMTP failure details.
	 */
	public function log_failure( \WP_Error $error ): void {
		// Merge mail data from WP_Error (wp_mail stores it there).
		$error_data = $error->get_error_data();
		if ( is_array( $error_data ) && null === $this->current_mail ) {
			$this->current_mail = $error_data;
		}

		if ( null === $this->current_mail ) {
			return;
		}

		$this->insert_entry( [
			'status'        => 'failed',
			'error_code'    => $error->get_error_code(),
			'error_message' => $error->get_error_message(),
			'debug_trace'   => $this->debug_trace ?: null,
		] );

		$this->current_mail = null;
		$this->debug_trace  = '';
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Insert a log entry. Merges defaults with the provided $extra array.
	 *
	 * @param array<string,mixed> $extra Fields to merge / override.
	 */
	private function insert_entry( array $extra ): void {
		global $wpdb;

		$mail = $this->current_mail;

		$to = $mail['to'] ?? '';
		if ( is_array( $to ) ) {
			$to = implode( ', ', $to );
		}

		$row = array_merge(
			[
				'date'          => current_time( 'mysql' ),
				'recipient'     => (string) $to,
				'subject'       => (string) ( $mail['subject'] ?? '' ),
				'status'        => 'sent',
				'error_code'    => null,
				'error_message' => null,
				'debug_trace'   => null,
				'source_plugin' => $this->detect_source_plugin(),
			],
			$extra
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'puresmtp_log',
			$row,
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Walk the call stack to find which plugin triggered wp_mail().
	 *
	 * @return string Plugin folder slug, or empty string if unknown.
	 */
	private function detect_source_plugin(): string {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$file = wp_normalize_path( $frame['file'] );

			if ( false === strpos( $file, '/plugins/' ) ) {
				continue;
			}

			$parts = explode( '/plugins/', $file );
			if ( empty( $parts[1] ) ) {
				continue;
			}

			$slug_parts = explode( '/', $parts[1] );
			$slug       = $slug_parts[0];

			if ( 'wp-puresmtp' !== $slug ) {
				return $slug;
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Public query API
	// -------------------------------------------------------------------------

	/**
	 * Return log entries with optional filters.
	 *
	 * @param array<string,mixed> $filters Keys: status, days, search, per_page, page.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_entries( array $filters = [] ): array {
		global $wpdb;

		[ $where, $values ] = $this->build_where( $filters );

		$order = 'ORDER BY date DESC';
		$limit = '';

		if ( ! empty( $filters['per_page'] ) && ! empty( $filters['page'] ) ) {
			$offset = ( absint( $filters['page'] ) - 1 ) * absint( $filters['per_page'] );
			$limit  = $wpdb->prepare( 'LIMIT %d OFFSET %d', absint( $filters['per_page'] ), $offset );
		}

		$table = $wpdb->prefix . 'puresmtp_log';

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} {$order} {$limit}", ...$values ),
				ARRAY_A
			) ?: [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} {$order} {$limit}", ARRAY_A ) ?: [];
	}

	/**
	 * Count log entries with optional filters.
	 *
	 * @param array<string,mixed> $filters Same keys as get_entries().
	 * @return int
	 */
	public function count_entries( array $filters = [] ): int {
		global $wpdb;

		[ $where, $values ] = $this->build_where( $filters );

		$table = $wpdb->prefix . 'puresmtp_log';

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$values )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Get a single log entry by ID.
	 *
	 * @param int $id Entry ID.
	 * @return array<string,mixed>|null
	 */
	public function get_entry( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}puresmtp_log WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Delete a single log entry.
	 *
	 * @param int $id Entry ID.
	 * @return bool
	 */
	public function delete_entry( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->delete(
			$wpdb->prefix . 'puresmtp_log',
			[ 'id' => $id ],
			[ '%d' ]
		);
	}

	/**
	 * Delete multiple log entries.
	 *
	 * @param int[] $ids Entry IDs.
	 * @return bool
	 */
	public function delete_entries( array $ids ): bool {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}puresmtp_log WHERE id IN ({$placeholders})",
				...$ids
			)
		);
	}

	/**
	 * Remove all log entries.
	 *
	 * @return bool
	 */
	public function clear_all(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}puresmtp_log" );
	}

	/**
	 * Delete log entries older than $days days.
	 *
	 * @param int $days Retention period in days.
	 */
	public function cleanup( int $days ): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( absint( $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->prefix}puresmtp_log WHERE date < %s", $cutoff )
		);
	}

	// -------------------------------------------------------------------------
	// Private – WHERE clause builder
	// -------------------------------------------------------------------------

	/**
	 * Build a reusable WHERE clause and value array from $filters.
	 *
	 * @param array<string,mixed> $filters Accepted keys: status, days, search.
	 * @return array{0: string, 1: array<int,mixed>}
	 */
	private function build_where( array $filters ): array {
		global $wpdb;

		$conditions = [];
		$values     = [];

		if ( ! empty( $filters['status'] ) ) {
			$conditions[] = 'status = %s';
			$values[]     = sanitize_text_field( $filters['status'] );
		}

		if ( ! empty( $filters['days'] ) ) {
			$cutoff       = gmdate( 'Y-m-d H:i:s', time() - ( absint( $filters['days'] ) * DAY_IN_SECONDS ) );
			$conditions[] = 'date >= %s';
			$values[]     = $cutoff;
		}

		if ( ! empty( $filters['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( sanitize_text_field( $filters['search'] ) ) . '%';
			$conditions[] = '(recipient LIKE %s OR subject LIKE %s OR status LIKE %s)';
			$values[]     = $like;
			$values[]     = $like;
			$values[]     = $like;
		}

		$where = empty( $conditions ) ? '1=1' : implode( ' AND ', $conditions );

		return [ $where, $values ];
	}
}
