<?php
/**
 * Mailer – hooks into phpmailer_init to configure SMTP via PHPMailer.
 * Also triggers the logger's success callback once a message is sent.
 *
 * @package WP_PureSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PureSMTP_Mailer {

	private PureSMTP_Options $options;
	private PureSMTP_Logger  $logger;

	public function __construct( PureSMTP_Options $options, PureSMTP_Logger $logger ) {
		$this->options = $options;
		$this->logger  = $logger;

		add_action( 'phpmailer_init', [ $this, 'configure_mailer' ] );

		if ( $options->get( 'force_from_email' ) ) {
			add_filter( 'wp_mail_from', [ $this, 'force_from_email' ] );
		}

		if ( $options->get( 'force_from_name' ) ) {
			add_filter( 'wp_mail_from_name', [ $this, 'force_from_name' ] );
		}
	}

	/**
	 * Configure PHPMailer to use SMTP with the stored settings.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance passed by reference.
	 */
	public function configure_mailer( $phpmailer ): void {
		$phpmailer->isSMTP();
		$phpmailer->Host     = $this->options->get( 'host' );
		$phpmailer->SMTPAuth = (bool) $this->options->get( 'auth', '1' );
		$phpmailer->Username = $this->options->get( 'username' );
		$phpmailer->Password = $this->options->decrypt_password( $this->options->get( 'password' ) );
		$phpmailer->Port     = (int) $this->options->get( 'port', '587' );

		// Encryption.
		$encryption = $this->options->get( 'encryption', 'tls' );
		if ( 'ssl' === $encryption ) {
			$phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
		} elseif ( 'tls' === $encryption ) {
			$phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		}

		// Auto-TLS when no explicit encryption is set.
		if ( '' === $phpmailer->SMTPSecure && $this->options->get( 'auto_tls', '1' ) ) {
			$phpmailer->SMTPAutoTLS = true;
		}

		// From / FromName (only override if configured).
		$from_email = $this->options->get( 'from_email' );
		if ( ! empty( $from_email ) ) {
			$phpmailer->From = $from_email;
		}

		$from_name = $this->options->get( 'from_name' );
		if ( ! empty( $from_name ) ) {
			$phpmailer->FromName = $from_name;
		}

		// Return-Path.
		if ( $this->options->get( 'return_path' ) ) {
			$phpmailer->Sender = $phpmailer->From;
		}

		// Debug trace capture (only when debug mode is enabled).
		$logger        = $this->logger;
		$persist_trace = (bool) $this->options->get( 'debug' );

		if ( $persist_trace ) {
			$phpmailer->SMTPDebug   = 2;
			$phpmailer->Debugoutput = static function ( string $str ) use ( $logger ): void {
				$logger->append_debug_trace( $str );
			};
		}

		// Success detection via PHPMailer's action_function callback.
		// This is called by doCallback() once per recipient after each send attempt,
		// so the guard inside log_success() prevents duplicate log entries.
		$phpmailer->action_function = static function ( bool $isSent ) use ( $logger ): void {
			if ( $isSent ) {
				$logger->log_success();
			}
		};
	}

	/**
	 * Return the configured From address (forced).
	 *
	 * @param string $email Default from email.
	 * @return string
	 */
	public function force_from_email( string $email ): string {
		$forced = $this->options->get( 'from_email' );
		return ! empty( $forced ) ? $forced : $email;
	}

	/**
	 * Return the configured From name (forced).
	 *
	 * @param string $name Default from name.
	 * @return string
	 */
	public function force_from_name( string $name ): string {
		$forced = $this->options->get( 'from_name' );
		return ! empty( $forced ) ? $forced : $name;
	}
}
