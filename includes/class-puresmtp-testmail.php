<?php
/**
 * Test Mail – AJAX handler for sending a test email from the admin UI.
 *
 * AJAX action: wp_ajax_puresmtp_test_mail
 *
 * @package WP_PureSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PureSMTP_TestMail {

	private PureSMTP_Options $options;

	public function __construct( PureSMTP_Options $options ) {
		$this->options = $options;
		add_action( 'wp_ajax_puresmtp_test_mail', [ $this, 'handle' ] );
	}

	/**
	 * Process the AJAX test-mail request.
	 * Expects POST fields: nonce, to, subject, message.
	 * Returns JSON: { success: bool, message: string }.
	 */
	public function handle(): void {
		check_ajax_referer( 'puresmtp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'wp-puresmtp' ) );
		}

		$to      = isset( $_POST['to'] )      ? sanitize_email( wp_unslash( $_POST['to'] ) )                   : '';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) )          : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) )      : '';

		if ( ! is_email( $to ) ) {
			wp_send_json_error( __( 'Please enter a valid recipient email address.', 'wp-puresmtp' ) );
		}

		if ( '' === $subject ) {
			$subject = __( 'WP PureSMTP Test Email', 'wp-puresmtp' );
		}

		if ( '' === $message ) {
			$message = __( 'This is a test email sent from WP PureSMTP to verify your SMTP configuration.', 'wp-puresmtp' );
		}

		// Capture any PHPMailer error for the response.
		$error_message = '';
		$error_handler = static function ( \WP_Error $error ) use ( &$error_message ): void {
			$error_message = $error->get_error_message();
			$data          = $error->get_error_data();
			if ( isset( $data['phpmailer_exception_code'] ) ) {
				$error_message .= ' (code: ' . (int) $data['phpmailer_exception_code'] . ')';
			}
		};

		add_action( 'wp_mail_failed', $error_handler );
		$result = wp_mail( $to, $subject, $message );
		remove_action( 'wp_mail_failed', $error_handler );

		if ( $result ) {
			wp_send_json_success(
				sprintf(
					/* translators: %s: recipient email address */
					__( 'Test email successfully sent to %s.', 'wp-puresmtp' ),
					$to
				)
			);
		} else {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to send test email. Error: %s', 'wp-puresmtp' ),
					$error_message ?: __( 'Unknown error.', 'wp-puresmtp' )
				)
			);
		}
	}
}
