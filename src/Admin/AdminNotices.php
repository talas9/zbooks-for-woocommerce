<?php
/**
 * Admin notices.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin notices for sync results.
 */
class AdminNotices {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_notices', [ $this, 'display_notices' ] );
	}

	/**
	 * Display admin notices.
	 */
	public function display_notices(): void {
		$this->display_configuration_notice();
		$this->display_sync_notices();
	}

	/**
	 * Display configuration notice if not configured.
	 */
	private function display_configuration_notice(): void {
		// Only show on WooCommerce pages.
		$screen = get_current_screen();
		if ( ! $screen || ! $screen->id || strpos( $screen->id, 'woocommerce' ) === false ) {
			return;
		}

		$plugin        = \Zbooks\Plugin::get_instance();
		$token_manager = $plugin->get_service( 'token_manager' );

		if ( ! $token_manager->has_credentials() ) {
			?>
			<div class="notice notice-warning">
				<p>
					<?php
					printf(
						/* translators: %s: Settings page URL */
						esc_html__( 'ZBooks for WooCommerce is not configured. Please %s to set up Zoho Books integration.', 'zbooks-for-woocommerce' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=zbooks-settings' ) ) . '">' .
						esc_html__( 'visit the settings page', 'zbooks-for-woocommerce' ) .
						'</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Display sync result notices.
	 */
	private function display_sync_notices(): void {
		// Check for order-specific notices.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display
		$order_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( ! $order_id ) {
			return;
		}

		$success = get_transient( 'zbooks_sync_success_' . $order_id );
		$error   = get_transient( 'zbooks_sync_error_' . $order_id );

		if ( $success ) {
			delete_transient( 'zbooks_sync_success_' . $order_id );
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %s: Invoice ID */
						esc_html__( 'Order synced to Zoho Books successfully! Invoice ID: %s', 'zbooks-for-woocommerce' ),
						'<strong>' . esc_html( $success ) . '</strong>'
					);
					?>
				</p>
			</div>
			<?php
		}

		if ( $error ) {
			delete_transient( 'zbooks_sync_error_' . $order_id );
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %s: Error message */
						esc_html__( 'Failed to sync order to Zoho Books: %s', 'zbooks-for-woocommerce' ),
						esc_html( $error )
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Add a success notice.
	 *
	 * @param string $message Notice message.
	 */
	public static function add_success( string $message ): void {
		set_transient( 'zbooks_notice_success', $message, 60 );
	}

	/**
	 * Add an error notice.
	 *
	 * @param string $message Notice message.
	 */
	public static function add_error( string $message ): void {
		set_transient( 'zbooks_notice_error', $message, 60 );
	}
}
