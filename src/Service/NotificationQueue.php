<?php
/**
 * Notification queue service for batching email notifications.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Queues notifications and sends them as batched digest emails.
 *
 * Instead of sending individual emails for each notification (which can
 * flood inboxes), this service queues notifications and sends them in
 * a single digest email every 5 minutes.
 */
class NotificationQueue {

	/**
	 * Transient key for the notification queue.
	 *
	 * @var string
	 */
	private const QUEUE_KEY = 'zbooks_notification_queue';

	/**
	 * Transient key for the last send timestamp.
	 *
	 * @var string
	 */
	private const LAST_SEND_KEY = 'zbooks_notification_last_send';

	/**
	 * Minimum interval between digest emails (in seconds).
	 *
	 * @var int
	 */
	private const SEND_INTERVAL = 300; // 5 minutes

	/**
	 * Email template service.
	 *
	 * @var EmailTemplateService
	 */
	private EmailTemplateService $email_service;

	/**
	 * Constructor.
	 *
	 * @param EmailTemplateService|null $email_service Email template service.
	 */
	public function __construct( ?EmailTemplateService $email_service = null ) {
		$this->email_service = $email_service ?? new EmailTemplateService();
	}

	/**
	 * Add a notification to the queue (or send immediately based on settings).
	 *
	 * @param string $type    Notification type (error, warning, success, info).
	 * @param string $title   Notification title.
	 * @param string $message Notification message (can include HTML).
	 * @param array  $context Additional context data.
	 * @return void
	 */
	public function queue( string $type, string $title, string $message, array $context = [] ): void {
		// Check if this notification type is enabled.
		if ( ! $this->is_notification_enabled( $type, $context ) ) {
			return;
		}

		$settings      = $this->get_notification_settings();
		$delivery_mode = $settings['delivery_mode'] ?? 'digest';

		// If immediate mode, send right away.
		if ( 'immediate' === $delivery_mode ) {
			$this->send_immediate( $type, $title, $message, $context );
			return;
		}

		// Otherwise, add to queue for digest.
		$queue   = $this->get_queue();
		$queue[] = [
			'type'      => $type,
			'title'     => $title,
			'message'   => $message,
			'context'   => $context,
			'timestamp' => current_time( 'mysql' ),
		];

		// Store queue with 1 hour expiration (cleanup if cron fails).
		set_transient( self::QUEUE_KEY, $queue, HOUR_IN_SECONDS );

		// Check if it's time to send the digest.
		$this->maybe_send_digest();
	}

	/**
	 * Send a notification immediately (bypasses queue).
	 *
	 * @param string $type    Notification type.
	 * @param string $title   Notification title.
	 * @param string $message Notification message.
	 * @param array  $context Additional context.
	 * @return bool
	 */
	private function send_immediate( string $type, string $title, string $message, array $context ): bool {
		$settings = $this->get_notification_settings();
		$email    = $settings['email'] ?? get_option( 'admin_email' );

		if ( empty( $email ) ) {
			return false;
		}

		$site_name = get_bloginfo( 'name' );
		$logs_url  = admin_url( 'admin.php?page=zbooks-log' );

		// Build subject based on type.
		$type_labels = [
			'error'   => __( 'Sync Error', 'zbooks-for-woocommerce' ),
			'warning' => __( 'Warning', 'zbooks-for-woocommerce' ),
			'success' => __( 'Success', 'zbooks-for-woocommerce' ),
			'info'    => __( 'Notification', 'zbooks-for-woocommerce' ),
		];

		$subject = sprintf(
			/* translators: 1: Site name, 2: Notification type */
			__( '[%1$s] ZBooks %2$s', 'zbooks-for-woocommerce' ),
			$site_name,
			$type_labels[ $type ] ?? $type_labels['info']
		);

		// Build email body based on type.
		switch ( $type ) {
			case 'error':
				$body = $this->email_service->build_error_email( $message, $context, $logs_url );
				break;

			case 'warning':
				$body = $this->email_service->build_warning_email(
					$message,
					$context,
					$logs_url,
					__( 'View Logs', 'zbooks-for-woocommerce' )
				);
				break;

			case 'success':
				$body = $this->email_service->build_success_email(
					$title,
					[],
					$logs_url,
					__( 'View Logs', 'zbooks-for-woocommerce' )
				);
				break;

			default:
				$body = $this->email_service->build_warning_email( $message, $context );
				break;
		}

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		return wp_mail( $email, $subject, $body, $headers );
	}

	/**
	 * Check if a notification type is enabled.
	 *
	 * @param string $type    Notification type.
	 * @param array  $context Context with optional 'notification_key'.
	 * @return bool
	 */
	private function is_notification_enabled( string $type, array $context ): bool {
		$settings = $this->get_notification_settings();

		// Map types to setting keys.
		$type_mapping = [
			'error'   => 'sync_errors',
			'warning' => 'warnings',
			'success' => 'payment_confirmations',
			'info'    => 'warnings', // Info messages grouped with warnings.
		];

		// Use context notification_key if provided, otherwise map from type.
		$setting_key = $context['notification_key'] ?? ( $type_mapping[ $type ] ?? 'warnings' );

		return ! empty( $settings['types'][ $setting_key ] );
	}

	/**
	 * Get notification settings.
	 *
	 * @return array
	 */
	private function get_notification_settings(): array {
		$defaults = [
			'email' => get_option( 'admin_email' ),
			'types' => [
				'sync_errors'           => true,
				'currency_mismatches'   => true,
				'warnings'              => false,
				'reconciliation'        => false,
				'payment_confirmations' => false,
			],
		];
		$saved    = get_option( 'zbooks_notification_settings', [] );
		return array_replace_recursive( $defaults, $saved );
	}

	/**
	 * Get the current notification queue.
	 *
	 * @return array
	 */
	private function get_queue(): array {
		$queue = get_transient( self::QUEUE_KEY );
		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * Clear the notification queue.
	 *
	 * @return void
	 */
	private function clear_queue(): void {
		delete_transient( self::QUEUE_KEY );
	}

	/**
	 * Check if it's time to send digest and send if needed.
	 *
	 * @return bool Whether a digest was sent.
	 */
	public function maybe_send_digest(): bool {
		$queue = $this->get_queue();

		if ( empty( $queue ) ) {
			return false;
		}

		$last_send = get_transient( self::LAST_SEND_KEY );

		// If never sent or interval has passed, send now.
		if ( false === $last_send || ( time() - (int) $last_send ) >= self::SEND_INTERVAL ) {
			return $this->send_digest();
		}

		return false;
	}

	/**
	 * Force send the digest immediately.
	 *
	 * @return bool Whether the digest was sent successfully.
	 */
	public function send_digest(): bool {
		$queue = $this->get_queue();

		if ( empty( $queue ) ) {
			return false;
		}

		$settings = $this->get_notification_settings();
		$email    = $settings['email'] ?? get_option( 'admin_email' );

		if ( empty( $email ) ) {
			return false;
		}

		// Group notifications by type.
		$grouped = $this->group_notifications( $queue );

		// Determine overall severity for subject line.
		$severity = $this->get_overall_severity( $grouped );

		// Build subject.
		$site_name = get_bloginfo( 'name' );
		$count     = count( $queue );
		$subject   = sprintf(
			/* translators: 1: Site name, 2: Count, 3: Plural suffix */
			__( '[%1$s] ZBooks Digest: %2$d notification%3$s', 'zbooks-for-woocommerce' ),
			$site_name,
			$count,
			$count > 1 ? 's' : ''
		);

		// Build email body.
		$body = $this->email_service->build_digest_email( $grouped, $severity );

		// Send email.
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$sent    = wp_mail( $email, $subject, $body, $headers );

		if ( $sent ) {
			// Clear the queue and update last send time.
			$this->clear_queue();
			set_transient( self::LAST_SEND_KEY, time(), DAY_IN_SECONDS );
		}

		return $sent;
	}

	/**
	 * Group notifications by type.
	 *
	 * @param array $queue The notification queue.
	 * @return array Grouped notifications.
	 */
	private function group_notifications( array $queue ): array {
		$grouped = [
			'error'   => [],
			'warning' => [],
			'success' => [],
			'info'    => [],
		];

		foreach ( $queue as $notification ) {
			$type = $notification['type'] ?? 'info';
			if ( isset( $grouped[ $type ] ) ) {
				$grouped[ $type ][] = $notification;
			} else {
				$grouped['info'][] = $notification;
			}
		}

		// Remove empty groups.
		return array_filter( $grouped );
	}

	/**
	 * Get the overall severity based on grouped notifications.
	 *
	 * @param array $grouped Grouped notifications.
	 * @return string Severity level (error, warning, success, info).
	 */
	private function get_overall_severity( array $grouped ): string {
		if ( ! empty( $grouped['error'] ) ) {
			return 'error';
		}
		if ( ! empty( $grouped['warning'] ) ) {
			return 'warning';
		}
		if ( ! empty( $grouped['success'] ) ) {
			return 'success';
		}
		return 'info';
	}

	/**
	 * Get the count of queued notifications.
	 *
	 * @return int
	 */
	public function get_queue_count(): int {
		return count( $this->get_queue() );
	}

	/**
	 * Schedule the digest cron job if not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'zbooks_send_notification_digest' ) ) {
			wp_schedule_event( time(), 'zbooks_five_minutes', 'zbooks_send_notification_digest' );
		}
	}

	/**
	 * Unschedule the digest cron job.
	 *
	 * @return void
	 */
	public static function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( 'zbooks_send_notification_digest' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'zbooks_send_notification_digest' );
		}
	}

	/**
	 * Register the custom cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public static function add_cron_interval( array $schedules ): array {
		$schedules['zbooks_five_minutes'] = [
			'interval' => self::SEND_INTERVAL,
			'display'  => __( 'Every 5 Minutes', 'zbooks-for-woocommerce' ),
		];
		return $schedules;
	}
}
