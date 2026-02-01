<?php
/**
 * Notifications settings tab.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Service\EmailTemplateService;

defined( 'ABSPATH' ) || exit;

/**
 * Notifications tab - handles email notification settings and templates.
 */
class NotificationsTab {

	/**
	 * Email template service.
	 *
	 * @var EmailTemplateService
	 */
	private EmailTemplateService $email_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->email_service = new EmailTemplateService();
		$this->register_ajax_handlers();
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue notifications tab assets.
	 * WordPress.org requires proper enqueue instead of inline tags.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_zbooks' ) {
			return;
		}

		// Check if we're on notifications tab.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = $_GET['tab'] ?? '';
		if ( $tab !== 'notifications' ) {
			return;
		}

		// Enqueue CSS.
		wp_enqueue_style(
			'zbooks-notifications-tab',
			ZBOOKS_PLUGIN_URL . 'assets/css/modules/notifications.css',
			[],
			ZBOOKS_VERSION
		);

		// Enqueue notifications JS module.
		wp_enqueue_script(
			'zbooks-notifications-tab',
			ZBOOKS_PLUGIN_URL . 'assets/js/modules/notifications.js',
			[ 'jquery', 'zbooks-admin' ],
			ZBOOKS_VERSION,
			true
		);

		// Localize script with nonce (WordPress.org compliant).
		wp_localize_script(
			'zbooks-notifications-tab',
			'ZbooksNotificationsConfig',
			[
				'nonce'   => wp_create_nonce( 'zbooks_notification_nonce' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			]
		);

		// Add delivery mode toggle inline script (WordPress.org compliant).
		$inline_script = "
		jQuery(document).ready(function($) {
			$('input[name=\"zbooks_notification_settings[delivery_mode]\"]').on('change', function() {
				var mode = $(this).val();
				$('.zbooks-notification-option').removeClass('is-visible');
				if (mode) {
					$('.zbooks-notification-option[data-mode=\"' + mode + '\"]').addClass('is-visible');
				}
			});
		});
		";
		wp_add_inline_script( 'zbooks-admin', $inline_script );
	}

	/**
	 * Register AJAX handlers.
	 */
	private function register_ajax_handlers(): void {
		add_action( 'wp_ajax_zbooks_send_test_email', [ $this, 'ajax_send_test_email' ] );
		add_action( 'wp_ajax_zbooks_preview_email_template', [ $this, 'ajax_preview_email_template' ] );
	}

	/**
	 * Register settings for this tab.
	 */
	public function register_settings(): void {
		register_setting(
			'zbooks_settings_notifications',
			'zbooks_notification_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			]
		);

		add_settings_section(
			'zbooks_notification_section',
			__( 'Email Notifications', 'zbooks-for-woocommerce' ),
			[ $this, 'render_section' ],
			'zbooks-settings-notifications'
		);

		add_settings_field(
			'zbooks_notification_settings',
			__( 'Notification Settings', 'zbooks-for-woocommerce' ),
			[ $this, 'render_settings_field' ],
			'zbooks-settings-notifications',
			'zbooks_notification_section'
		);
	}

	/**
	 * Render the tab content.
	 */
	public function render_content(): void {
		?>
		<!-- Styles now loaded from assets/css/modules/notifications.css -->

		<form method="post" action="options.php">
			<?php
			settings_fields( 'zbooks_settings_notifications' );
			?>

			<div class="zbooks-notification-card">
				<h3><?php esc_html_e( 'Email Recipient', 'zbooks-for-woocommerce' ); ?></h3>
				<?php $this->render_email_field(); ?>
			</div>

			<div class="zbooks-notification-card">
				<h3><?php esc_html_e( 'Notification Types', 'zbooks-for-woocommerce' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Select which events should trigger email notifications.', 'zbooks-for-woocommerce' ); ?>
				</p>
				<?php $this->render_notification_types(); ?>
			</div>

			<div class="zbooks-notification-card">
				<h3><?php esc_html_e( 'Delivery Mode', 'zbooks-for-woocommerce' ); ?></h3>
				<?php $this->render_delivery_mode_settings(); ?>
			</div>

			<div class="zbooks-notification-card">
				<h3><?php esc_html_e( 'Test Email', 'zbooks-for-woocommerce' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Send a test email to verify your notification settings are working correctly.', 'zbooks-for-woocommerce' ); ?>
				</p>
				<?php $this->render_test_email_section(); ?>
			</div>

			<div class="zbooks-notification-card">
				<h3><?php esc_html_e( 'Email Template Preview', 'zbooks-for-woocommerce' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Preview how notification emails will appear to recipients.', 'zbooks-for-woocommerce' ); ?>
				</p>
				<?php $this->render_template_preview(); ?>
			</div>

			<?php submit_button(); ?>
		</form>

		<?php $this->render_scripts(); ?>
		<?php
	}

	/**
	 * Render section description.
	 */
	public function render_section(): void {
		?>
		<p><?php esc_html_e( 'Configure email notifications for sync events and errors.', 'zbooks-for-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Render email recipient field.
	 */
	private function render_email_field(): void {
		$settings = $this->get_settings();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="zbooks_notification_email">
						<?php esc_html_e( 'Email Address', 'zbooks-for-woocommerce' ); ?>
					</label>
				</th>
				<td>
					<input type="email"
						id="zbooks_notification_email"
						name="zbooks_notification_settings[email]"
						value="<?php echo esc_attr( $settings['email'] ); ?>"
						class="regular-text"
						placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
					<p class="description">
						<?php esc_html_e( 'Leave blank to use the site admin email.', 'zbooks-for-woocommerce' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render notification type checkboxes.
	 */
	private function render_notification_types(): void {
		$settings = $this->get_settings();
		$types    = $this->get_notification_types();
		?>
		<div class="zbooks-notification-types">
			<?php foreach ( $types as $type_id => $type ) : ?>
				<label class="zbooks-notification-type">
					<input type="checkbox"
						name="zbooks_notification_settings[types][<?php echo esc_attr( $type_id ); ?>]"
						value="1"
						<?php checked( ! empty( $settings['types'][ $type_id ] ) ); ?>>
					<div class="zbooks-notification-type-content">
						<div class="zbooks-notification-type-title">
							<?php echo esc_html( $type['title'] ); ?>
						</div>
						<div class="zbooks-notification-type-desc">
							<?php echo esc_html( $type['description'] ); ?>
						</div>
					</div>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render delivery mode settings.
	 */
	private function render_delivery_mode_settings(): void {
		$settings = $this->get_settings();
		$mode     = $settings['delivery_mode'] ?? 'digest';
		$interval = $settings['digest_interval'] ?? 5;
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Delivery Method', 'zbooks-for-woocommerce' ); ?></th>
				<td>
					<fieldset>
						<label style="display: block; margin-bottom: 12px;">
							<input type="radio"
								name="zbooks_notification_settings[delivery_mode]"
								value="immediate"
								<?php checked( $mode, 'immediate' ); ?>>
							<strong><?php esc_html_e( 'Send Immediately', 'zbooks-for-woocommerce' ); ?></strong>
							<p class="description" style="margin-left: 24px; margin-top: 4px;">
								<?php esc_html_e( 'Send each notification as it happens. Best for low-volume stores or critical alerts.', 'zbooks-for-woocommerce' ); ?>
							</p>
						</label>
						<label style="display: block;">
							<input type="radio"
								name="zbooks_notification_settings[delivery_mode]"
								value="digest"
								<?php checked( $mode, 'digest' ); ?>>
							<strong><?php esc_html_e( 'Consolidate into Digest', 'zbooks-for-woocommerce' ); ?></strong>
							<p class="description" style="margin-left: 24px; margin-top: 4px;">
								<?php esc_html_e( 'Batch notifications and send a single digest email periodically. Recommended for busy stores to prevent inbox flooding.', 'zbooks-for-woocommerce' ); ?>
							</p>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr id="zbooks_digest_interval_row" style="<?php echo 'digest' !== $mode ? 'display:none;' : ''; ?>">
				<th scope="row">
					<label for="zbooks_digest_interval"><?php esc_html_e( 'Digest Interval', 'zbooks-for-woocommerce' ); ?></label>
				</th>
				<td>
					<select name="zbooks_notification_settings[digest_interval]" id="zbooks_digest_interval">
						<option value="5" <?php selected( $interval, 5 ); ?>><?php esc_html_e( 'Every 5 minutes', 'zbooks-for-woocommerce' ); ?></option>
						<option value="15" <?php selected( $interval, 15 ); ?>><?php esc_html_e( 'Every 15 minutes', 'zbooks-for-woocommerce' ); ?></option>
						<option value="30" <?php selected( $interval, 30 ); ?>><?php esc_html_e( 'Every 30 minutes', 'zbooks-for-woocommerce' ); ?></option>
						<option value="60" <?php selected( $interval, 60 ); ?>><?php esc_html_e( 'Every hour', 'zbooks-for-woocommerce' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'How often to send digest emails when notifications are queued.', 'zbooks-for-woocommerce' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<div class="zbooks-delivery-mode-info" style="margin-top: 16px; padding: 12px 16px; background: #f0f6fc; border-left: 4px solid #0073aa; border-radius: 0 4px 4px 0;">
			<p style="margin: 0; font-size: 13px; color: #1d2327;">
				<strong><?php esc_html_e( 'Why use digest mode?', 'zbooks-for-woocommerce' ); ?></strong><br>
				<?php esc_html_e( 'During bulk syncs or busy periods, many errors or warnings can occur in quick succession. Digest mode prevents your inbox from being flooded with individual emails by grouping all notifications into a single, organized summary.', 'zbooks-for-woocommerce' ); ?>
			</p>
		</div>

		<!-- JavaScript now output via wp_add_inline_script() -->
		<?php
	}

	/**
	 * Render test email section.
	 */
	private function render_test_email_section(): void {
		$settings = $this->get_settings();
		$mode     = $settings['delivery_mode'] ?? 'digest';
		?>
		<div class="zbooks-test-email-section">
			<select id="zbooks_test_email_type">
				<option value="error"><?php esc_html_e( 'Sync Error', 'zbooks-for-woocommerce' ); ?></option>
				<option value="warning"><?php esc_html_e( 'Warning', 'zbooks-for-woocommerce' ); ?></option>
				<option value="success"><?php esc_html_e( 'Success', 'zbooks-for-woocommerce' ); ?></option>
				<option value="digest" <?php selected( $mode, 'digest' ); ?>><?php esc_html_e( 'Digest (Multiple)', 'zbooks-for-woocommerce' ); ?></option>
			</select>
			<button type="button" id="zbooks_send_test_email" class="button button-secondary">
				<?php esc_html_e( 'Send Test Email', 'zbooks-for-woocommerce' ); ?>
			</button>
			<span id="zbooks_test_email_result"></span>
		</div>
		<?php
	}

	/**
	 * Render template preview section.
	 */
	private function render_template_preview(): void {
		$settings = $this->get_settings();
		$mode     = $settings['delivery_mode'] ?? 'digest';
		// Default preview to digest if in digest mode, otherwise error.
		$default_preview = 'digest' === $mode ? 'digest' : 'error';
		?>
		<div class="zbooks-email-preview">
			<div class="zbooks-email-preview-header">
				<h4><?php esc_html_e( 'Preview', 'zbooks-for-woocommerce' ); ?></h4>
				<select id="zbooks_preview_type">
					<option value="error" <?php selected( $default_preview, 'error' ); ?>><?php esc_html_e( 'Sync Error', 'zbooks-for-woocommerce' ); ?></option>
					<option value="warning"><?php esc_html_e( 'Warning', 'zbooks-for-woocommerce' ); ?></option>
					<option value="success"><?php esc_html_e( 'Success', 'zbooks-for-woocommerce' ); ?></option>
					<option value="digest" <?php selected( $default_preview, 'digest' ); ?>><?php esc_html_e( 'Digest (Multiple)', 'zbooks-for-woocommerce' ); ?></option>
				</select>
			</div>
			<iframe id="zbooks_preview_frame" class="zbooks-email-preview-frame"></iframe>
		</div>
		<?php
	}

	/**
	 * Render placeholder settings field (required by WordPress).
	 */
	public function render_settings_field(): void {
		// Content is rendered directly in render_content().
	}

	/**
	 * Render JavaScript for the tab.
	 */
	private function render_scripts(): void {
		$nonce = wp_create_nonce( 'zbooks_notification_nonce' );
		?>
		<!-- JavaScript now output via wp_add_inline_script() -->
		<?php
	}

	/**
	 * Get notification settings with defaults.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		$defaults = [
			'email'           => get_option( 'admin_email' ),
			'delivery_mode'   => 'digest',
			'digest_interval' => 5,
			'types'           => [
				'sync_errors'       => true,
				'sync_warnings'     => false,
				'reconciliation'    => false,
				'payment_applied'   => false,
				'currency_mismatch' => true,
			],
		];

		// Migrate from old log_settings if needed.
		$old_settings = get_option( 'zbooks_log_settings', [] );
		if ( ! empty( $old_settings['email_on_error'] ) ) {
			$defaults['types']['sync_errors'] = true;
		}
		if ( ! empty( $old_settings['error_email'] ) ) {
			$defaults['email'] = $old_settings['error_email'];
		}

		$saved = get_option( 'zbooks_notification_settings', [] );
		return array_replace_recursive( $defaults, $saved );
	}

	/**
	 * Get available notification types.
	 *
	 * @return array
	 */
	private function get_notification_types(): array {
		return [
			'sync_errors'       => [
				'title'       => __( 'Sync Errors', 'zbooks-for-woocommerce' ),
				'description' => __( 'Get notified when an order fails to sync to Zoho Books due to API errors, validation issues, or connection problems.', 'zbooks-for-woocommerce' ),
			],
			'currency_mismatch' => [
				'title'       => __( 'Currency Mismatches', 'zbooks-for-woocommerce' ),
				'description' => __( 'Get notified when an order cannot sync because the customer contact in Zoho has a different currency than the order.', 'zbooks-for-woocommerce' ),
			],
			'sync_warnings'     => [
				'title'       => __( 'Sync Warnings', 'zbooks-for-woocommerce' ),
				'description' => __( 'Get notified about non-critical issues like skipped bank fees or missing product mappings.', 'zbooks-for-woocommerce' ),
			],
			'reconciliation'    => [
				'title'       => __( 'Reconciliation Reports', 'zbooks-for-woocommerce' ),
				'description' => __( 'Receive scheduled reconciliation reports comparing WooCommerce orders with Zoho invoices.', 'zbooks-for-woocommerce' ),
			],
			'payment_applied'   => [
				'title'       => __( 'Payment Applied', 'zbooks-for-woocommerce' ),
				'description' => __( 'Get notified when payments are successfully applied to invoices in Zoho Books.', 'zbooks-for-woocommerce' ),
			],
		];
	}

	/**
	 * Check if a notification type is enabled.
	 *
	 * @param string $type Notification type.
	 * @return bool
	 */
	public function is_notification_enabled( string $type ): bool {
		$settings = $this->get_settings();
		return ! empty( $settings['types'][ $type ] );
	}

	/**
	 * Get the notification email address.
	 *
	 * @return string
	 */
	public function get_notification_email(): string {
		$settings = $this->get_settings();
		$email    = $settings['email'] ?? '';
		return ! empty( $email ) && is_email( $email ) ? $email : get_option( 'admin_email' );
	}

	/**
	 * Sanitize notification settings.
	 *
	 * @param array $input Input data.
	 * @return array
	 */
	public function sanitize_settings( array $input ): array {
		$email = ! empty( $input['email'] )
			? sanitize_email( $input['email'] )
			: '';

		$delivery_mode = in_array( $input['delivery_mode'] ?? '', [ 'immediate', 'digest' ], true )
			? $input['delivery_mode']
			: 'digest';

		$valid_intervals = [ 5, 15, 30, 60 ];
		$digest_interval = (int) ( $input['digest_interval'] ?? 5 );
		$digest_interval = in_array( $digest_interval, $valid_intervals, true ) ? $digest_interval : 5;

		$types = [];
		if ( ! empty( $input['types'] ) && is_array( $input['types'] ) ) {
			foreach ( array_keys( $this->get_notification_types() ) as $type_id ) {
				$types[ $type_id ] = ! empty( $input['types'][ $type_id ] );
			}
		}

		// Reschedule cron if interval changed.
		$old_settings = get_option( 'zbooks_notification_settings', [] );
		$old_interval = $old_settings['digest_interval'] ?? 5;
		$old_mode     = $old_settings['delivery_mode'] ?? 'digest';

		if ( $digest_interval !== $old_interval || $delivery_mode !== $old_mode ) {
			// Unschedule existing cron.
			$timestamp = wp_next_scheduled( 'zbooks_send_notification_digest' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'zbooks_send_notification_digest' );
			}

			// Reschedule with new interval if in digest mode.
			if ( 'digest' === $delivery_mode ) {
				$schedule = 'zbooks_' . $digest_interval . '_minutes';
				wp_schedule_event( time(), $schedule, 'zbooks_send_notification_digest' );
			}
		}

		return [
			'email'           => $email,
			'delivery_mode'   => $delivery_mode,
			'digest_interval' => $digest_interval,
			'types'           => $types,
		];
	}

	/**
	 * AJAX: Send test email.
	 */
	public function ajax_send_test_email(): void {
		check_ajax_referer( 'zbooks_notification_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$type  = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'error';
		$email = $this->get_notification_email();

		$site_name = get_bloginfo( 'name' );
		$logs_url  = admin_url( 'admin.php?page=zbooks-logs' );

		switch ( $type ) {
			case 'warning':
				$subject = sprintf(
					/* translators: %s: Site name */
					__( '[%s] ZBooks Warning (Test)', 'zbooks-for-woocommerce' ),
					$site_name
				);
				$body = $this->email_service->build_warning_email(
					__( 'This is a test warning notification. Bank fees were skipped because the payment gateway processed in a different currency.', 'zbooks-for-woocommerce' ),
					[
						'order_id'       => 12345,
						'order_number'   => '#12345',
						'fee_currency'   => 'AED',
						'order_currency' => 'USD',
					],
					$logs_url,
					__( 'View Logs', 'zbooks-for-woocommerce' )
				);
				break;

			case 'success':
				$subject = sprintf(
					/* translators: %s: Site name */
					__( '[%s] ZBooks Sync Complete (Test)', 'zbooks-for-woocommerce' ),
					$site_name
				);
				$body = $this->email_service->build_success_email(
					__( 'Bulk sync completed successfully', 'zbooks-for-woocommerce' ),
					[
						__( 'Orders', 'zbooks-for-woocommerce' )   => 25,
						__( 'Invoices', 'zbooks-for-woocommerce' ) => 25,
						__( 'Payments', 'zbooks-for-woocommerce' ) => 20,
					],
					admin_url( 'admin.php?page=zbooks&tab=orders' ),
					__( 'View Orders', 'zbooks-for-woocommerce' )
				);
				break;

			case 'digest':
				$subject = sprintf(
					/* translators: %s: Site name */
					__( '[%s] ZBooks Digest: 3 notifications (Test)', 'zbooks-for-woocommerce' ),
					$site_name
				);
				$body = $this->email_service->build_digest_email(
					$this->get_sample_digest_notifications(),
					'warning'
				);
				break;

			default: // error
				$subject = sprintf(
					/* translators: %s: Site name */
					__( '[%s] ZBooks Sync Error (Test)', 'zbooks-for-woocommerce' ),
					$site_name
				);
				$body = $this->email_service->build_error_email(
					__( 'Currency mismatch: Contact is set to AED but order uses USD. Please update the contact currency in Zoho Books or use a different email.', 'zbooks-for-woocommerce' ),
					[
						'order_id'         => 12345,
						'order_number'     => '#12345',
						'customer_email'   => 'customer@example.com',
						'contact_currency' => 'AED',
						'order_currency'   => 'USD',
					],
					$logs_url
				);
				break;
		}

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$sent    = wp_mail( $email, $subject, $body, $headers );

		if ( $sent ) {
			wp_send_json_success(
				[
					'message' => sprintf(
						/* translators: %s: Email address */
						__( 'Test email sent to %s', 'zbooks-for-woocommerce' ),
						$email
					),
				]
			);
		} else {
			wp_send_json_error(
				[
					'message' => __( 'Failed to send test email. Please check your WordPress email configuration.', 'zbooks-for-woocommerce' ),
				]
			);
		}
	}

	/**
	 * AJAX: Preview email template.
	 */
	public function ajax_preview_email_template(): void {
		check_ajax_referer( 'zbooks_notification_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$type     = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'error';
		$logs_url = admin_url( 'admin.php?page=zbooks-logs' );

		switch ( $type ) {
			case 'warning':
				$html = $this->email_service->build_warning_email(
					__( 'Bank fees were skipped because the payment gateway processed in a different currency and no exchange rate could be calculated.', 'zbooks-for-woocommerce' ),
					[
						'order_id'       => 1234,
						'order_number'   => '#1234',
						'fee_currency'   => 'AED',
						'order_currency' => 'USD',
						'raw_fee'        => '71.76',
					],
					$logs_url,
					__( 'View Logs', 'zbooks-for-woocommerce' )
				);
				break;

			case 'success':
				$html = $this->email_service->build_success_email(
					__( 'Bulk sync completed successfully', 'zbooks-for-woocommerce' ),
					[
						__( 'Orders', 'zbooks-for-woocommerce' )   => 25,
						__( 'Invoices', 'zbooks-for-woocommerce' ) => 25,
						__( 'Payments', 'zbooks-for-woocommerce' ) => 20,
					],
					admin_url( 'admin.php?page=zbooks&tab=orders' ),
					__( 'View Orders', 'zbooks-for-woocommerce' )
				);
				break;

			case 'digest':
				$html = $this->email_service->build_digest_email(
					$this->get_sample_digest_notifications(),
					'warning'
				);
				break;

			default: // error
				$html = $this->email_service->build_error_email(
					__( 'Currency mismatch: Contact is set to AED but order uses USD. Please update the contact currency in Zoho Books or use a different email.', 'zbooks-for-woocommerce' ),
					[
						'order_id'         => 1234,
						'order_number'     => '#1234',
						'customer_email'   => 'customer@example.com',
						'contact_currency' => 'AED',
						'order_currency'   => 'USD',
					],
					$logs_url
				);
				break;
		}

		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * Get sample notifications for digest preview/test.
	 *
	 * @return array Grouped notifications by type.
	 */
	private function get_sample_digest_notifications(): array {
		return [
			'error'   => [
				[
					'title'     => __( 'Sync Error - Order #1234', 'zbooks-for-woocommerce' ),
					'message'   => __( 'Currency mismatch: Contact is set to AED but order uses USD.', 'zbooks-for-woocommerce' ),
					'timestamp' => current_time( 'mysql' ),
				],
			],
			'warning' => [
				[
					'title'     => __( 'Warning - Order #1235', 'zbooks-for-woocommerce' ),
					'message'   => __( 'Bank fees skipped: Payment gateway processed in AED but order is in USD.', 'zbooks-for-woocommerce' ),
					'timestamp' => current_time( 'mysql' ),
				],
				[
					'title'     => __( 'Warning - Order #1236', 'zbooks-for-woocommerce' ),
					'message'   => __( 'Product mapping not found for SKU "WIDGET-001". Using generic item.', 'zbooks-for-woocommerce' ),
					'timestamp' => current_time( 'mysql' ),
				],
			],
			'success' => [
				[
					'title'     => __( 'Payment Applied - Order #1237', 'zbooks-for-woocommerce' ),
					'message'   => __( 'Payment of $150.00 applied to invoice INV-00123.', 'zbooks-for-woocommerce' ),
					'timestamp' => current_time( 'mysql' ),
				],
			],
		];
	}
}
