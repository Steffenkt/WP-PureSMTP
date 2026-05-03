<?php
/**
 * Admin – settings page with 5 tabs:
 *   General | Email Log | Queue | Test Email | Misc
 *
 * @package WP_PureSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PureSMTP_Admin {

	private PureSMTP_Options $options;
	private PureSMTP_Logger  $logger;
	private PureSMTP_Queue   $queue;

	/** Valid tabs. */
	private const TABS = [ 'general', 'log', 'queue', 'testmail', 'misc' ];

	public function __construct(
		PureSMTP_Options $options,
		PureSMTP_Logger  $logger,
		PureSMTP_Queue   $queue
	) {
		$this->options = $options;
		$this->logger  = $logger;
		$this->queue   = $queue;

		add_action( 'admin_menu',            [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_notices',         [ $this, 'stop_sending_notice' ] );
		add_action( 'admin_post_puresmtp_save_settings', [ $this, 'handle_save' ] );
		add_action( 'admin_post_puresmtp_log_action',    [ $this, 'handle_log_action' ] );
		add_action( 'admin_post_puresmtp_queue_action',  [ $this, 'handle_queue_action' ] );
		add_action( 'wp_ajax_puresmtp_log_cleanup',      [ $this, 'ajax_log_cleanup' ] );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public function add_menu(): void {
		add_menu_page(
			__( 'WP PureSMTP', 'wp-puresmtp' ),
			__( 'WP PureSMTP', 'wp-puresmtp' ),
			'manage_options',
			'wp-puresmtp',
			[ $this, 'render_page' ],
			'dashicons-email-alt',
			81
		);
	}

	public function enqueue_scripts( string $hook ): void {
		if ( 'toplevel_page_wp-puresmtp' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'puresmtp-admin',
			PURESMTP_PLUGIN_URL . 'assets/css/admin.css',
			[],
			PURESMTP_VERSION
		);

		wp_enqueue_script(
			'puresmtp-admin',
			PURESMTP_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			PURESMTP_VERSION,
			true
		);

		wp_localize_script(
			'puresmtp-admin',
			'pureSMTP',
			[
				'ajaxurl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'puresmtp_nonce' ),
				'sendCount'        => $this->queue->get_send_count(),
				'rateMax'          => $this->options->get( 'rate_limit_count', '100' ),
				'rateInterval'     => $this->options->get( 'rate_limit_interval', 'hour' ),
				'confirmDeleteLog' => __( 'Delete this log entry?', 'wp-puresmtp' ),
				'confirmClearLog'  => __( 'Delete ALL log entries? This cannot be undone.', 'wp-puresmtp' ),
				'confirmDeleteQ'   => __( 'Delete this queue item?', 'wp-puresmtp' ),
				'confirmClearQ'    => __( 'Clear the entire queue? This cannot be undone.', 'wp-puresmtp' ),
				'sending'          => __( 'Sending…', 'wp-puresmtp' ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// "Stop Sending" admin notice
	// -------------------------------------------------------------------------

	public function stop_sending_notice(): void {
		if ( ! $this->options->get( 'stop_sending' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'WP PureSMTP:', 'wp-puresmtp' ); ?></strong>
				<?php esc_html_e( 'All outgoing emails are currently STOPPED. No mail will be sent until you disable this in WP PureSMTP → Queue.', 'wp-puresmtp' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-puresmtp&tab=queue' ) ); ?>">
					<?php esc_html_e( 'Go to Queue settings', 'wp-puresmtp' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Main page renderer
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-puresmtp' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $active_tab, self::TABS, true ) ) {
			$active_tab = 'general';
		}
		$updated = isset( $_GET['updated'] ) && '1' === sanitize_key( $_GET['updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap puresmtp-wrap">
			<h1 class="puresmtp-page-header">
				<span class="puresmtp-page-header-icon"><span class="dashicons dashicons-email-alt"></span></span>
				<span class="puresmtp-page-header-title"><?php echo esc_html( get_admin_page_title() ); ?></span>
				<span class="puresmtp-page-header-version">v<?php echo esc_html( PURESMTP_VERSION ); ?></span>
			</h1>

			<div class="puresmtp-layout">
				<nav class="puresmtp-sidebar" aria-label="<?php esc_attr_e( 'Plugin navigation', 'wp-puresmtp' ); ?>">
					<?php $this->render_tabs( $active_tab ); ?>
				</nav>

				<div class="puresmtp-tab-content">
					<?php if ( $updated ) : ?>
						<div class="puresmtp-inline-notice puresmtp-inline-notice--ok">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Settings saved.', 'wp-puresmtp' ); ?>
						</div>
					<?php endif; ?>
					<?php
					switch ( $active_tab ) {
						case 'log':
							$this->render_tab_log();
							break;
						case 'queue':
							$this->render_tab_queue();
							break;
						case 'testmail':
							$this->render_tab_testmail();
							break;
						case 'misc':
							$this->render_tab_misc();
							break;
						default:
							$this->render_tab_general();
							break;
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab navigation
	// -------------------------------------------------------------------------

	private function render_tabs( string $active ): void {
		$tabs = [
			'general' => __( 'General', 'wp-puresmtp' ),
			'log'     => __( 'Email Log', 'wp-puresmtp' ),
			'queue'   => __( 'Queue', 'wp-puresmtp' ),
			'testmail'=> __( 'Test Email', 'wp-puresmtp' ),
			'misc'    => __( 'Misc', 'wp-puresmtp' ),
		];

		$icons = [
			'general'  => 'dashicons-admin-settings',
			'log'      => 'dashicons-list-view',
			'queue'    => 'dashicons-email-alt2',
			'testmail' => 'dashicons-email',
			'misc'     => 'dashicons-admin-tools',
		];

		echo '<div class="puresmtp-sidebar-section">' . esc_html__( 'Settings', 'wp-puresmtp' ) . '</div>';

		foreach ( $tabs as $slug => $label ) {
			$class = ( $slug === $active ) ? 'puresmtp-nav-item puresmtp-nav-item--active' : 'puresmtp-nav-item';
			$icon  = $icons[ $slug ] ?? 'dashicons-admin-generic';
			$url   = admin_url( 'admin.php?page=wp-puresmtp&tab=' . $slug );
			printf(
				'<a href="%s" class="%s"><span class="dashicons %s puresmtp-nav-icon"></span><span class="puresmtp-nav-label">%s</span></a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_attr( $icon ),
				esc_html( $label )
			);
		}
	}

	// =========================================================================
	// TAB: General
	// =========================================================================

	private function render_tab_general(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="puresmtp_save_settings">
			<input type="hidden" name="puresmtp_tab" value="general">
			<?php wp_nonce_field( 'puresmtp_save_general', 'puresmtp_nonce' ); ?>

			<!-- Sender Info -->
			<h2><?php esc_html_e( 'Sender Information', 'wp-puresmtp' ); ?></h2>
			<table class="form-table puresmtp-form-table" role="presentation">
				<tr>
					<th><label for="puresmtp_from_email"><?php esc_html_e( 'From Email', 'wp-puresmtp' ); ?></label></th>
					<td>
						<input type="email" id="puresmtp_from_email" name="puresmtp_from_email"
							value="<?php echo esc_attr( $this->options->get( 'from_email' ) ); ?>"
							class="regular-text">
						<p class="description"><?php esc_html_e( 'The email address used as the sender.', 'wp-puresmtp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Force From Email', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" name="puresmtp_force_from_email" value="1"
								<?php checked( $this->options->get( 'force_from_email' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
						<span class="puresmtp-toggle-label"><?php esc_html_e( 'Override From address from all plugins', 'wp-puresmtp' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><label for="puresmtp_from_name"><?php esc_html_e( 'From Name', 'wp-puresmtp' ); ?></label></th>
					<td>
						<input type="text" id="puresmtp_from_name" name="puresmtp_from_name"
							value="<?php echo esc_attr( $this->options->get( 'from_name' ) ); ?>"
							class="regular-text">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Force From Name', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" name="puresmtp_force_from_name" value="1"
								<?php checked( $this->options->get( 'force_from_name' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
						<span class="puresmtp-toggle-label"><?php esc_html_e( 'Override From name from all plugins', 'wp-puresmtp' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Return Path', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" name="puresmtp_return_path" value="1"
								<?php checked( $this->options->get( 'return_path' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
						<span class="puresmtp-toggle-label"><?php esc_html_e( 'Set return-path to From address (for bounces)', 'wp-puresmtp' ); ?></span>
					</td>
				</tr>
			</table>

			<!-- SMTP Configuration -->
			<h2><?php esc_html_e( 'SMTP Configuration', 'wp-puresmtp' ); ?></h2>
			<table class="form-table puresmtp-form-table" role="presentation">
				<tr>
					<th><label for="puresmtp_host"><?php esc_html_e( 'SMTP Host', 'wp-puresmtp' ); ?></label></th>
					<td>
						<input type="text" id="puresmtp_host" name="puresmtp_host"
							value="<?php echo esc_attr( $this->options->get( 'host' ) ); ?>"
							class="regular-text" placeholder="smtp.example.com">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Encryption', 'wp-puresmtp' ); ?></th>
					<td>
						<?php $enc = $this->options->get( 'encryption', 'tls' ); ?>
						<div class="puresmtp-radio-group">
							<label><input type="radio" name="puresmtp_encryption" value="none" <?php checked( $enc, 'none' ); ?>><?php esc_html_e( 'None', 'wp-puresmtp' ); ?></label>
							<label><input type="radio" name="puresmtp_encryption" value="ssl"  <?php checked( $enc, 'ssl' ); ?>><?php esc_html_e( 'SSL', 'wp-puresmtp' ); ?></label>
							<label><input type="radio" name="puresmtp_encryption" value="tls"  <?php checked( $enc, 'tls' ); ?>><?php esc_html_e( 'TLS', 'wp-puresmtp' ); ?></label>
						</div>
					</td>
				</tr>
				<tr>
					<th><label for="puresmtp_port"><?php esc_html_e( 'SMTP Port', 'wp-puresmtp' ); ?></label></th>
					<td>
						<input type="number" id="puresmtp_port" name="puresmtp_port" min="1" max="65535"
							value="<?php echo esc_attr( $this->options->get( 'port', '587' ) ); ?>"
							class="small-text">
						<p class="description"><?php esc_html_e( 'Auto-filled when you change encryption: None = 25, SSL = 465, TLS = 587.', 'wp-puresmtp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Auto TLS', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" name="puresmtp_auto_tls" value="1"
								<?php checked( $this->options->get( 'auto_tls', '1' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
						<span class="puresmtp-toggle-label"><?php esc_html_e( 'Automatically upgrade to TLS if the server supports it', 'wp-puresmtp' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Authentication', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" id="puresmtp_auth" name="puresmtp_auth" value="1"
								<?php checked( $this->options->get( 'auth', '1' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
						<span class="puresmtp-toggle-label"><?php esc_html_e( 'Enable SMTP authentication', 'wp-puresmtp' ); ?></span>
					</td>
				</tr>
				<tr id="puresmtp-auth-row-username" class="puresmtp-auth-row">
					<th><label for="puresmtp_username"><?php esc_html_e( 'SMTP Username', 'wp-puresmtp' ); ?></label></th>
					<td>
						<input type="text" id="puresmtp_username" name="puresmtp_username"
							value="<?php echo esc_attr( $this->options->get( 'username' ) ); ?>"
							class="regular-text" autocomplete="off">
					</td>
				</tr>
				<tr id="puresmtp-auth-row-password" class="puresmtp-auth-row">
					<th><label for="puresmtp_password"><?php esc_html_e( 'SMTP Password', 'wp-puresmtp' ); ?></label></th>
					<td>
						<?php if ( $this->options->get( 'password' ) ) : ?>
							<div class="puresmtp-password-set">
								<span class="puresmtp-password-dots">••••••••</span>
								<label>
									<input type="checkbox" name="puresmtp_remove_password" value="1">
									<?php esc_html_e( 'Remove password', 'wp-puresmtp' ); ?>
								</label>
							</div>
							<p class="description"><?php esc_html_e( 'A password is stored. Check "Remove password" to clear it, or enter a new one below.', 'wp-puresmtp' ); ?></p>
						<?php endif; ?>
						<input type="password" id="puresmtp_password" name="puresmtp_password"
							value="" class="regular-text" autocomplete="new-password"
							placeholder="<?php esc_attr_e( 'Enter new password', 'wp-puresmtp' ); ?>">
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'wp-puresmtp' ) ); ?>
		</form>
		<?php
	}

	// =========================================================================
	// TAB: Email Log
	// =========================================================================

	private function render_tab_log(): void {
		// Handle sub-action: view detail.
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$entry_id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'view' === $action && $entry_id > 0 ) {
			$this->render_log_detail( $entry_id );
			return;
		}

		// Filters.
		$search  = isset( $_GET['s'] )      ? sanitize_text_field( wp_unslash( $_GET['s'] ) )      : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] )                      : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days    = isset( $_GET['days'] )   ? absint( $_GET['days'] )                               : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page    = isset( $_GET['paged'] )  ? max( 1, absint( $_GET['paged'] ) )                    : 1;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;

		$filters = array_filter( [
			'search'   => $search,
			'status'   => $status,
			'days'     => $days,
			'per_page' => $per_page,
			'page'     => $page,
		] );

		$entries     = $this->logger->get_entries( $filters );
		$total       = $this->logger->count_entries( $filters );
		$total_pages = (int) ceil( $total / $per_page );

		$base_url = admin_url( 'admin.php?page=wp-puresmtp&tab=log' );
		?>
		<div class="puresmtp-log-toolbar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="puresmtp-filter-form">
				<input type="hidden" name="page" value="wp-puresmtp">
				<input type="hidden" name="tab"  value="log">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search recipient, subject…', 'wp-puresmtp' ); ?>"
					class="regular-text">
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'wp-puresmtp' ); ?></option>
					<option value="sent"   <?php selected( $status, 'sent' ); ?>><?php esc_html_e( 'Sent', 'wp-puresmtp' ); ?></option>
					<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'wp-puresmtp' ); ?></option>
				</select>
				<select name="days">
					<option value="0" <?php selected( $days, 0 ); ?>><?php esc_html_e( 'All time', 'wp-puresmtp' ); ?></option>
					<option value="7"  <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'wp-puresmtp' ); ?></option>
					<option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( 'Last 30 days', 'wp-puresmtp' ); ?></option>
					<option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( 'Last 90 days', 'wp-puresmtp' ); ?></option>
				</select>
				<?php submit_button( __( 'Filter', 'wp-puresmtp' ), 'secondary', '', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="puresmtp-clear-form">
				<input type="hidden" name="action" value="puresmtp_log_action">
				<input type="hidden" name="log_action" value="clear_all">
				<?php wp_nonce_field( 'puresmtp_log_action' ); ?>
				<button type="submit" class="button puresmtp-btn-danger js-confirm-clear-log">
					<?php esc_html_e( 'Clear all logs', 'wp-puresmtp' ); ?>
				</button>
			</form>
		</div>

		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'No log entries found.', 'wp-puresmtp' ); ?></p>
		<?php else : ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="puresmtp_log_action">
			<input type="hidden" name="log_action" value="bulk_delete">
			<?php wp_nonce_field( 'puresmtp_log_action' ); ?>

			<div class="puresmtp-bulk-actions">
				<button type="submit" class="button js-confirm-clear-log"><?php esc_html_e( 'Delete selected', 'wp-puresmtp' ); ?></button>
			</div>

			<table class="wp-list-table widefat fixed striped puresmtp-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" class="js-check-all"></td>
						<th><?php esc_html_e( '#', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Date / Time', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'To', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Error', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-puresmtp' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $entries as $entry ) :
					$view_url   = add_query_arg( [ 'action' => 'view', 'entry_id' => $entry['id'] ], $base_url );
					$delete_url = wp_nonce_url(
						add_query_arg( [ 'log_action' => 'delete', 'entry_id' => $entry['id'] ], admin_url( 'admin-post.php?action=puresmtp_log_action' ) ),
						'puresmtp_log_action'
					);
				?>
					<tr>
						<td class="check-column">
							<input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr( $entry['id'] ); ?>">
						</td>
						<td><?php echo esc_html( $entry['id'] ); ?></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry['date'] ) ) ); ?></td>
						<td class="puresmtp-truncate"><?php echo esc_html( $entry['recipient'] ); ?></td>
						<td class="puresmtp-truncate"><?php echo esc_html( $entry['subject'] ); ?></td>
						<td>
							<?php if ( 'sent' === $entry['status'] ) : ?>
								<span class="puresmtp-badge puresmtp-badge-sent">&#10003; <?php esc_html_e( 'Sent', 'wp-puresmtp' ); ?></span>
							<?php else : ?>
								<span class="puresmtp-badge puresmtp-badge-failed">&#10007; <?php esc_html_e( 'Failed', 'wp-puresmtp' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="puresmtp-truncate"><?php echo esc_html( $entry['error_message'] ?? '' ); ?></td>
						<td>
							<a href="<?php echo esc_url( $view_url ); ?>" class="button button-small"><?php esc_html_e( 'View', 'wp-puresmtp' ); ?></a>
							<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small puresmtp-btn-danger js-confirm-delete-log"><?php esc_html_e( 'Delete', 'wp-puresmtp' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>

		<?php $this->render_pagination( $page, $total_pages, $base_url ); ?>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Log detail view
	// -------------------------------------------------------------------------

	private function render_log_detail( int $id ): void {
		$entry = $this->logger->get_entry( $id );

		if ( ! $entry ) {
			echo '<p>' . esc_html__( 'Log entry not found.', 'wp-puresmtp' ) . '</p>';
			return;
		}

		$back_url = admin_url( 'admin.php?page=wp-puresmtp&tab=log' );
		?>
		<a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Back to log', 'wp-puresmtp' ); ?></a>

		<table class="form-table puresmtp-detail-table">
			<tr><th><?php esc_html_e( 'Date / Time', 'wp-puresmtp' ); ?></th><td><?php echo esc_html( $entry['date'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'To', 'wp-puresmtp' ); ?></th><td><?php echo esc_html( $entry['recipient'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Subject', 'wp-puresmtp' ); ?></th><td><?php echo esc_html( $entry['subject'] ); ?></td></tr>
			<tr>
				<th><?php esc_html_e( 'Status', 'wp-puresmtp' ); ?></th>
				<td>
					<?php if ( 'sent' === $entry['status'] ) : ?>
						<span class="puresmtp-badge puresmtp-badge-sent">&#10003; <?php esc_html_e( 'Sent', 'wp-puresmtp' ); ?></span>
					<?php else : ?>
						<span class="puresmtp-badge puresmtp-badge-failed">&#10007; <?php esc_html_e( 'Failed', 'wp-puresmtp' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $entry['error_message'] ) : ?>
			<tr>
				<th><?php esc_html_e( 'Error Message', 'wp-puresmtp' ); ?></th>
				<td>
					<code class="puresmtp-error-code"><?php echo esc_html( $entry['error_message'] ); ?></code>
					<?php $hint = $this->get_error_hint( $entry['error_message'] ); if ( $hint ) : ?>
						<p class="description"><?php echo esc_html( $hint ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php endif; ?>
			<?php if ( $entry['source_plugin'] ) : ?>
			<tr><th><?php esc_html_e( 'Source Plugin', 'wp-puresmtp' ); ?></th><td><?php echo esc_html( $entry['source_plugin'] ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $entry['debug_trace'] ) : ?>
			<tr>
				<th><?php esc_html_e( 'SMTP Debug Trace', 'wp-puresmtp' ); ?></th>
				<td><pre class="puresmtp-debug-trace"><?php echo esc_html( $entry['debug_trace'] ); ?></pre></td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	// =========================================================================
	// TAB: Queue
	// =========================================================================

	private function render_tab_queue(): void {
		$action   = isset( $_GET['action'] )   ? sanitize_key( $_GET['action'] )   : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$queue_id = isset( $_GET['queue_id'] ) ? absint( $_GET['queue_id'] )        : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $action && $queue_id > 0 ) {
			$this->render_queue_edit( $queue_id );
			return;
		}

		$items      = $this->queue->get_items();
		$base_url   = admin_url( 'admin.php?page=wp-puresmtp&tab=queue' );
		?>

		<!-- Queue settings form -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="puresmtp_save_settings">
			<input type="hidden" name="puresmtp_tab" value="queue">
			<?php wp_nonce_field( 'puresmtp_save_queue', 'puresmtp_nonce' ); ?>

			<h2><?php esc_html_e( 'Stop All Emails', 'wp-puresmtp' ); ?></h2>
			<table class="form-table puresmtp-form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Stop sending all emails', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" name="puresmtp_stop_sending" value="1"
								<?php checked( $this->options->get( 'stop_sending' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
						<span class="puresmtp-toggle-label puresmtp-toggle-danger"><?php esc_html_e( 'When ON, no email will be sent from this site.', 'wp-puresmtp' ); ?></span>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Rate Limiting', 'wp-puresmtp' ); ?></h2>
			<table class="form-table puresmtp-form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Enable Rate Limiting', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" name="puresmtp_rate_limit_enabled" value="1"
								<?php checked( $this->options->get( 'rate_limit_enabled' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Max emails', 'wp-puresmtp' ); ?></th>
					<td>
						<input type="number" name="puresmtp_rate_limit_count" min="1" value="<?php echo esc_attr( $this->options->get( 'rate_limit_count', '100' ) ); ?>" class="small-text">
						<?php esc_html_e( 'per', 'wp-puresmtp' ); ?>
						<select name="puresmtp_rate_limit_interval">
							<?php foreach ( [ 'minute' => __( 'Minute', 'wp-puresmtp' ), 'hour' => __( 'Hour', 'wp-puresmtp' ), 'day' => __( 'Day', 'wp-puresmtp' ), 'week' => __( 'Week', 'wp-puresmtp' ), 'month' => __( 'Month', 'wp-puresmtp' ) ] as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $this->options->get( 'rate_limit_interval', 'hour' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php
							printf(
								/* translators: 1: sent count, 2: max count, 3: interval */
								esc_html__( 'Sent this %3$s: %1$d / %2$d', 'wp-puresmtp' ),
								(int) $this->queue->get_send_count(),
								(int) $this->options->get( 'rate_limit_count', '100' ),
								esc_html( $this->options->get( 'rate_limit_interval', 'hour' ) )
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'SMTP Retry Queue', 'wp-puresmtp' ); ?></h2>
			<table class="form-table puresmtp-form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Enable Retry Queue', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" name="puresmtp_retry_queue_enabled" value="1"
								<?php checked( $this->options->get( 'retry_queue_enabled', '1' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Retry interval', 'wp-puresmtp' ); ?></th>
					<td>
						<select name="puresmtp_retry_interval">
							<?php foreach ( [ '5min' => __( '5 minutes', 'wp-puresmtp' ), '15min' => __( '15 minutes', 'wp-puresmtp' ), '30min' => __( '30 minutes', 'wp-puresmtp' ), '1hour' => __( '1 hour', 'wp-puresmtp' ) ] as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $this->options->get( 'retry_interval', '15min' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="puresmtp_retry_max_attempts"><?php esc_html_e( 'Max retry attempts', 'wp-puresmtp' ); ?></label></th>
					<td>
						<input type="number" id="puresmtp_retry_max_attempts" name="puresmtp_retry_max_attempts"
							min="1" max="100" value="<?php echo esc_attr( $this->options->get( 'retry_max_attempts', '5' ) ); ?>" class="small-text">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Notify admin on failure', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" name="puresmtp_retry_notify_admin" value="1"
								<?php checked( $this->options->get( 'retry_notify_admin', '1' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Queue Settings', 'wp-puresmtp' ) ); ?>
		</form>

		<!-- Queue table -->
		<h2 class="puresmtp-section-title">
			<?php esc_html_e( 'Pending Queue', 'wp-puresmtp' ); ?>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'queue_action' => 'process_now' ], admin_url( 'admin-post.php?action=puresmtp_queue_action' ) ), 'puresmtp_queue_action' ) ); ?>"
				class="button button-secondary">
				&#8635; <?php esc_html_e( 'Process queue now', 'wp-puresmtp' ); ?>
			</a>
		</h2>

		<?php if ( empty( $items ) ) : ?>
			<p><?php esc_html_e( 'The queue is empty.', 'wp-puresmtp' ); ?></p>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="puresmtp_queue_action">
			<input type="hidden" name="queue_action" value="bulk_delete">
			<?php wp_nonce_field( 'puresmtp_queue_action' ); ?>

			<div class="puresmtp-bulk-actions">
				<button type="submit" class="button js-confirm-clear-queue"><?php esc_html_e( 'Delete selected', 'wp-puresmtp' ); ?></button>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'queue_action' => 'clear_all' ], admin_url( 'admin-post.php?action=puresmtp_queue_action' ) ), 'puresmtp_queue_action' ) ); ?>"
					class="button puresmtp-btn-danger js-confirm-clear-queue">
					<?php esc_html_e( 'Clear all', 'wp-puresmtp' ); ?>
				</a>
			</div>

			<table class="wp-list-table widefat fixed striped puresmtp-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" class="js-check-all"></td>
						<th><?php esc_html_e( '#', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Date Added', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Next Retry', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'To', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-puresmtp' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-puresmtp' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $items as $item ) :
					$edit_url   = add_query_arg( [ 'action' => 'edit', 'queue_id' => $item['id'] ], $base_url );
					$retry_url  = wp_nonce_url(
						add_query_arg( [ 'queue_action' => 'retry', 'queue_id' => $item['id'] ], admin_url( 'admin-post.php?action=puresmtp_queue_action' ) ),
						'puresmtp_queue_action'
					);
					$delete_url = wp_nonce_url(
						add_query_arg( [ 'queue_action' => 'delete', 'queue_id' => $item['id'] ], admin_url( 'admin-post.php?action=puresmtp_queue_action' ) ),
						'puresmtp_queue_action'
					);
				?>
					<tr>
						<td class="check-column">
							<input type="checkbox" name="queue_ids[]" value="<?php echo esc_attr( $item['id'] ); ?>">
						</td>
						<td><?php echo esc_html( $item['id'] ); ?></td>
						<td><?php echo esc_html( $item['date_added'] ); ?></td>
						<td><?php echo esc_html( $item['next_retry'] ); ?></td>
						<td><?php echo esc_html( $item['attempt_count'] . ' / ' . $item['max_attempts'] ); ?></td>
						<td class="puresmtp-truncate"><?php echo esc_html( $item['recipient'] ); ?></td>
						<td class="puresmtp-truncate"><?php echo esc_html( $item['subject'] ); ?></td>
						<td><?php echo esc_html( $item['reason'] ); ?></td>
						<td>
							<?php
							$status_classes = [ 'pending' => 'puresmtp-badge-pending', 'failed' => 'puresmtp-badge-failed', 'sent' => 'puresmtp-badge-sent' ];
							$status_class   = $status_classes[ $item['status'] ] ?? 'puresmtp-badge-pending';
							?>
							<span class="puresmtp-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( ucfirst( $item['status'] ) ); ?></span>
						</td>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'wp-puresmtp' ); ?></a>
							<a href="<?php echo esc_url( $retry_url ); ?>" class="button button-small"><?php esc_html_e( 'Retry', 'wp-puresmtp' ); ?></a>
							<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small puresmtp-btn-danger js-confirm-delete-queue"><?php esc_html_e( 'Delete', 'wp-puresmtp' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Queue edit form
	// -------------------------------------------------------------------------

	private function render_queue_edit( int $id ): void {
		$item = $this->queue->get_item( $id );

		if ( ! $item ) {
			echo '<p>' . esc_html__( 'Queue item not found.', 'wp-puresmtp' ) . '</p>';
			return;
		}

		$back_url = admin_url( 'admin.php?page=wp-puresmtp&tab=queue' );
		?>
		<a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Back to queue', 'wp-puresmtp' ); ?></a>

		<h2><?php esc_html_e( 'Edit Queued Email', 'wp-puresmtp' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action"       value="puresmtp_queue_action">
			<input type="hidden" name="queue_action" value="update">
			<input type="hidden" name="queue_id"     value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'puresmtp_queue_action' ); ?>

			<table class="form-table puresmtp-form-table" role="presentation">
				<tr>
					<th><label for="qedit_recipient"><?php esc_html_e( 'To', 'wp-puresmtp' ); ?></label></th>
					<td><input type="text" id="qedit_recipient" name="recipient" value="<?php echo esc_attr( $item['recipient'] ); ?>" class="large-text"></td>
				</tr>
				<tr>
					<th><label for="qedit_subject"><?php esc_html_e( 'Subject', 'wp-puresmtp' ); ?></label></th>
					<td><input type="text" id="qedit_subject" name="subject" value="<?php echo esc_attr( $item['subject'] ); ?>" class="large-text"></td>
				</tr>
				<tr>
					<th><label for="qedit_message"><?php esc_html_e( 'Message', 'wp-puresmtp' ); ?></label></th>
					<td><textarea id="qedit_message" name="message" rows="12" class="large-text"><?php echo esc_textarea( $item['message'] ); ?></textarea></td>
				</tr>
			</table>

			<p>
				<button type="submit" name="queue_send" value="1" class="button button-primary">
					<?php esc_html_e( 'Save & Retry Now', 'wp-puresmtp' ); ?>
				</button>
				<button type="submit" name="queue_send" value="0" class="button">
					<?php esc_html_e( 'Save Only', 'wp-puresmtp' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	// =========================================================================
	// TAB: Test Email
	// =========================================================================

	private function render_tab_testmail(): void {
		?>
		<h2><?php esc_html_e( 'Send a Test Email', 'wp-puresmtp' ); ?></h2>

		<table class="form-table puresmtp-form-table" role="presentation">
			<tr>
				<th><label for="puresmtp_test_to"><?php esc_html_e( 'To', 'wp-puresmtp' ); ?></label></th>
				<td>
					<input type="email" id="puresmtp_test_to" class="regular-text"
						value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="puresmtp_test_subject"><?php esc_html_e( 'Subject', 'wp-puresmtp' ); ?></label></th>
				<td>
					<input type="text" id="puresmtp_test_subject" class="regular-text"
						value="<?php esc_attr_e( 'WP PureSMTP Test Email', 'wp-puresmtp' ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="puresmtp_test_message"><?php esc_html_e( 'Message', 'wp-puresmtp' ); ?></label></th>
				<td>
					<textarea id="puresmtp_test_message" rows="5" class="large-text"><?php echo esc_textarea( __( 'This is a test email sent from WP PureSMTP to verify your SMTP configuration.', 'wp-puresmtp' ) ); ?></textarea>
				</td>
			</tr>
		</table>

		<p>
			<button id="puresmtp-send-test" class="button button-primary">
				<?php esc_html_e( 'Send Test Email', 'wp-puresmtp' ); ?>
			</button>
		</p>

		<div id="puresmtp-test-result" style="display:none;" class="notice inline"></div>
		<?php
	}

	// =========================================================================
	// TAB: Misc
	// =========================================================================

	private function render_tab_misc(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="puresmtp_save_settings">
			<input type="hidden" name="puresmtp_tab" value="misc">
			<?php wp_nonce_field( 'puresmtp_save_misc', 'puresmtp_nonce' ); ?>

			<table class="form-table puresmtp-form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Debug Mode', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" name="puresmtp_debug" value="1"
								<?php checked( $this->options->get( 'debug' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
						<span class="puresmtp-toggle-label"><?php esc_html_e( 'Capture full SMTP conversation and save it in the email log', 'wp-puresmtp' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><label for="puresmtp_log_retention"><?php esc_html_e( 'Log Retention', 'wp-puresmtp' ); ?></label></th>
					<td>
						<select id="puresmtp_log_retention" name="puresmtp_log_retention">
							<?php foreach ( [ '7' => __( '7 days', 'wp-puresmtp' ), '14' => __( '14 days', 'wp-puresmtp' ), '30' => __( '30 days', 'wp-puresmtp' ), '90' => __( '90 days', 'wp-puresmtp' ), '0' => __( 'Never (keep forever)', 'wp-puresmtp' ) ] as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $this->options->get( 'log_retention', '30' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Automatically delete log entries older than the selected period (runs daily via WP-Cron).', 'wp-puresmtp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Remove data on uninstall', 'wp-puresmtp' ); ?></th>
					<td>
						<label class="puresmtp-toggle">
							<input type="checkbox" name="puresmtp_uninstall" value="1"
								<?php checked( $this->options->get( 'uninstall' ), '1' ); ?>>
							<span class="puresmtp-toggle-slider"></span>
						</label>
						<span class="puresmtp-toggle-label puresmtp-toggle-danger"><?php esc_html_e( 'If ON, all settings and log data are permanently deleted when the plugin is uninstalled.', 'wp-puresmtp' ); ?></span>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Misc Settings', 'wp-puresmtp' ) ); ?>
		</form>
		<?php
	}

	// =========================================================================
	// POST handlers
	// =========================================================================

	/**
	 * Handle settings save for General, Queue, and Misc tabs.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-puresmtp' ) );
		}

		$tab = isset( $_POST['puresmtp_tab'] ) ? sanitize_key( $_POST['puresmtp_tab'] ) : 'general';
		check_admin_referer( 'puresmtp_save_' . $tab, 'puresmtp_nonce' );

		switch ( $tab ) {
			case 'general':
				$this->save_general_settings();
				break;
			case 'queue':
				$this->save_queue_settings();
				break;
			case 'misc':
				$this->save_misc_settings();
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'wp-puresmtp', 'tab' => $tab, 'updated' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function save_general_settings(): void {
		$fields = [
			'from_email'       => 'sanitize_email',
			'force_from_email' => 'absint',
			'from_name'        => 'sanitize_text_field',
			'force_from_name'  => 'absint',
			'return_path'      => 'absint',
			'host'             => 'sanitize_text_field',
			'port'             => 'absint',
			'auto_tls'         => 'absint',
			'auth'             => 'absint',
			'username'         => 'sanitize_text_field',
		];

		foreach ( $fields as $key => $sanitizer ) {
			$raw   = isset( $_POST[ 'puresmtp_' . $key ] ) ? wp_unslash( $_POST[ 'puresmtp_' . $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = $sanitizer( $raw );
			$this->options->set( $key, (string) $value );
		}

		// Checkboxes (unchecked = not posted).
		foreach ( [ 'force_from_email', 'force_from_name', 'return_path', 'auto_tls', 'auth' ] as $cb ) {
			$this->options->set( $cb, isset( $_POST[ 'puresmtp_' . $cb ] ) ? '1' : '0' );
		}

		// Encryption radio.
		$enc_allowed = [ 'none', 'ssl', 'tls' ];
		$enc         = isset( $_POST['puresmtp_encryption'] ) ? sanitize_key( $_POST['puresmtp_encryption'] ) : 'tls';
		$this->options->set( 'encryption', in_array( $enc, $enc_allowed, true ) ? $enc : 'tls' );

		// Password.
		if ( isset( $_POST['puresmtp_remove_password'] ) ) {
			$this->options->set( 'password', '' );
		} elseif ( ! empty( $_POST['puresmtp_password'] ) ) {
			$plain = sanitize_text_field( wp_unslash( $_POST['puresmtp_password'] ) );
			$this->options->set( 'password', $this->options->encrypt_password( $plain ) );
		}
	}

	private function save_queue_settings(): void {
		$checkboxes = [ 'stop_sending', 'rate_limit_enabled', 'retry_queue_enabled', 'retry_notify_admin' ];
		foreach ( $checkboxes as $cb ) {
			$this->options->set( $cb, isset( $_POST[ 'puresmtp_' . $cb ] ) ? '1' : '0' );
		}

		$this->options->set( 'rate_limit_count', (string) absint( $_POST['puresmtp_rate_limit_count'] ?? 100 ) );

		$interval_allowed = [ 'minute', 'hour', 'day', 'week', 'month' ];
		$interval         = sanitize_key( $_POST['puresmtp_rate_limit_interval'] ?? 'hour' );
		$this->options->set( 'rate_limit_interval', in_array( $interval, $interval_allowed, true ) ? $interval : 'hour' );

		$retry_allowed = [ '5min', '15min', '30min', '1hour' ];
		$retry         = sanitize_key( $_POST['puresmtp_retry_interval'] ?? '15min' );
		$this->options->set( 'retry_interval', in_array( $retry, $retry_allowed, true ) ? $retry : '15min' );

		$this->options->set( 'retry_max_attempts', (string) absint( $_POST['puresmtp_retry_max_attempts'] ?? 5 ) );

		// Reschedule cron with new interval.
		wp_clear_scheduled_hook( 'puresmtp_process_queue' );
		wp_schedule_event( time(), 'puresmtp_retry_interval', 'puresmtp_process_queue' );
	}

	private function save_misc_settings(): void {
		$this->options->set( 'debug', isset( $_POST['puresmtp_debug'] ) ? '1' : '0' );
		$this->options->set( 'uninstall', isset( $_POST['puresmtp_uninstall'] ) ? '1' : '0' );

		$retention_allowed = [ '0', '7', '14', '30', '90' ];
		$retention         = (string) absint( $_POST['puresmtp_log_retention'] ?? 30 );
		$this->options->set( 'log_retention', in_array( $retention, $retention_allowed, true ) ? $retention : '30' );
	}

	/**
	 * Handle log table actions: view (GET redirect), delete, bulk_delete, clear_all.
	 */
	public function handle_log_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-puresmtp' ) );
		}

		check_admin_referer( 'puresmtp_log_action' );

		$log_action = isset( $_REQUEST['log_action'] ) ? sanitize_key( $_REQUEST['log_action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $log_action ) {
			case 'delete':
				$id = absint( $_REQUEST['entry_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $id ) {
					$this->logger->delete_entry( $id );
				}
				break;

			case 'bulk_delete':
				$ids = isset( $_POST['entry_ids'] ) ? array_map( 'absint', (array) $_POST['entry_ids'] ) : [];
				if ( $ids ) {
					$this->logger->delete_entries( $ids );
				}
				break;

			case 'clear_all':
				$this->logger->clear_all();
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp-puresmtp&tab=log&updated=1' ) );
		exit;
	}

	/**
	 * Handle queue table actions.
	 */
	public function handle_queue_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-puresmtp' ) );
		}

		check_admin_referer( 'puresmtp_queue_action' );

		$queue_action = isset( $_REQUEST['queue_action'] ) ? sanitize_key( $_REQUEST['queue_action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $queue_action ) {
			case 'retry':
				$id = absint( $_REQUEST['queue_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $id ) {
					$this->queue->retry( $id );
				}
				break;

			case 'delete':
				$id = absint( $_REQUEST['queue_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $id ) {
					$this->queue->delete_item( $id );
				}
				break;

			case 'bulk_delete':
				$ids = isset( $_POST['queue_ids'] ) ? array_map( 'absint', (array) $_POST['queue_ids'] ) : [];
				if ( $ids ) {
					$this->queue->delete_items( $ids );
				}
				break;

			case 'process_now':
				$this->queue->process_queue();
				break;

			case 'clear_all':
				$this->queue->clear_queue();
				break;

			case 'update':
				$id   = absint( $_POST['queue_id'] ?? 0 );
				$send = isset( $_POST['queue_send'] ) && '1' === $_POST['queue_send'];

				if ( $id ) {
					$this->queue->update_item( $id, [
						'recipient' => sanitize_text_field( wp_unslash( $_POST['recipient'] ?? '' ) ),
						'subject'   => sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ),
						'message'   => wp_kses_post( wp_unslash( $_POST['message'] ?? '' ) ),
					] );

					if ( $send ) {
						$this->queue->retry( $id );
					}
				}
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp-puresmtp&tab=queue&updated=1' ) );
		exit;
	}

	/**
	 * AJAX: trigger log auto-cleanup now.
	 */
	public function ajax_log_cleanup(): void {
		check_ajax_referer( 'puresmtp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'wp-puresmtp' ) );
		}

		$days = (int) $this->options->get( 'log_retention', '30' );
		if ( $days > 0 ) {
			$this->logger->cleanup( $days );
		}

		wp_send_json_success( __( 'Log cleaned up.', 'wp-puresmtp' ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function render_pagination( int $current_page, int $total_pages, string $base_url ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';

		for ( $i = 1; $i <= $total_pages; $i++ ) {
			$class = ( $i === $current_page ) ? 'button button-primary' : 'button';
			$url   = add_query_arg( 'paged', $i, $base_url );
			printf( '<a href="%s" class="%s">%d</a> ', esc_url( $url ), esc_attr( $class ), (int) $i );
		}

		echo '</div></div>';
	}

	/**
	 * Return a human-readable hint for common SMTP errors.
	 *
	 * @param string $error_message Error text from PHPMailer/SMTP.
	 * @return string
	 */
	private function get_error_hint( string $error_message ): string {
		$hints = [
			'SMTP connect() failed' => __( 'Check SMTP host and port in General settings. Your server may be blocking outgoing SMTP connections.', 'wp-puresmtp' ),
			'535'                   => __( 'Authentication failed. Verify your SMTP username and password in General settings.', 'wp-puresmtp' ),
			'550 No such user'      => __( 'The recipient address does not exist on the remote server.', 'wp-puresmtp' ),
			'550 Relay'             => __( 'Relay access denied. Use the exact email address associated with your SMTP account as From Email.', 'wp-puresmtp' ),
			'timed out'             => __( 'Connection timed out. Check if your hosting provider blocks outgoing SMTP ports (25, 465, 587).', 'wp-puresmtp' ),
			'STARTTLS'              => __( 'STARTTLS negotiation failed. Try switching between SSL and TLS encryption in General settings.', 'wp-puresmtp' ),
		];

		foreach ( $hints as $needle => $hint ) {
			if ( false !== stripos( $error_message, $needle ) ) {
				return $hint;
			}
		}

		return '';
	}
}
