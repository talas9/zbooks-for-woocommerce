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
		if ( $existing_id !== null ) {
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
			$response = $this->client->request(
				function ( $client ) use ( $invoice_data ) {
					return $client->invoices->create( $invoice_data );
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
			$should_mark_sent = ! $as_draft && $this->should_mark_as_sent();
			if ( $should_mark_sent ) {
				$this->mark_as_sent( $invoice_id );
			}

			$status = $as_draft ? SyncStatus::DRAFT : SyncStatus::SYNCED;

			$this->logger->info(
				'Invoice created successfully',
				[
					'order_id'     => $order->get_id(),
					'order_number' => $order_number,
					'invoice_id'   => $invoice_id,
					'status'       => $status->value,
				]
			);

			return SyncResult::success(
				invoice_id: $invoice_id,
				contact_id: $contact_id,
				status: $status,
				data: $response
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
				return (string) $invoices[0]['invoice_id'];
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
				return (string) $invoices[0]['invoice_id'];
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

		// Add notes.
		$customer_note = $order->get_customer_note();
		if ( ! empty( $customer_note ) ) {
			$invoice['notes'] = $customer_note;
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
				if ( ! empty( $zoho_item_id ) ) {
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
}
