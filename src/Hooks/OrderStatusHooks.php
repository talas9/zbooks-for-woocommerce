<?php
/**
 * Order status hooks.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Hooks;

use WC_Order;
use WC_Order_Refund;
use Zbooks\Service\SyncOrchestrator;

defined( 'ABSPATH' ) || exit;

/**
 * Handles WooCommerce order status change hooks.
 */
class OrderStatusHooks {

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
	 * Register WordPress hooks.
	 */
	private function register_hooks(): void {
		// Order status changes.
		add_action(
			'woocommerce_order_status_changed',
			[ $this, 'on_status_changed' ],
			10,
			4
		);

		// Payment applied (order completed).
		add_action(
			'woocommerce_order_status_completed',
			[ $this, 'on_order_completed' ],
			20,
			1
		);

		// Refund created.
		add_action(
			'woocommerce_order_refunded',
			[ $this, 'on_order_refunded' ],
			10,
			2
		);

		// Alternative: hook for individual refunds.
		add_action(
			'woocommerce_refund_created',
			[ $this, 'on_refund_created' ],
			10,
			2
		);

		add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_order_cancelled' ], 10, 1 );
	}

	/**
	 * Handle order status change.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Old status.
	 * @param string   $new_status New status.
	 * @param WC_Order $order      WooCommerce order.
	 */
	public function on_status_changed(
		int $order_id,
		string $old_status,
		string $new_status,
		WC_Order $order
	): void {
		// Get trigger configuration (action => status).
		$triggers = get_option(
			'zbooks_sync_triggers',
			[
				'sync_draft'        => 'processing',
				'sync_submit'       => 'completed',
				'create_creditnote' => 'refunded',
			]
		);

		// Find which action is configured for this status.
		$action = array_search( $new_status, $triggers, true );

		if ( $action === false ) {
			return;
		}

		// Credit note processing is handled by on_order_refunded hook.
		if ( $action === 'create_creditnote' ) {
			return;
		}

		$as_draft = $action === 'sync_draft';

		// Sync in background to avoid blocking the order update.
		$this->schedule_sync( $order_id, $as_draft );
	}

	/**
	 * Schedule sync to run in background.
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $as_draft Create as draft.
	 */
	private function schedule_sync( int $order_id, bool $as_draft ): void {
		// Use Action Scheduler if available (WooCommerce includes it).
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				'zbooks_sync_order',
				[
					'order_id' => $order_id,
					'as_draft' => $as_draft,
				],
				'zbooks'
			);
		} else {
			// Fallback: sync immediately.
			$this->execute_sync( $order_id, $as_draft );
		}
	}

	/**
	 * Execute sync (called by Action Scheduler or directly).
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $as_draft Create as draft.
	 */
	public function execute_sync( int $order_id, bool $as_draft ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$result = $this->orchestrator->sync_order( $order, $as_draft );

		// Store result in transient for admin notice.
		if ( $result->success ) {
			set_transient(
				'zbooks_sync_success_' . $order_id,
				$result->invoice_id,
				60
			);
		} else {
			set_transient(
				'zbooks_sync_error_' . $order_id,
				$result->error,
				60
			);
		}
	}

	/**
	 * Handle order completed status.
	 *
	 * Applies payment to the synced invoice.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_order_completed( int $order_id ): void {
		// Check if auto-payment is enabled.
		$settings = get_option(
			'zbooks_payment_settings',
			[
				'auto_apply_payment' => true,
			]
		);

		if ( empty( $settings['auto_apply_payment'] ) ) {
			return;
		}

		// Schedule payment application.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				'zbooks_apply_payment',
				[ 'order_id' => $order_id ],
				'zbooks'
			);
		} else {
			$this->execute_apply_payment( $order_id );
		}
	}

	/**
	 * Execute payment application.
	 *
	 * @param int $order_id Order ID.
	 */
	public function execute_apply_payment( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$this->orchestrator->apply_payment( $order );
	}

	/**
	 * Handle order refunded.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function on_order_refunded( int $order_id, int $refund_id ): void {
		// Check if credit note creation is enabled via sync triggers.
		$triggers = get_option(
			'zbooks_sync_triggers',
			[
				'sync_draft'        => 'processing',
				'sync_submit'       => 'completed',
				'create_creditnote' => 'refunded',
			]
		);

		// Skip if create_creditnote is disabled (empty status).
		if ( empty( $triggers['create_creditnote'] ) ) {
			return;
		}

		// Schedule refund processing.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				'zbooks_process_refund',
				[
					'order_id'  => $order_id,
					'refund_id' => $refund_id,
				],
				'zbooks'
			);
		} else {
			$this->execute_process_refund( $order_id, $refund_id );
		}
	}

	/**
	 * Handle refund created (alternative hook).
	 *
	 * @param int   $refund_id Refund ID.
	 * @param array $args      Refund arguments.
	 */
	public function on_refund_created( int $refund_id, array $args ): void {
		// The woocommerce_order_refunded hook is preferred.
		// This is a fallback for programmatic refunds.
	}

	/**
	 * Execute refund processing.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function execute_process_refund( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );

		if ( ! $order || ! $refund instanceof WC_Order_Refund ) {
			return;
		}

		$this->orchestrator->process_refund( $order, $refund );
	}

	/**
	 * Handle order cancellation.
	 *
	 * Voids the corresponding Zoho invoice if no payment has been applied.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function on_order_cancelled( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$invoice_id = $order->get_meta( '_zbooks_zoho_invoice_id' );
		if ( empty( $invoice_id ) ) {
			return; // Never synced.
		}

		// Check if invoice can be voided (no payments applied).
		$payment_id = $order->get_meta( '_zbooks_zoho_payment_id' );
		if ( ! empty( $payment_id ) ) {
			$order->add_order_note(
				__( 'Cannot void Zoho invoice - payment already applied. Manual void required in Zoho Books.', 'zbooks-for-woocommerce' )
			);
			return;
		}

		// Schedule void action via Action Scheduler if available.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				'zbooks_void_invoice',
				[ 'order_id' => $order_id ],
				'zbooks'
			);
		} else {
			// Void immediately if Action Scheduler not available.
			$this->void_invoice_for_order( $order );
		}
	}

	/**
	 * Void invoice for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	private function void_invoice_for_order( WC_Order $order ): void {
		$plugin = \Zbooks\Plugin::get_instance();
		$invoice_service = $plugin->get_service( 'invoice_service' );

		if ( ! $invoice_service ) {
			return;
		}

		$invoice_id = $order->get_meta( '_zbooks_zoho_invoice_id' );
		if ( empty( $invoice_id ) ) {
			return;
		}

		$result = $invoice_service->void_invoice( $invoice_id );

		if ( $result ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: Invoice ID */
					__( 'Zoho invoice %s has been voided due to order cancellation.', 'zbooks-for-woocommerce' ),
					$invoice_id
				)
			);
			$order->update_meta_data( '_zbooks_zoho_invoice_status', 'void' );
			$order->save();
		} else {
			$order->add_order_note(
				__( 'Failed to void Zoho invoice. Please void manually in Zoho Books.', 'zbooks-for-woocommerce' )
			);
		}
	}
}

// Register Action Scheduler callbacks.
add_action(
	'zbooks_sync_order',
	function ( int $order_id, bool $as_draft ) {
		$plugin = \Zbooks\Plugin::get_instance();
		$hooks  = $plugin->get_service( 'order_status_hooks' );
		$hooks->execute_sync( $order_id, $as_draft );
	},
	10,
	2
);

add_action(
	'zbooks_apply_payment',
	function ( int $order_id ) {
		$plugin = \Zbooks\Plugin::get_instance();
		$hooks  = $plugin->get_service( 'order_status_hooks' );
		$hooks->execute_apply_payment( $order_id );
	},
	10,
	1
);

add_action(
	'zbooks_process_refund',
	function ( int $order_id, int $refund_id ) {
		$plugin = \Zbooks\Plugin::get_instance();
		$hooks  = $plugin->get_service( 'order_status_hooks' );
		$hooks->execute_process_refund( $order_id, $refund_id );
	},
	10,
	2
);

add_action(
	'zbooks_void_invoice',
	function ( int $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$plugin = \Zbooks\Plugin::get_instance();
		$invoice_service = $plugin->get_service( 'invoice_service' );

		if ( ! $invoice_service ) {
			return;
		}

		$invoice_id = $order->get_meta( '_zbooks_zoho_invoice_id' );
		if ( empty( $invoice_id ) ) {
			return;
		}

		$result = $invoice_service->void_invoice( $invoice_id );

		if ( $result ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: Invoice ID */
					__( 'Zoho invoice %s has been voided due to order cancellation.', 'zbooks-for-woocommerce' ),
					$invoice_id
				)
			);
			$order->update_meta_data( '_zbooks_zoho_invoice_status', 'void' );
			$order->save();
		} else {
			$order->add_order_note(
				__( 'Failed to void Zoho invoice. Please void manually in Zoho Books.', 'zbooks-for-woocommerce' )
			);
		}
	},
	10,
	1
);
