<?php
/**
 * Sync orchestrator service.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

use WC_Order;
use WC_Order_Refund;
use Zbooks\Logger\SyncLogger;
use Zbooks\Model\SyncResult;
use Zbooks\Model\SyncStatus;
use Zbooks\Repository\OrderMetaRepository;

defined( 'ABSPATH' ) || exit;


/**
 * Orchestrates the full sync workflow.
 */
class SyncOrchestrator {

	/**
	 * Customer service.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customer_service;

	/**
	 * Invoice service.
	 *
	 * @var InvoiceService
	 */
	private InvoiceService $invoice_service;

	/**
	 * Payment service.
	 *
	 * @var PaymentService|null
	 */
	private ?PaymentService $payment_service = null;

	/**
	 * Refund service.
	 *
	 * @var RefundService|null
	 */
	private ?RefundService $refund_service = null;

	/**
	 * Order meta repository.
	 *
	 * @var OrderMetaRepository
	 */
	private OrderMetaRepository $repository;

	/**
	 * Logger.
	 *
	 * @var SyncLogger
	 */
	private SyncLogger $logger;

	/**
	 * Order note service.
	 *
	 * @var OrderNoteService
	 */
	private OrderNoteService $note_service;

	/**
	 * Constructor.
	 *
	 * @param CustomerService       $customer_service Customer service.
	 * @param InvoiceService        $invoice_service  Invoice service.
	 * @param OrderMetaRepository   $repository       Order meta repository.
	 * @param SyncLogger            $logger           Logger.
	 * @param PaymentService|null   $payment_service  Payment service.
	 * @param RefundService|null    $refund_service   Refund service.
	 * @param OrderNoteService|null $note_service    Order note service.
	 */
	public function __construct(
		CustomerService $customer_service,
		InvoiceService $invoice_service,
		OrderMetaRepository $repository,
		SyncLogger $logger,
		?PaymentService $payment_service = null,
		?RefundService $refund_service = null,
		?OrderNoteService $note_service = null
	) {
		$this->customer_service = $customer_service;
		$this->invoice_service  = $invoice_service;
		$this->repository       = $repository;
		$this->logger           = $logger;
		$this->payment_service  = $payment_service;
		$this->refund_service   = $refund_service;
		$this->note_service     = $note_service ?? new OrderNoteService();
	}

	/**
	 * Sync an order to Zoho Books.
	 *
	 * @param WC_Order $order    WooCommerce order.
	 * @param bool     $as_draft Create invoice as draft.
	 * @return SyncResult
	 */
	public function sync_order( WC_Order $order, bool $as_draft = false ): SyncResult {
		$order_id     = $order->get_id();
		$order_number = $order->get_order_number();

		$this->logger->info(
			'Starting sync for order',
			[
				'order_id'     => $order_id,
				'order_number' => $order_number,
				'status'       => $order->get_status(),
				'as_draft'     => $as_draft,
			]
		);

		// Check if already synced.
		$existing_invoice_id = $this->repository->get_invoice_id( $order );
		if ( $existing_invoice_id !== null ) {
			$this->logger->debug(
				'Order already synced',
				[
					'order_id'     => $order_id,
					'order_number' => $order_number,
					'invoice_id'   => $existing_invoice_id,
				]
			);

			return SyncResult::success(
				invoice_id: $existing_invoice_id,
				contact_id: $this->repository->get_contact_id( $order ),
				status: $this->repository->get_sync_status( $order ) ?? SyncStatus::SYNCED
			);
		}

		// Update status to pending.
		$this->repository->set_sync_status( $order, SyncStatus::PENDING );
		$this->repository->set_last_sync_attempt( $order );

		try {
			// Step 1: Find or create contact.
			$contact_id = $this->get_or_create_contact( $order );

			// Step 2: Get contact display name.
			$contact_name = $this->get_contact_display_name( $order, $contact_id );

			// Step 3: Create invoice.
			$result = $this->invoice_service->create_invoice( $order, $contact_id, $as_draft );

			// Step 4: Extract invoice number from response.
			$invoice_number = $this->extract_invoice_number( $result );

			// Step 5: Update order meta.
			$this->repository->update_sync_meta(
				order: $order,
				status: $result->status,
				invoice_id: $result->invoice_id,
				contact_id: $contact_id,
				error: $result->error,
				invoice_number: $invoice_number,
				contact_name: $contact_name
			);

			if ( $result->success ) {
				$this->logger->info(
					'Order synced successfully',
					[
						'order_id'       => $order_id,
						'order_number'   => $order_number,
						'invoice_id'     => $result->invoice_id,
						'invoice_number' => $invoice_number,
						'total'          => $order->get_total(),
					]
				);

				// Add order note with invoice link.
				if ( $result->invoice_id ) {
					$this->note_service->add_invoice_created_note(
						$order,
						$result->invoice_id,
						$result->status,
						$invoice_number
					);
				}

				/**
				 * Fires after an order is successfully synced to Zoho Books.
				 *
				 * @param WC_Order   $order  The WooCommerce order.
				 * @param SyncResult $result The sync result.
				 */
				do_action( 'zbooks_order_synced', $order, $result );
			} else {
				$this->logger->error(
					'Order sync failed',
					[
						'order_id'     => $order_id,
						'order_number' => $order_number,
						'email'        => $order->get_billing_email(),
						'error'        => $result->error,
					]
				);

				// Add order note about failure.
				if ( $result->error ) {
					$this->note_service->add_sync_failed_note( $order, $result->error );
				}

				/**
				 * Fires when an order sync fails.
				 *
				 * @param WC_Order   $order  The WooCommerce order.
				 * @param SyncResult $result The sync result.
				 */
				do_action( 'zbooks_order_sync_failed', $order, $result );
			}

			return $result;
		} catch ( \Exception $e ) {
			$error = $e->getMessage();

			$this->repository->update_sync_meta(
				order: $order,
				status: SyncStatus::FAILED,
				error: $error
			);

			$this->logger->error(
				'Order sync exception',
				[
					'order_id'     => $order_id,
					'order_number' => $order_number,
					'email'        => $order->get_billing_email(),
					'error'        => $error,
				]
			);

			// Add order note about failure.
			$this->note_service->add_sync_failed_note( $order, $error );

			$result = SyncResult::failure( $error );

			do_action( 'zbooks_order_sync_failed', $order, $result );

			return $result;
		}
	}

	/**
	 * Get or create contact for order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string Contact ID.
	 * @throws \RuntimeException If contact creation fails.
	 */
	private function get_or_create_contact( WC_Order $order ): string {
		// Check if we already have a contact ID.
		$existing_contact_id = $this->repository->get_contact_id( $order );
		if ( $existing_contact_id !== null ) {
			return $existing_contact_id;
		}

		return $this->customer_service->find_or_create_contact( $order );
	}

	/**
	 * Get contact display name for an order.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $contact_id Zoho contact ID.
	 * @return string|null Contact display name or null.
	 */
	private function get_contact_display_name( WC_Order $order, string $contact_id ): ?string {
		// First try to get from existing meta.
		$existing_name = $this->repository->get_contact_name( $order );
		if ( $existing_name !== null ) {
			return $existing_name;
		}

		// Fetch from Zoho.
		$contact = $this->customer_service->get_contact( $contact_id );
		if ( $contact ) {
			return $contact['contact_name'] ?? $contact['company_name'] ?? null;
		}

		return null;
	}

	/**
	 * Extract invoice number from sync result.
	 *
	 * @param SyncResult $result Sync result.
	 * @return string|null Invoice number or null.
	 */
	private function extract_invoice_number( SyncResult $result ): ?string {
		$data = $result->data;

		// Handle nested 'invoice' response structure.
		$invoice_data = $data['invoice'] ?? $data;

		return $invoice_data['invoice_number'] ?? null;
	}

	/**
	 * Retry sync for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return SyncResult
	 */
	public function retry_sync( WC_Order $order ): SyncResult {
		// Clear previous error.
		$this->repository->clear_sync_error( $order );

		// Increment retry count.
		$retry_count = $this->repository->increment_retry_count( $order );

		$this->logger->info(
			'Retrying sync',
			[
				'order_id'    => $order->get_id(),
				'retry_count' => $retry_count,
			]
		);

		// Check if draft or submit based on current order status.
		$as_draft = $this->should_create_as_draft( $order );

		return $this->sync_order( $order, $as_draft );
	}

	/**
	 * Determine if invoice should be created as draft.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function should_create_as_draft( WC_Order $order ): bool {
		$triggers = get_option(
			'zbooks_sync_triggers',
			[
				'sync_draft'        => 'processing',
				'sync_submit'       => 'completed',
				'create_creditnote' => 'refunded',
			]
		);
		$status   = $order->get_status();

		// Check if sync_draft is configured for this status.
		if ( isset( $triggers['sync_draft'] ) && $triggers['sync_draft'] === $status ) {
			return true;
		}

		// Check if sync_submit is configured for this status.
		if ( isset( $triggers['sync_submit'] ) && $triggers['sync_submit'] === $status ) {
			return false;
		}

		return true; // Default to draft.
	}

	/**
	 * Check if order can be retried.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	public function can_retry( WC_Order $order ): bool {
		$status = $this->repository->get_sync_status( $order );

		if ( $status !== SyncStatus::FAILED ) {
			return false;
		}

		$settings = get_option(
			'zbooks_retry_settings',
			[
				'mode'      => 'max_retries',
				'max_count' => 5,
			]
		);

		if ( $settings['mode'] === 'manual' ) {
			return false;
		}

		if ( $settings['mode'] === 'max_retries' ) {
			$retry_count = $this->repository->get_retry_count( $order );
			return $retry_count < (int) $settings['max_count'];
		}

		return true; // Indefinite mode.
	}

	/**
	 * Get seconds to wait before next retry (exponential backoff).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int Seconds to wait.
	 */
	public function get_retry_delay( WC_Order $order ): int {
		$settings = get_option(
			'zbooks_retry_settings',
			[
				'backoff_minutes' => 15,
			]
		);

		$retry_count = $this->repository->get_retry_count( $order );
		$base_delay  = (int) $settings['backoff_minutes'] * 60;

		// Exponential backoff: 15min, 30min, 1hr, 2hr, 4hr, ...
		return $base_delay * pow( 2, $retry_count );
	}

	/**
	 * Check for conflicts before syncing.
	 *
	 * Detects if an invoice already exists in Zoho Books for this order,
	 * and checks for currency mismatches with existing contacts.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array{has_conflict: bool, invoice_id: ?string, contact_id: ?string, currency_mismatch: bool}
	 */
	public function detect_conflicts( WC_Order $order ): array {
		$order_number = $order->get_order_number();
		$email        = $order->get_billing_email();

		$result = [
			'has_conflict'      => false,
			'invoice_id'        => null,
			'contact_id'        => null,
			'invoice_exists'    => false,
			'contact_exists'    => false,
			'currency_mismatch' => false,
			'contact_currency'  => null,
			'order_currency'    => $order->get_currency(),
		];

		// Check for existing invoice in Zoho by reference number.
		$existing_invoice_id = $this->invoice_service->find_invoice_by_reference( $order_number );
		if ( $existing_invoice_id !== null ) {
			$result['has_conflict']   = true;
			$result['invoice_id']     = $existing_invoice_id;
			$result['invoice_exists'] = true;

			$this->logger->info(
				'Conflict detected: Invoice already exists in Zoho',
				[
					'order_id'     => $order->get_id(),
					'order_number' => $order_number,
					'invoice_id'   => $existing_invoice_id,
				]
			);
		}

		// Check for existing contact in Zoho by email.
		if ( ! empty( $email ) ) {
			$existing_contact = $this->customer_service->get_contact_by_email( $email );
			if ( $existing_contact !== null ) {
				$result['contact_id']     = (string) $existing_contact['contact_id'];
				$result['contact_exists'] = true;

				$this->logger->debug(
					'Existing contact found in Zoho',
					[
						'email'      => $email,
						'contact_id' => $result['contact_id'],
					]
				);

				// Check currency compatibility.
				$currency_check = $this->customer_service->check_currency_compatibility( $existing_contact, $order );
				if ( ! $currency_check['compatible'] ) {
					$result['has_conflict']      = true;
					$result['currency_mismatch'] = true;
					$result['contact_currency']  = $currency_check['contact_currency'];

					$this->logger->warning(
						'Conflict detected: Currency mismatch',
						[
							'order_id'         => $order->get_id(),
							'email'            => $email,
							'contact_currency' => $currency_check['contact_currency'],
							'order_currency'   => $currency_check['order_currency'],
						]
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Sync order with conflict resolution.
	 *
	 * If invoice already exists, links it instead of creating a duplicate.
	 *
	 * @param WC_Order $order    WooCommerce order.
	 * @param bool     $as_draft Create invoice as draft.
	 * @return SyncResult
	 */
	public function sync_order_with_conflict_check( WC_Order $order, bool $as_draft = false ): SyncResult {
		$order_id = $order->get_id();

		// Check if already synced locally.
		$existing_invoice_id = $this->repository->get_invoice_id( $order );
		if ( $existing_invoice_id !== null ) {
			return SyncResult::success(
				invoice_id: $existing_invoice_id,
				contact_id: $this->repository->get_contact_id( $order ),
				status: $this->repository->get_sync_status( $order ) ?? SyncStatus::SYNCED
			);
		}

		// Detect conflicts with Zoho.
		$conflicts = $this->detect_conflicts( $order );

		if ( $conflicts['has_conflict'] && $conflicts['invoice_id'] !== null ) {
			// Invoice exists in Zoho - link it to this order.
			$this->logger->info(
				'Linking existing Zoho invoice to order',
				[
					'order_id'   => $order_id,
					'invoice_id' => $conflicts['invoice_id'],
				]
			);

			$contact_id = $conflicts['contact_id'] ?? '';

			// Update local meta to link the existing invoice.
			$this->repository->update_sync_meta(
				order: $order,
				status: SyncStatus::SYNCED,
				invoice_id: $conflicts['invoice_id'],
				contact_id: $contact_id
			);

			// Add order note about linking existing invoice.
			$this->note_service->add_invoice_linked_note( $order, $conflicts['invoice_id'] );

			return SyncResult::success(
				invoice_id: $conflicts['invoice_id'],
				contact_id: $contact_id,
				status: SyncStatus::SYNCED,
				data: [
					'conflict_resolved' => true,
					'linked_existing'   => true,
				]
			);
		}

		// No conflict, proceed with normal sync.
		return $this->sync_order( $order, $as_draft );
	}

	/**
	 * Apply payment to an order's invoice.
	 *
	 * Called when order status changes to completed/paid.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array{success: bool, payment_id: ?string, error: ?string}
	 */
	public function apply_payment( WC_Order $order ): array {
		$order_id = $order->get_id();

		// Check if payment service is available.
		if ( ! $this->payment_service ) {
			$this->logger->warning(
				'Payment service not available',
				[
					'order_id' => $order_id,
				]
			);
			return [
				'success'    => false,
				'payment_id' => null,
				'error'      => __( 'Payment service not configured', 'zbooks-for-woocommerce' ),
			];
		}

		// Check if order has been synced.
		$invoice_id = $this->repository->get_invoice_id( $order );
		if ( ! $invoice_id ) {
			$this->logger->warning(
				'Cannot apply payment - order not synced',
				[
					'order_id' => $order_id,
				]
			);
			return [
				'success'    => false,
				'payment_id' => null,
				'error'      => __( 'Order has not been synced to Zoho Books', 'zbooks-for-woocommerce' ),
			];
		}

		// Check if payment already recorded.
		if ( $this->repository->has_payment( $order ) ) {
			$existing_payment_id = $this->repository->get_payment_id( $order );
			$this->logger->debug(
				'Payment already recorded',
				[
					'order_id'   => $order_id,
					'payment_id' => $existing_payment_id,
				]
			);
			return [
				'success'    => true,
				'payment_id' => $existing_payment_id,
				'error'      => null,
			];
		}

		$contact_id = $this->repository->get_contact_id( $order ) ?? '';

		// Apply payment.
		$result = $this->payment_service->apply_payment( $order, $invoice_id, $contact_id );

		if ( $result['success'] && $result['payment_id'] ) {
			$this->repository->set_payment_id( $order, $result['payment_id'] );

			// Save payment number if available.
			$payment_number = $result['payment_number'] ?? null;
			if ( $payment_number ) {
				$this->repository->set_payment_number( $order, $payment_number );
			}

			// Get invoice number for the note.
			$invoice_number = $this->repository->get_invoice_number( $order );

			// Add order note about payment.
			$this->note_service->add_payment_applied_note(
				$order,
				$result['payment_id'],
				$invoice_id,
				$payment_number,
				$invoice_number
			);

			/**
			 * Fires after a payment is successfully applied in Zoho Books.
			 *
			 * @param WC_Order $order      The WooCommerce order.
			 * @param string   $payment_id The Zoho payment ID.
			 */
			do_action( 'zbooks_payment_applied', $order, $result['payment_id'] );
		}

		return $result;
	}

	/**
	 * Process a refund for an order.
	 *
	 * Called when a WooCommerce refund is created.
	 *
	 * @param WC_Order        $order  WooCommerce order.
	 * @param WC_Order_Refund $refund WooCommerce refund.
	 * @return array{success: bool, credit_note_id: ?string, refund_id: ?string, error: ?string}
	 */
	public function process_refund( WC_Order $order, WC_Order_Refund $refund ): array {
		$order_id  = $order->get_id();
		$refund_id = $refund->get_id();

		// Check if refund service is available.
		if ( ! $this->refund_service ) {
			$this->logger->warning(
				'Refund service not available',
				[
					'order_id'  => $order_id,
					'refund_id' => $refund_id,
				]
			);
			return [
				'success'        => false,
				'credit_note_id' => null,
				'refund_id'      => null,
				'error'          => __( 'Refund service not configured', 'zbooks-for-woocommerce' ),
			];
		}

		// Check if order has been synced.
		$invoice_id = $this->repository->get_invoice_id( $order );
		if ( ! $invoice_id ) {
			$this->logger->warning(
				'Cannot process refund - order not synced',
				[
					'order_id'  => $order_id,
					'refund_id' => $refund_id,
				]
			);
			return [
				'success'        => false,
				'credit_note_id' => null,
				'refund_id'      => null,
				'error'          => __( 'Order has not been synced to Zoho Books', 'zbooks-for-woocommerce' ),
			];
		}

		// Check if this refund was already processed.
		$existing = $this->repository->get_zoho_refund_for_wc_refund( $order, $refund_id );
		if ( $existing ) {
			$this->logger->debug(
				'Refund already processed',
				[
					'order_id'       => $order_id,
					'refund_id'      => $refund_id,
					'zoho_refund_id' => $existing['zoho_refund_id'],
				]
			);
			return [
				'success'        => true,
				'credit_note_id' => $existing['zoho_credit_note_id'] ?? null,
				'refund_id'      => $existing['zoho_refund_id'],
				'error'          => null,
			];
		}

		$contact_id = $this->repository->get_contact_id( $order ) ?? '';

		// Process refund.
		$result = $this->refund_service->process_refund( $order, $refund, $invoice_id, $contact_id );

		if ( $result['success'] ) {
			// Record the refund mapping.
			$this->repository->add_refund_id(
				$order,
				$refund_id,
				$result['refund_id'] ?? '',
				$result['credit_note_id'] ?? '',
				$result['credit_note_number'] ?? ''
			);

			// Add order note about credit note.
			if ( ! empty( $result['credit_note_id'] ) ) {
				$refund_amount = abs( (float) $refund->get_total() );
				$this->note_service->add_credit_note_created_note(
					$order,
					$result['credit_note_id'],
					$refund_amount,
					$result['refund_id'] ?? null,
					$result['credit_note_number'] ?? null
				);
			}

			/**
			 * Fires after a refund is successfully processed in Zoho Books.
			 *
			 * @param WC_Order        $order  The WooCommerce order.
			 * @param WC_Order_Refund $refund The WooCommerce refund.
			 * @param array           $result The refund result.
			 */
			do_action( 'zbooks_refund_processed', $order, $refund, $result );
		}

		return $result;
	}

	/**
	 * Sync order and apply payment if order is paid.
	 *
	 * Convenience method that syncs the order and applies payment in one call.
	 *
	 * @param WC_Order $order       WooCommerce order.
	 * @param bool     $as_draft    Create invoice as draft.
	 * @param bool     $with_payment Apply payment if order is paid.
	 * @return SyncResult
	 */
	public function sync_order_with_payment(
		WC_Order $order,
		bool $as_draft = false,
		bool $with_payment = true
	): SyncResult {
		// First sync the order.
		$result = $this->sync_order( $order, $as_draft );

		if ( ! $result->success ) {
			return $result;
		}

		// Apply payment if order is paid and not a draft.
		if ( $with_payment && ! $as_draft && $order->is_paid() ) {
			$payment_result = $this->apply_payment( $order );

			if ( $payment_result['success'] && $payment_result['payment_id'] ) {
				// Include payment ID in result data.
				$data               = $result->data ?? [];
				$data['payment_id'] = $payment_result['payment_id'];

				return SyncResult::success(
					invoice_id: $result->invoice_id,
					contact_id: $result->contact_id,
					status: $result->status,
					data: $data
				);
			}
		}

		return $result;
	}
}
