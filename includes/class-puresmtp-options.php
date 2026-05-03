<?php
/**
 * Options helper – wraps get_option / update_option with a consistent prefix
 * and handles SMTP password encryption.
 *
 * @package WP_PureSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PureSMTP_Options {

	/** @var string Option key prefix. */
	private string $prefix = 'puresmtp_';

	// -------------------------------------------------------------------------
	// CRUD helpers
	// -------------------------------------------------------------------------

	/**
	 * Get a plugin option.
	 *
	 * @param string $key     Option key (without prefix).
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public function get( string $key, $default = '' ) {
		return get_option( $this->prefix . $key, $default );
	}

	/**
	 * Update a plugin option.
	 *
	 * @param string $key   Option key (without prefix).
	 * @param mixed  $value New value.
	 * @return bool
	 */
	public function set( string $key, $value ): bool {
		return update_option( $this->prefix . $key, $value );
	}

	/**
	 * Delete a single plugin option.
	 *
	 * @param string $key Option key (without prefix).
	 * @return bool
	 */
	public function delete( string $key ): bool {
		return delete_option( $this->prefix . $key );
	}

	/**
	 * Delete all plugin options from wp_options.
	 */
	public function delete_all(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $this->prefix ) . '%'
			)
		);
	}

	// -------------------------------------------------------------------------
	// Password encryption / decryption (AES-256-CBC, key derived from AUTH_KEY)
	// -------------------------------------------------------------------------

	/**
	 * Encrypt a plain-text password for safe storage in wp_options.
	 *
	 * @param string $password Plain text.
	 * @return string Base-64 encoded ciphertext (iv + ciphertext).
	 */
	public function encrypt_password( string $password ): string {
		if ( '' === $password ) {
			return '';
		}

		$key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
		$iv  = random_bytes( 16 );

		$encrypted = openssl_encrypt( $password, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return '';
		}

		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a previously encrypted password.
	 *
	 * @param string $stored Encrypted value from wp_options.
	 * @return string Plain text (empty string on failure).
	 */
	public function decrypt_password( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		$raw = base64_decode( $stored, true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$key       = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
		$iv        = substr( $raw, 0, 16 );
		$cipher    = substr( $raw, 16 );
		$decrypted = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	// -------------------------------------------------------------------------
	// Default values
	// -------------------------------------------------------------------------

	/**
	 * Returns an associative array of default values for all plugin options.
	 *
	 * @return array<string, string>
	 */
	public function get_defaults(): array {
		return [
			'from_email'          => get_option( 'admin_email', '' ),
			'force_from_email'    => '0',
			'from_name'           => get_option( 'blogname', '' ),
			'force_from_name'     => '0',
			'return_path'         => '0',
			'host'                => '',
			'encryption'          => 'tls',
			'port'                => '587',
			'auto_tls'            => '1',
			'auth'                => '1',
			'username'            => '',
			'password'            => '',
			'debug'               => '0',
			'log_retention'       => '30',
			'uninstall'           => '0',
			'stop_sending'        => '0',
			'rate_limit_enabled'  => '0',
			'rate_limit_count'    => '100',
			'rate_limit_interval' => 'hour',
			'retry_queue_enabled' => '1',
			'retry_interval'      => '15min',
			'retry_max_attempts'  => '5',
			'retry_notify_admin'  => '1',
		];
	}
}
