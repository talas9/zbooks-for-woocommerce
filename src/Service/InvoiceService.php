<?php
/**
 * Invoice service for Zoho Books.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

use WC_Order;
use WC_Order_Item_Product;
use Zbooks\Api\ZohoClient;
use Zbooks\Helper\SyncMetadataHelper;
use Zbooks\Logger\SyncLogger;
use Zbooks\Model\SyncResult;
use Zbooks\Model\SyncStatus;
use Zbooks\Repository\ItemMappingRepository;
use Zbooks\Repository\FieldMappingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Service for creating Zoho Books invoices.
 */
class InvoiceService {

	/**
	 * Zoho client.
	 *
	 * @var ZohoClient
	 */
	private ZohoClient $client;

	/**
	 * Logger.
	 *
	 * @var SyncLogger
	 */
	private SyncLogger $logger;

	/**
	 * Item mapping repository.
	 *
	 * @var ItemMappingRepository
	 */
	private ItemMappingRepository $item_mapping;

	/**
	 * Field mapping repository.
	 *
	 * @var FieldMappingRepository
	 */
	private FieldMappingRepository $field_mapping;

	/**
	 * Constructor.
	 *
	 * @param ZohoClient             $client        Zoho client instance.
	 * @param SyncLogger             $logger        Logger instance.
	 * @param ItemMappingRepository  $item_mapping  Item mapping repository.
	 * @param FieldMappingRepository $field_mapping Field mapping repository.
	 */
	public function __construct(
		ZohoClient $client,
		SyncLogger $logger,
		?ItemMappingRepository $item_mapping = null,
		?FieldMappingRepository $field_mapping = null
	) {
		$this->client        = $client;
		$this->logger        = $logger;
		$this->item_mapping  = $item_mapping ?? new ItemMappingRepository();
		$this->field_mapping = $field_mapping ?? new FieldMappingRepository();
	}

	/**
	 * Create an invoice for a WooCommerce order.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $contact_id Zoho contact ID.
	 * @param bool     $as_draft   Create as draft (default: false).
	 * @return SyncResult
	 */
	public function create_invoice(
		WC_Order $order,
		string $contact_id,
		bool $as_draft = false
	): SyncResult {
		$order_number = $order->get_order_number();

		// Check for existing invoice by order number (checks both reference_number and invoice_number).
		$existing_id = $this->find_invoice_by_order_number( $order_number );
		if ( ! empty( $existing_id ) ) {
			// Verify the existing invoice belongs to the same customer to avoid collision.
			$existing_invoice     = $this->get_invoice( $existing_id );
			$existing_customer_id = $existing_invoice['customer_id'] ?? null;

			if ( $existing_customer_id === $contact_id ) {
				$this->logger->info(
					'Invoice already exists',
					[
						'order_id'   => $order->get_id(),
						'invoice_id' => $existing_id,
					]
				);
				return SyncResult::success(
					invoice_id: $existing_id,
					contact_id: $contact_id,
					status: SyncStatus::SYNCED
				);
			} else {
				// Different customer - this is a collision with an old invoice.
				$this->logger->warning(
					'Invoice with same order number exists but for different customer - creating new invoice',
					[
						'order_id'            => $order->get_id(),
						'order_number'        => $order_number,
						'existing_invoice_id' => $existing_id,
						'existing_customer'   => $existing_customer_id,
						'new_customer'        => $contact_id,
					]
				);
				// Continue to create a new invoice below.
			}
		}

		$invoice_data = $this->map_order_to_invoice( $order, $contact_id );

		$this->logger->info(
			'Creating invoice',
			[
				'order_id'     => $order->get_id(),
				'order_number' => $order_number,
				'contact_id'   => $contact_id,
				'total'        => $order->get_total(),
				'currency'     => $order->get_currency(),
				'as_draft'     => $as_draft,
			]
		);

		try {
			// Determine if invoice email should be sent to customer.
			$send_email = $this->should_send_invoice_email();

			$response = $this->client->request(
				function ( $client ) use ( $invoice_data, $send_email ) {
					// Pass send parameter to control whether Zoho emails the customer.
					// Default is false to prevent unwanted emails.
					return $client->invoices->create( $invoice_data, [ 'send' => $send_email ? 'true' : 'false' ] );
				},
				[
					'endpoint'     => 'invoices.create',
					'order_id'     => $order->get_id(),
					'order_number' => $order_number,
				]
			);

			// Convert object to array if needed.
			if ( is_object( $response ) ) {
				$response = json_decode( wp_json_encode( $response ), true );
			}

			$invoice_response = $response['invoice'] ?? $response;
			$invoice_id       = (string) ( $invoice_response['invoice_id'] ?? '' );

			// Mark as sent if not draft and setting is enabled.
			$marked_as_sent     = false;
			$mark_as_sent_error = null;
			$should_mark_sent   = ! $as_draft && $this->should_mark_as_sent();
			if ( $should_mark_sent ) {
				$marked_as_sent = $this->mark_as_sent( $invoice_id );
				if ( ! $marked_as_sent ) {
					$mark_as_sent_error = __( 'Failed to mark invoice as sent', 'zbooks-for-woocommerce' );
					$this->logger->warning(
						'Invoice created but mark_as_sent failed',
						[
							'order_id'     => $order->get_id(),
							'order_number' => $order_number,
							'invoice_id'   => $invoice_id,
						]
					);
				}
			}

			$status = $as_draft ? SyncStatus::DRAFT : SyncStatus::SYNCED;

			$this->logger->info(
				'Invoice created successfully',
				[
					'order_id'     => $order->get_id(),
					'order_number' => $order_number,
					'invoice_id'   => $invoice_id,
					'status'       => $status->value,
					'email_sent'   => $send_email,
				]
			);

			// Merge response data with mark_as_sent status.
			$result_data                   = is_array( $response ) ? $response : [];
			$result_data['marked_as_sent'] = $marked_as_sent;
			$result_data['email_sent']     = $send_email;
			if ( $mark_as_sent_error ) {
				$result_data['mark_as_sent_error'] = $mark_as_sent_error;
			}

			return SyncResult::success(
				invoice_id: $invoice_id,
				contact_id: $contact_id,
				status: $status,
				data: $result_data
			);
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to create invoice',
				[
					'order_id'     => $order->get_id(),
					'order_number' => $order_number,
					'email'        => $order->get_billing_email(),
					'total'        => $order->get_total(),
					'error'        => $e->getMessage(),
				]
			);

			return SyncResult::failure( $e->getMessage() );
		}
	}

	/**
	 * Update an existing invoice in Zoho Books.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $invoice_id Zoho invoice ID.
	 * @param string   $contact_id Zoho contact ID.
	 * @return array{success: bool, error: ?string}
	 */
	public function update_invoice( WC_Order $order, string $invoice_id, string $contact_id ): array {
		$order_number = $order->get_order_number();

		$this->logger->info(
			'Updating invoice',
			[
				'order_id'     => $order->get_id(),
				'order_number' => $order_number,
				'invoice_id'   => $invoice_id,
			]
		);

		try {
			$invoice_data = $this->map_order_to_invoice( $order, $contact_id );

			$response = $this->client->request(
				function ( $client ) use ( $invoice_id, $invoice_data ) {
					return $client->invoices->update( $invoice_id, $invoice_data );
				},
				[
					'endpoint'     => 'invoices.update',
					'invoice_id'   => $invoice_id,
					'order_id'     => $order->get_id(),
					'order_number' => $order_number,
				]
			);

			$this->logger->info(
				'Invoice updated successfully',
				[
					'order_id'   => $order->get_id(),
					'invoice_id' => $invoice_id,
				]
			);

			return [
				'success' => true,
				'error'   => null,
			];
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to update invoice',
				[
					'order_id'   => $order->get_id(),
					'invoice_id' => $invoice_id,
					'error'      => $e->getMessage(),
				]
			);

			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * Find invoice by order number, checking both reference_number and invoice_number.
	 *
	 * This ensures conflict detection works regardless of the invoice numbering setting,
	 * catching duplicates whether the order number was stored as reference or invoice number.
	 *
	 * @param string $order_number WooCommerce order number.
	 * @return string|null Invoice ID or null.
	 */
	public function find_invoice_by_order_number( string $order_number ): ?string {
		// First check reference_number.
		$invoice_id = $this->find_invoice_by_reference( $order_number );
		if ( $invoice_id !== null ) {
			return $invoice_id;
		}

		// Also check invoice_number to catch all duplicates.
		return $this->find_invoice_by_invoice_number( $order_number );
	}

	/**
	 * Find invoice by reference number.
	 *
	 * @param string $reference Reference number (order number).
	 * @return string|null Invoice ID or null.
	 */
	public function find_invoice_by_reference( string $reference ): ?string {
		try {
			$invoices = $this->client->request(
				function ( $client ) use ( $reference ) {
					return $client->invoices->getList(
						[
							'reference_number' => $reference,
						]
					)->toArray();
				},
				[
					'endpoint'  => 'invoices.getList',
					'filter'    => 'reference_number',
					'reference' => $reference,
				]
			);

			if ( ! empty( $invoices ) ) {
				// API returns associative array keyed by invoice_id, use reset() to get first element.
				$first_invoice = reset( $invoices );
				return (string) ( $first_invoice['invoice_id'] ?? '' );
			}
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to search for invoice by reference',
				[
					'reference' => $reference,
					'error'     => $e->getMessage(),
				]
			);
		}

		return null;
	}

	/**
	 * Find invoice by invoice number.
	 *
	 * @param string $invoice_number Invoice number (order number when using direct numbering).
	 * @return string|null Invoice ID or null.
	 */
	public function find_invoice_by_invoice_number( string $invoice_number ): ?string {
		try {
			$invoices = $this->client->request(
				function ( $client ) use ( $invoice_number ) {
					return $client->invoices->getList(
						[
							'invoice_number' => $invoice_number,
						]
					)->toArray();
				},
				[
					'endpoint'       => 'invoices.getList',
					'filter'         => 'invoice_number',
					'invoice_number' => $invoice_number,
				]
			);

			if ( ! empty( $invoices ) ) {
				// API returns associative array keyed by invoice_id, use reset() to get first element.
				$first_invoice = reset( $invoices );
				return (string) ( $first_invoice['invoice_id'] ?? '' );
			}
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to search for invoice by invoice number',
				[
					'invoice_number' => $invoice_number,
					'error'          => $e->getMessage(),
				]
			);
		}

		return null;
	}

	/**
	 * Check if using Zoho's auto-numbering series for invoices.
	 *
	 * When true (default), Zoho auto-generates invoice numbers (INV-00001, etc.).
	 * When false, order number is used as the invoice_number directly.
	 *
	 * Note: reference_number is always set to the order number regardless of this setting.
	 *
	 * @return bool
	 */
	private function use_reference_number(): bool {
		$settings = get_option(
			'zbooks_invoice_numbering',
			[
				'use_reference_number' => true,
			]
		);

		return ! empty( $settings['use_reference_number'] );
	}

	/**
	 * Check if invoices should be marked as sent after creation.
	 *
	 * When disabled, invoices remain as drafts which prevents Zoho from
	 * sending automatic email notifications to customers.
	 *
	 * @return bool
	 */
	private function should_mark_as_sent(): bool {
		$settings = get_option(
			'zbooks_invoice_numbering',
			[
				'mark_as_sent' => true,
			]
		);

		return $settings['mark_as_sent'] ?? true;
	}

	/**
	 * Check if invoice email should be sent to customer via Zoho Books.
	 *
	 * This controls the 'send' parameter in the Zoho API which determines
	 * whether Zoho Books emails the invoice to the customer on creation.
	 *
	 * @return bool Default is false to prevent unwanted customer emails.
	 */
	private function should_send_invoice_email(): bool {
		$settings = get_option(
			'zbooks_invoice_numbering',
			[
				'send_invoice_email' => false,
			]
		);

		return ! empty( $settings['send_invoice_email'] );
	}

	/**
	 * Mark invoice as sent.
	 *
	 * @param string $invoice_id Zoho invoice ID.
	 * @return bool
	 */
	public function mark_as_sent( string $invoice_id ): bool {
		try {
			$this->client->request(
				function ( $client ) use ( $invoice_id ) {
					return $client->invoices->markAsSent( $invoice_id );
				},
				[
					'endpoint'   => 'invoices.markAsSent',
					'invoice_id' => $invoice_id,
				]
			);

			$this->logger->debug(
				'Invoice marked as sent',
				[
					'invoice_id' => $invoice_id,
				]
			);

			return true;
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to mark invoice as sent',
				[
					'invoice_id' => $invoice_id,
					'error'      => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Map WooCommerce order to Zoho invoice format.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $contact_id Zoho contact ID.
	 * @return array Invoice data.
	 */
	private function map_order_to_invoice( WC_Order $order, string $contact_id ): array {
		$order_number = $order->get_order_number();

		$invoice = [
			'customer_id' => $contact_id,
			'date'        => $order->get_date_created()->format( 'Y-m-d' ),
			'line_items'  => $this->map_line_items( $order ),
		];

		// Always store order number in reference_number for easy lookup.
		$invoice['reference_number'] = $order_number;

		// Additionally set invoice_number if not using Zoho's auto-numbering series.
		if ( ! $this->use_reference_number() ) {
			$invoice['invoice_number'] = $order_number;
		}

		// Add shipping charge.
		$shipping_total = (float) $order->get_shipping_total();
		if ( $shipping_total > 0 ) {
			$invoice['shipping_charge'] = $shipping_total;

			// Add shipping account if configured.
			$shipping_settings = get_option( 'zbooks_shipping_settings', [] );
			if ( ! empty( $shipping_settings['account_id'] ) ) {
				$invoice['shipping_charge_account_id'] = $shipping_settings['account_id'];
			}
		}

		// Add discount.
		$discount_total = (float) $order->get_discount_total();
		if ( $discount_total > 0 ) {
			$invoice['discount']               = $discount_total;
			$invoice['discount_type']          = 'entity_level';
			$invoice['is_discount_before_tax'] = true;
		}

		// Add currency.
		$currency = $order->get_currency();
		if ( ! empty( $currency ) ) {
			$invoice['currency_code'] = $currency;
		}

		// Add notes with sync metadata.
		$customer_note = $order->get_customer_note();
		$sync_comment  = SyncMetadataHelper::generate_sync_comment( $order, 'invoice' );
		if ( ! empty( $customer_note ) ) {
			$invoice['notes'] = $customer_note . "\n\n" . $sync_comment;
		} else {
			$invoice['notes'] = $sync_comment;
		}

		// Add custom field mappings.
		$custom_fields = $this->field_mapping->build_custom_fields( $order, 'invoice' );
		if ( ! empty( $custom_fields ) ) {
			$invoice['custom_fields'] = $custom_fields;
			$this->logger->debug(
				'Adding custom fields to invoice',
				[
					'order_id'           => $order->get_id(),
					'custom_field_count' => count( $custom_fields ),
				]
			);
		}

		return $invoice;
	}

	/**
	 * Map order items to Zoho line items.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array Line items.
	 */
	private function map_line_items( WC_Order $order ): array {
		$line_items = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$quantity = $item->get_quantity();
			if ( $quantity <= 0 ) {
				continue;
			}

			$subtotal = (float) $item->get_subtotal();
			$rate     = $subtotal / $quantity;

			$line_item = [
				'name'     => $item->get_name(),
				'quantity' => $quantity,
				'rate'     => round( $rate, 2 ),
			];

			// Add description and Zoho item mapping if product exists.
			$product = $item->get_product();
			if ( $product ) {
				$product_id = $product->get_id();

				$description = $product->get_short_description();
				if ( ! empty( $description ) ) {
					$line_item['description'] = wp_strip_all_tags( $description );
				}

				// Use mapped Zoho item ID if available.
				$zoho_item_id = $this->item_mapping->get_zoho_item_id( $product_id );
				// Note: We use explicit null and empty string checks instead of empty() because
				// empty() treats the string "0" as empty, which would exclude valid item_id mappings.
				if ( $zoho_item_id !== null && $zoho_item_id !== '' ) {
					$line_item['item_id'] = $zoho_item_id;
					$this->logger->debug(
						'Using mapped Zoho item',
						[
							'product_id'   => $product_id,
							'zoho_item_id' => $zoho_item_id,
						]
					);
				}
			}

			$line_items[] = $line_item;
		}

		// Add fee items.
		foreach ( $order->get_fees() as $fee ) {
			$line_items[] = [
				'name'     => $fee->get_name(),
				'quantity' => 1,
				'rate'     => (float) $fee->get_total(),
			];
		}

		return $line_items;
	}

	/**
	 * Get invoice details from Zoho.
	 *
	 * @param string $invoice_id Zoho invoice ID.
	 * @return array|null Invoice data or null.
	 */
	public function get_invoice( string $invoice_id ): ?array {
		try {
			return $this->client->request(
				function ( $client ) use ( $invoice_id ) {
					return $client->invoices->get( $invoice_id )->toArray();
				},
				[
					'endpoint'   => 'invoices.get',
					'invoice_id' => $invoice_id,
				]
			);
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to get invoice',
				[
					'invoice_id' => $invoice_id,
					'error'      => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get invoice status from Zoho.
	 *
	 * @param string $invoice_id Zoho invoice ID.
	 * @return string|null Invoice status (draft/sent/paid/partially_paid/overdue/void) or null.
	 */
	public function get_invoice_status( string $invoice_id ): ?string {
		$invoice = $this->get_invoice( $invoice_id );
		return $invoice['status'] ?? null;
	}

	/**
	 * Verify invoice exists in Zoho and optionally validate it matches the order.
	 *
	 * @param string        $invoice_id Zoho invoice ID.
	 * @param WC_Order|null $order      Optional order to validate against.
	 * @return array{exists: bool, valid: bool, invoice: ?array, discrepancies: array}
	 */
	public function verify_invoice_exists( string $invoice_id, ?WC_Order $order = null ): array {
		$result = [
			'exists'        => false,
			'valid'         => false,
			'invoice'       => null,
			'discrepancies' => [],
		];

		$invoice = $this->get_invoice( $invoice_id );

		if ( $invoice === null ) {
			$this->logger->info(
				'Invoice not found in Zoho (may have been deleted)',
				[ 'invoice_id' => $invoice_id ]
			);
			return $result;
		}

		$result['exists']  = true;
		$result['invoice'] = $invoice;

		// If no order provided, just check existence.
		if ( $order === null ) {
			$result['valid'] = true;
			return $result;
		}

		// Validate invoice matches order.
		$result['discrepancies'] = $this->compare_invoice_to_order( $invoice, $order );
		$result['valid']         = empty( $result['discrepancies'] );

		if ( ! $result['valid'] ) {
			$this->logger->info(
				'Invoice exists but has discrepancies with order',
				[
					'invoice_id'    => $invoice_id,
					'order_id'      => $order->get_id(),
					'discrepancies' => $result['discrepancies'],
				]
			);
		}

		return $result;
	}

	/**
	 * Compare Zoho invoice to WooCommerce order for discrepancies.
	 *
	 * @param array    $invoice Zoho invoice data.
	 * @param WC_Order $order   WooCommerce order.
	 * @return array List of discrepancies found.
	 */
	private function compare_invoice_to_order( array $invoice, WC_Order $order ): array {
		$discrepancies = [];
		$tolerance     = 0.01; // Allow 1 cent tolerance for rounding.

		// Compare total amount.
		$invoice_total = (float) ( $invoice['total'] ?? 0 );
		$order_total   = (float) $order->get_total();

		if ( abs( $invoice_total - $order_total ) > $tolerance ) {
			$discrepancies[] = [
				'field'    => 'total',
				'invoice'  => $invoice_total,
				'order'    => $order_total,
				'severity' => 'high',
			];
		}

		// Compare line item count.
		$invoice_items = $invoice['line_items'] ?? [];
		$order_items   = $order->get_items();

		if ( count( $invoice_items ) !== count( $order_items ) ) {
			$discrepancies[] = [
				'field'    => 'line_item_count',
				'invoice'  => count( $invoice_items ),
				'order'    => count( $order_items ),
				'severity' => 'high',
			];
		}

		// Compare reference number (order number).
		$invoice_ref  = $invoice['reference_number'] ?? '';
		$order_number = $order->get_order_number();

		if ( $invoice_ref !== $order_number && $invoice_ref !== '' ) {
			$discrepancies[] = [
				'field'    => 'reference_number',
				'invoice'  => $invoice_ref,
				'order'    => $order_number,
				'severity' => 'medium',
			];
		}

		// Check if invoice is locked (cannot be edited).
		$status = strtolower( $invoice['status'] ?? '' );
		if ( in_array( $status, [ 'paid', 'void' ], true ) ) {
			$discrepancies[] = [
				'field'    => 'status',
				'invoice'  => $status,
				'order'    => 'n/a',
				'severity' => 'info',
				'message'  => 'Invoice is ' . $status . ' and cannot be modified',
			];
		}

		return $discrepancies;
	}

	/**
	 * Void an invoice in Zoho Books.
	 *
	 * @param string $invoice_id Zoho invoice ID.
	 * @return bool True on success.
	 */
	public function void_invoice( string $invoice_id ): bool {
		try {
			$this->client->request(
				function ( $client ) use ( $invoice_id ) {
					return $client->invoices->markAsVoid( $invoice_id );
				},
				[
					'endpoint'   => 'invoices.markAsVoid',
					'invoice_id' => $invoice_id,
				]
			);

			$this->logger->info(
				'Invoice voided',
				[
					'invoice_id' => $invoice_id,
				]
			);

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to void invoice',
				[
					'invoice_id' => $invoice_id,
					'error'      => $e->getMessage(),
				]
			);
			return false;
		}
	}
}
