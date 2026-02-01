<?php
/**
 * AJAX handlers.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Hooks;

use Zbooks\Service\SyncOrchestrator;

defined( 'ABSPATH' ) || exit;

/**
 * Handles AJAX requests for manual sync.
 */
class AjaxHandlers {

	/**
	 * Sync orchestrator.
	 *
	 * @var SyncOrchestrator
	 */
	private SyncOrchestrator $orchestrator;

	/**
	 * Constructor.
	 *
	 * @param SyncOrchestrator $orchestrator Sync orchestrator.
	 */
	public function __construct( SyncOrchestrator $orchestrator ) {
		$this->orchestrator = $orchestrator;
		$this->register_hooks();
	}

	/**
	 * Register AJAX hooks.
	 */
	private function register_hooks(): void {
		add_action( 'wp_ajax_zbooks_manual_sync', [ $this, 'handle_manual_sync' ] );
		add_action( 'wp_ajax_zbooks_bulk_sync', [ $this, 'handle_bulk_sync' ] );
		add_action( 'wp_ajax_zbooks_bulk_sync_date_range', [ $this, 'handle_bulk_sync_date_range' ] );
		add_action( 'wp_ajax_zbooks_get_orders_by_date', [ $this, 'handle_get_orders_by_date' ] );
		add_action( 'wp_ajax_zbooks_test_connection', [ $this, 'handle_test_connection' ] );
		add_action( 'wp_ajax_zbooks_apply_payment', [ $this, 'handle_apply_payment' ] );
		add_action( 'wp_ajax_zbooks_refresh_bank_accounts', [ $this, 'handle_refresh_bank_accounts' ] );
		add_action( 'wp_ajax_zbooks_refresh_invoice_status', [ $this, 'handle_refresh_invoice_status' ] );
	}

	/**
	 * Handle manual sync AJAX request.
	 *
	 * Uses status mappings to determine sync behavior unless explicitly overridden.
	 */
	public function handle_manual_sync(): void {
		check_ajax_referer( 'zbooks_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_send_json_error(
				[
					'message' => __( 'Invalid order ID.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error(
				[
					'message' => __( 'Order not found.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		// Determine sync behavior based on status mappings.
		$sync_config = $this->get_sync_config_for_order( $order );
		$as_draft    = $sync_config['as_draft'];

		// Manual sync always uses force=true to actually re-sync (not just return cached result).
		$result = $this->orchestrator->sync_order( $order, $as_draft, true );

		if ( ! $result->success ) {
			wp_send_json_error(
				[
					'message' => $result->error ?? __( 'Sync failed.', 'zbooks-for-woocommerce' ),
				]
			);
			return;
		}

		// Apply payment if order status matches apply_payment mapping and order is paid.
		$payment_result = null;
		if ( $sync_config['should_apply_payment'] && ! $as_draft && $order->is_paid() ) {
			$payment_result = $this->orchestrator->apply_payment( $order );
		}

		// Get repository to fetch display names.
		$repository = new \Zbooks\Repository\OrderMetaRepository();

		// Get last attempt timestamp.
		$last_attempt = $repository->get_last_sync_attempt( $order );

		$response = [
			'message'         => __( 'Order synced successfully!', 'zbooks-for-woocommerce' ),
			'invoice_id'      => $result->invoice_id,
			'invoice_number'  => $repository->get_invoice_number( $order ),
			'invoice_url'     => \Zbooks\Helper\ZohoUrlHelper::invoice( $result->invoice_id ),
			'contact_id'      => $result->contact_id,
			'contact_name'    => $repository->get_contact_name( $order ),
			'contact_url'     => $result->contact_id ? \Zbooks\Helper\ZohoUrlHelper::contact( $result->contact_id ) : null,
			'status'          => $result->status->value,
			'status_label'    => $result->status->label(),
			'status_class'    => $result->status->css_class(),
			'payment_id'      => $repository->get_payment_id( $order ),
			'payment_number'  => $repository->get_payment_number( $order ),
			'invoice_status'  => $repository->get_invoice_status( $order ),
			'last_attempt'    => $last_attempt ? $last_attempt->format( 'Y-m-d H:i:s' ) : null,
		];

		// Add payment result info if payment was attempted.
		if ( $payment_result !== null ) {
			if ( $payment_result['success'] ) {
				$response['message']        = __( 'Order synced and payment applied!', 'zbooks-for-woocommerce' );
				$response['payment_id']     = $payment_result['payment_id'] ?? $repository->get_payment_id( $order );
				$response['payment_number'] = $repository->get_payment_number( $order );
			} else {
				$response['payment_warning'] = $payment_result['error'] ?? __( 'Payment could not be applied.', 'zbooks-for-woocommerce' );
			}
		}

		wp_send_json_success( $response );
	}

	/**
	 * Get sync configuration for an order based on status mappings.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return array{as_draft: bool, should_apply_payment: bool}
	 */
	private function get_sync_config_for_order( \WC_Order $order ): array {
		$triggers = get_option( 'zbooks_sync_triggers', [] );

		// Use sensible defaults if not configured.
		if ( empty( $triggers ) ) {
			$triggers = [
				'sync_draft'        => 'processing',
				'sync_submit'       => 'completed',
				'apply_payment'     => 'completed',
				'create_creditnote' => 'refunded',
			];
		}

		$order_status = $order->get_status();

		// Check if order status matches sync_draft or sync_submit.
		$as_draft = true; // Default to draft for safety.
		if ( isset( $triggers['sync_submit'] ) && $triggers['sync_submit'] === $order_status ) {
			$as_draft = false;
		} elseif ( isset( $triggers['sync_draft'] ) && $triggers['sync_draft'] === $order_status ) {
			$as_draft = true;
		}

		// Check if payment should be applied.
		$should_apply_payment = false;
		if ( isset( $triggers['apply_payment'] ) && $triggers['apply_payment'] === $order_status ) {
			$should_apply_payment = true;
		}

		return [
			'as_draft'             => $as_draft,
			'should_apply_payment' => $should_apply_payment,
		];
	}

	/**
	 * Handle bulk sync AJAX request.
	 *
	 * Each order is synced according to trigger settings.
	 */
	public function handle_bulk_sync(): void {
		check_ajax_referer( 'zbooks_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$order_ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : [];

		if ( empty( $order_ids ) ) {
			wp_send_json_error(
				[
					'message' => __( 'No orders selected.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$plugin       = \Zbooks\Plugin::get_instance();
		$bulk_service = $plugin->get_service( 'bulk_sync_service' );

		$results = $bulk_service->sync_orders( $order_ids );

		wp_send_json_success(
			[
				'message'       => sprintf(
					/* translators: 1: Success count, 2: Failed count */
					__( 'Synced %1$d orders, %2$d failed.', 'zbooks-for-woocommerce' ),
					$results['success'],
					$results['failed']
				),
				'success_count' => $results['success'],
				'failed_count'  => $results['failed'],
				'results'       => $results['results'],
			]
		);
	}

	/**
	 * Handle connection test AJAX request.
	 */
	public function handle_test_connection(): void {
		check_ajax_referer( 'zbooks_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$plugin      = \Zbooks\Plugin::get_instance();
		$zoho_client = $plugin->get_service( 'zoho_client' );

		try {
			$success = $zoho_client->test_connection();

			// Update connection health cache.
			set_transient( 'zbooks_connection_healthy', $success ? 'yes' : 'no', 5 * MINUTE_IN_SECONDS );

			if ( $success ) {
				wp_send_json_success(
					[
						'message' => __( 'Connection successful!', 'zbooks-for-woocommerce' ),
					]
				);
			} else {
				wp_send_json_error(
					[
						'message' => __( 'Connection failed. Please check your credentials.', 'zbooks-for-woocommerce' ),
					]
				);
			}
		} catch ( \Exception $e ) {
			wp_send_json_error(
				[
					'message' => $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Handle apply payment AJAX request.
	 */
	public function handle_apply_payment(): void {
		check_ajax_referer( 'zbooks_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_send_json_error(
				[
					'message' => __( 'Invalid order ID.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error(
				[
					'message' => __( 'Order not found.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$result = $this->orchestrator->apply_payment( $order );

		if ( $result['success'] ) {
			// Get repository to fetch display names and updated data.
			$repository   = new \Zbooks\Repository\OrderMetaRepository();
			$last_attempt = $repository->get_last_sync_attempt( $order );
			
			wp_send_json_success(
				[
					'message'        => __( 'Payment applied successfully!', 'zbooks-for-woocommerce' ),
					'payment_id'     => $result['payment_id'] ?? null,
					'payment_number' => $repository->get_payment_number( $order ),
					'last_attempt'   => $last_attempt ? $last_attempt->format( 'Y-m-d H:i:s' ) : null,
				]
			);
		} else {
			wp_send_json_error(
				[
					'message' => $result['error'] ?? __( 'Failed to apply payment.', 'zbooks-for-woocommerce' ),
				]
			);
		}
	}

	/**
	 * Handle refresh bank accounts AJAX request.
	 */
	public function handle_refresh_bank_accounts(): void {
		check_ajax_referer( 'zbooks_refresh_accounts', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		// Clear the cached bank accounts.
		delete_transient( 'zbooks_zoho_bank_accounts' );

		wp_send_json_success(
			[
				'message' => __( 'Bank accounts refreshed.', 'zbooks-for-woocommerce' ),
			]
		);
	}

	/**
	 * Handle refresh invoice status AJAX request.
	 */
	public function handle_refresh_invoice_status(): void {
		check_ajax_referer( 'zbooks_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_send_json_error(
				[
					'message' => __( 'Invalid order ID.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error(
				[
					'message' => __( 'Order not found.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$status = $this->orchestrator->refresh_invoice_status( $order );

		if ( $status !== null ) {
			wp_send_json_success(
				[
					'message' => __( 'Invoice status refreshed.', 'zbooks-for-woocommerce' ),
					'status'  => $status,
					'label'   => ucwords( str_replace( '_', ' ', $status ) ),
				]
			);
		} else {
			wp_send_json_error(
				[
					'message' => __( 'Could not refresh status. Order may not be synced.', 'zbooks-for-woocommerce' ),
				]
			);
		}
	}

	/**
	 * Handle bulk sync by date range AJAX request.
	 *
	 * Each order is synced according to trigger settings.
	 */
	public function handle_bulk_sync_date_range(): void {
		check_ajax_referer( 'zbooks_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : '';
		$date_to   = isset( $_POST['date_to'] ) ? sanitize_text_field( $_POST['date_to'] ) : '';

		if ( empty( $date_from ) || empty( $date_to ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Please select a date range.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$plugin       = \Zbooks\Plugin::get_instance();
		$bulk_service = $plugin->get_service( 'bulk_sync_service' );

		$results = $bulk_service->sync_date_range( $date_from, $date_to );

		wp_send_json_success(
			[
				'message'       => sprintf(
					/* translators: 1: Success count, 2: Failed count */
					__( 'Synced %1$d orders, %2$d failed.', 'zbooks-for-woocommerce' ),
					$results['success'],
					$results['failed']
				),
				'success_count' => $results['success'],
				'failed_count'  => $results['failed'],
				'results'       => $results['results'],
			]
		);
	}

	/**
	 * Handle get orders by date range AJAX request.
	 */
	public function handle_get_orders_by_date(): void {
		check_ajax_referer( 'zbooks_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$date_from    = isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : '';
		$date_to      = isset( $_POST['date_to'] ) ? sanitize_text_field( $_POST['date_to'] ) : '';
		$order_status = isset( $_POST['order_status'] ) && is_array( $_POST['order_status'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['order_status'] ) )
			: [ 'all' ];

		if ( empty( $date_from ) || empty( $date_to ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Please select a date range.', 'zbooks-for-woocommerce' ),
				]
			);
		}

		$plugin       = \Zbooks\Plugin::get_instance();
		$bulk_service = $plugin->get_service( 'bulk_sync_service' );

		$orders = $bulk_service->get_syncable_orders( $date_from, $date_to, 500, $order_status );

		$order_data = [];
		foreach ( $orders as $order ) {
			$order_data[] = [
				'id'       => $order->get_id(),
				'number'   => $order->get_order_number(),
				'total'    => $order->get_total(),
				'customer' => $order->get_formatted_billing_full_name(),
			];
		}

		wp_send_json_success(
			[
				'orders' => $order_data,
				'count'  => count( $order_data ),
			]
		);
	}
}
