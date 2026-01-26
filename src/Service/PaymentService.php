<?php
/**
 * Payment service for Zoho Books.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

use WC_Order;
use Zbooks\Api\ZohoClient;
use Zbooks\Logger\SyncLogger;
use Zbooks\Repository\PaymentMethodMappingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Service for managing Zoho Books payments.
 */
class PaymentService {

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
	 * Payment method mapping repository.
	 *
	 * @var PaymentMethodMappingRepository
	 */
	private PaymentMethodMappingRepository $mapping_repository;

	/**
	 * Constructor.
	 *
	 * @param ZohoClient                     $client             Zoho client instance.
	 * @param SyncLogger                     $logger             Logger instance.
	 * @param PaymentMethodMappingRepository $mapping_repository Payment mapping repository.
	 */
	public function __construct(
		ZohoClient $client,
		SyncLogger $logger,
		PaymentMethodMappingRepository $mapping_repository
	) {
		$this->client             = $client;
		$this->logger             = $logger;
		$this->mapping_repository = $mapping_repository;
	}

	/**
	 * Apply payment to an invoice.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $invoice_id Zoho invoice ID.
	 * @param string   $contact_id Zoho contact ID.
	 * @return array{success: bool, payment_id: ?string, error: ?string}
	 */
	public function apply_payment( WC_Order $order, string $invoice_id, string $contact_id ): array {
		$order_id = $order->get_id();
		$amount   = (float) $order->get_total();

		// Don't create payment for zero amount.
		if ( $amount <= 0 ) {
			$this->logger->debug(
				'Skipping payment for zero amount order',
				[
					'order_id' => $order_id,
				]
			);
			return [
				'success'    => true,
				'payment_id' => null,
				'error'      => null,
			];
		}

		// Validate invoice can accept payments.
		$validation = $this->validate_invoice_for_payment( $invoice_id );
		if ( ! $validation['valid'] ) {
			$log_level = $validation['already_paid'] ? 'debug' : 'warning';
			$this->logger->$log_level(
				'Invoice validation failed for payment',
				[
					'order_id'     => $order_id,
					'invoice_id'   => $invoice_id,
					'error'        => $validation['error'],
					'already_paid' => $validation['already_paid'],
				]
			);

			// Return success if already paid (idempotent behavior).
			if ( $validation['already_paid'] ) {
				return [
					'success'    => true,
					'payment_id' => null,
					'error'      => null,
				];
			}

			return [
				'success'    => false,
				'payment_id' => null,
				'error'      => $validation['error'],
			];
		}

		// Use the lesser of order total and invoice balance.
		$invoice = $validation['invoice'];
		$invoice_balance = (float) ( $invoice['balance'] ?? $amount );
		$payment_amount = min( $amount, $invoice_balance );

		// Warn if amounts don't match.
		if ( abs( $amount - $invoice_balance ) > 0.01 ) {
			$this->logger->warning(
				'Payment amount mismatch - using invoice balance',
				[
					'order_id'       => $order_id,
					'order_total'    => $amount,
					'invoice_balance' => $invoice_balance,
					'applying'       => $payment_amount,
				]
			);
		}

		// Use the calculated payment amount for the rest of the method.
		$amount = $payment_amount;

		$payment_data = $this->map_order_to_payment( $order, $invoice_id, $contact_id, $amount );

		// Get actual bank charges from order meta (set by payment gateways).
		$bank_charges = $this->get_order_bank_charges( $order );

		$this->logger->info(
			'Applying payment to invoice',
			[
				'order_id'       => $order_id,
				'order_number'   => $order->get_order_number(),
				'invoice_id'     => $invoice_id,
				'amount'         => $amount,
				'bank_charges'   => $bank_charges,
				'payment_method' => $order->get_payment_method_title(),
			]
		);

		try {
			$response = $this->client->request(
				function ( $client ) use ( $payment_data ) {
					return $client->customerpayments->create( $payment_data );
				},
				[
					'endpoint'     => 'customerpayments.create',
					'order_id'     => $order_id,
					'order_number' => $order->get_order_number(),
					'invoice_id'   => $invoice_id,
				]
			);

			// Convert object to array if needed.
			if ( is_object( $response ) ) {
				$response = json_decode( wp_json_encode( $response ), true );
			}

			$payment_data   = $response['payment'] ?? $response;
			$payment_id     = (string) ( $payment_data['payment_id'] ?? '' );
			$payment_number = $payment_data['payment_number'] ?? null;

			$this->logger->info(
				'Payment applied successfully',
				[
					'order_id'       => $order_id,
					'order_number'   => $order->get_order_number(),
					'invoice_id'     => $invoice_id,
					'payment_id'     => $payment_id,
					'payment_number' => $payment_number,
					'amount'         => $amount,
				]
			);

			return [
				'success'        => true,
				'payment_id'     => $payment_id,
				'payment_number' => $payment_number,
				'error'          => null,
			];
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to apply payment',
				[
					'order_id'     => $order_id,
					'order_number' => $order->get_order_number(),
					'invoice_id'   => $invoice_id,
					'amount'       => $amount,
					'error'        => $e->getMessage(),
				]
			);

			return [
				'success'    => false,
				'payment_id' => null,
				'error'      => $e->getMessage(),
			];
		}
	}

	/**
	 * Get invoice status from Zoho.
	 *
	 * @param string $invoice_id Zoho invoice ID.
	 * @return array|null Invoice data or null.
	 */
	private function get_invoice_status( string $invoice_id ): ?array {
		try {
			$response = $this->client->request(
				function ( $client ) use ( $invoice_id ) {
					return $client->invoices->get( $invoice_id );
				},
				[
					'endpoint'   => 'invoices.get',
					'invoice_id' => $invoice_id,
				]
			);

			if ( is_array( $response ) ) {
				return $response['invoice'] ?? $response;
			}

			if ( method_exists( $response, 'toArray' ) ) {
				return $response->toArray();
			}

			return null;
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to get invoice status',
				[
					'invoice_id' => $invoice_id,
					'error'      => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Validate that an invoice can accept payments.
	 *
	 * Checks if the invoice exists, is not void/draft/deleted, and has outstanding balance.
	 *
	 * @param string $invoice_id Zoho invoice ID.
	 * @return array{valid: bool, invoice: ?array, error: ?string, already_paid: bool}
	 */
	private function validate_invoice_for_payment( string $invoice_id ): array {
		$invoice = $this->get_invoice_status( $invoice_id );

		if ( $invoice === null ) {
			return [
				'valid'        => false,
				'invoice'      => null,
				'error'        => __( 'Invoice not found in Zoho Books (may have been deleted)', 'zbooks-for-woocommerce' ),
				'already_paid' => false,
			];
		}

		$status = strtolower( $invoice['status'] ?? '' );
		$invalid_statuses = [ 'void', 'draft' ];

		if ( in_array( $status, $invalid_statuses, true ) ) {
			return [
				'valid'        => false,
				'invoice'      => $invoice,
				'error'        => sprintf(
					/* translators: %s: Invoice status */
					__( 'Invoice is %s and cannot accept payments', 'zbooks-for-woocommerce' ),
					$status
				),
				'already_paid' => false,
			];
		}

		if ( $status === 'paid' ) {
			return [
				'valid'        => false,
				'invoice'      => $invoice,
				'error'        => __( 'Invoice is already fully paid', 'zbooks-for-woocommerce' ),
				'already_paid' => true,
			];
		}

		// Check if there's any balance remaining.
		$balance = (float) ( $invoice['balance'] ?? 0 );
		if ( $balance <= 0 ) {
			return [
				'valid'        => false,
				'invoice'      => $invoice,
				'error'        => __( 'Invoice has no outstanding balance', 'zbooks-for-woocommerce' ),
				'already_paid' => true,
			];
		}

		return [
			'valid'        => true,
			'invoice'      => $invoice,
			'error'        => null,
			'already_paid' => false,
		];
	}

	/**
	 * Map WooCommerce order to Zoho payment format.
	 *
	 * @param WC_Order   $order           WooCommerce order.
	 * @param string     $invoice_id      Zoho invoice ID.
	 * @param string     $contact_id      Zoho contact ID.
	 * @param float|null $override_amount Optional amount to use instead of order total.
	 * @return array Payment data.
	 */
	private function map_order_to_payment( WC_Order $order, string $invoice_id, string $contact_id, ?float $override_amount = null ): array {
		$payment_date = $order->get_date_paid();
		if ( ! $payment_date ) {
			$payment_date = $order->get_date_completed() ?? $order->get_date_created();
		}

		$amount = $override_amount ?? (float) $order->get_total();
		$wc_method = $order->get_payment_method();

		$payment = [
			'customer_id' => $contact_id,
			'date'        => $payment_date->format( 'Y-m-d' ),
			'amount'      => $amount,
			'invoices'    => [
				[
					'invoice_id'     => $invoice_id,
					'amount_applied' => $amount,
				],
			],
		];

		// Add payment method info.
		$payment_method = $order->get_payment_method_title();

		if ( ! empty( $payment_method ) ) {
			$payment['payment_mode']     = $this->map_payment_mode( $wc_method );
			$payment['reference_number'] = $this->get_payment_reference( $order );
			$payment['description']      = sprintf(
				/* translators: 1: Order number, 2: Payment method */
				__( 'Payment for Order #%1$s via %2$s', 'zbooks-for-woocommerce' ),
				$order->get_order_number(),
				$payment_method
			);

			// Add deposit account (bank/cash account) if mapped.
			$account_id = $this->mapping_repository->get_zoho_account_id( $wc_method );
			if ( ! empty( $account_id ) ) {
				$payment['account_id'] = $account_id;

				// Only add bank charges when account_id is configured.
				// Zoho requires account_id when bank_charges is specified.
				$bank_charges = $this->get_order_bank_charges( $order );
				if ( $bank_charges > 0 ) {
					$payment['bank_charges'] = $bank_charges;

					// Add bank charges expense account if configured.
					$fee_account_id = $this->mapping_repository->get_fee_account_id( $wc_method );
					if ( ! empty( $fee_account_id ) ) {
						$payment['bank_charges_account_id'] = $fee_account_id;
					}
				}
			}
		}

		return $payment;
	}

	/**
	 * Map WooCommerce payment method to Zoho payment mode.
	 *
	 * @param string $wc_method WooCommerce payment method slug.
	 * @return string Zoho payment mode.
	 */
	private function map_payment_mode( string $wc_method ): string {
		// Check repository mapping first.
		$mapped_mode = $this->mapping_repository->get_zoho_mode( $wc_method );
		if ( ! empty( $mapped_mode ) ) {
			return $mapped_mode;
		}

		// Fall back to default mappings.
		$default_mappings = [
			'paypal'                   => 'PayPal',
			'stripe'                   => 'Credit Card',
			'stripe_cc'                => 'Credit Card',
			'bacs'                     => 'Bank Transfer',
			'cheque'                   => 'Check',
			'cod'                      => 'Cash',
			'square'                   => 'Credit Card',
			'braintree'                => 'Credit Card',
			'amazon_payments_advanced' => 'Amazon Pay',
		];

		// Allow filtering payment mode mapping.
		$default_mappings = apply_filters( 'zbooks_payment_mode_mapping', $default_mappings );

		return $default_mappings[ $wc_method ] ?? 'Others';
	}

	/**
	 * Get payment reference number from order.
	 *
	 * Uses transaction ID for most payment methods. For Bitcoin and other
	 * methods with long transaction IDs (64+ chars), uses order number instead
	 * since Zoho has a 50 character limit on reference_number.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string Reference number.
	 */
	private function get_payment_reference( WC_Order $order ): string {
		$payment_method = $order->get_payment_method();

		// Bitcoin payment methods have long transaction hashes (64 chars).
		// Use order number for these and add hash to notes instead.
		$bitcoin_methods = [
			'bitcoin',
			'btc',
			'btcpay',
			'btcpay_greenfield',
			'coinbase',
			'coinbase_commerce',
			'bitpay',
			'opennode',
		];

		// Allow filtering the list of methods that use order number.
		$bitcoin_methods = apply_filters( 'zbooks_long_transaction_id_methods', $bitcoin_methods );

		if ( in_array( $payment_method, $bitcoin_methods, true ) ) {
			// Add transaction hash to order notes for reference.
			$transaction_id = $order->get_transaction_id();
			if ( ! empty( $transaction_id ) ) {
				$this->add_transaction_note( $order, $transaction_id );
			}
			return $order->get_order_number();
		}

		// Use transaction ID for all other payment methods.
		$transaction_id = $order->get_transaction_id();
		if ( ! empty( $transaction_id ) ) {
			return $transaction_id;
		}

		// Fallback to order number if no transaction ID.
		return $order->get_order_number();
	}

	/**
	 * Add transaction ID to order notes (for payment methods with long IDs).
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param string   $transaction_id Transaction ID/hash.
	 */
	private function add_transaction_note( WC_Order $order, string $transaction_id ): void {
		// Check if we've already added this note to avoid duplicates.
		$note_added = $order->get_meta( '_zbooks_transaction_note_added' );
		if ( $note_added === $transaction_id ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: Transaction ID/hash */
				__( 'Payment transaction ID: %s', 'zbooks-for-woocommerce' ),
				$transaction_id
			)
		);

		$order->update_meta_data( '_zbooks_transaction_note_added', $transaction_id );
		$order->save();
	}

	/**
	 * Get actual bank charges/fees from WooCommerce order meta.
	 *
	 * Payment gateways store their fees in order meta. This method checks
	 * common meta keys used by popular gateways.
	 *
	 * If the fee is in a different currency than the order (e.g., Stripe processing
	 * in local currency like AED while order is in USD), the fee is converted.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return float Bank charges amount in order currency, or 0 if not found.
	 */
	private function get_order_bank_charges( WC_Order $order ): float {
		// Get raw fee amount.
		$raw_fee = $this->extract_raw_fee( $order );
		if ( $raw_fee <= 0 ) {
			return 0.0;
		}

		// Check if fee currency differs from order currency.
		$fee_currency   = $this->get_fee_currency( $order );
		$order_currency = $order->get_currency();

		// If currencies match, no conversion needed.
		if ( empty( $fee_currency ) || strtoupper( $fee_currency ) === strtoupper( $order_currency ) ) {
			return $raw_fee;
		}

		// Currencies differ - need to convert.
		$exchange_rate = $this->calculate_gateway_exchange_rate( $order );
		if ( $exchange_rate === null || $exchange_rate <= 0 ) {
			$this->logger->warning(
				'Bank fee currency mismatch - skipping fee (cannot calculate exchange rate)',
				[
					'order_id'       => $order->get_id(),
					'raw_fee'        => $raw_fee,
					'fee_currency'   => $fee_currency,
					'order_currency' => $order_currency,
				]
			);
			return 0.0;
		}

		// Convert fee to order currency.
		$converted_fee = round( $raw_fee / $exchange_rate, 2 );

		$this->logger->info(
			'Converted bank fee from gateway currency to order currency',
			[
				'order_id'       => $order->get_id(),
				'original_fee'   => "{$raw_fee} {$fee_currency}",
				'converted_fee'  => "{$converted_fee} {$order_currency}",
				'exchange_rate'  => $exchange_rate,
			]
		);

		return $converted_fee;
	}

	/**
	 * Extract raw fee amount from order meta.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return float Raw fee amount (may be in different currency).
	 */
	private function extract_raw_fee( WC_Order $order ): float {
		// Common meta keys used by payment gateways for fees.
		$fee_meta_keys = [
			'_stripe_fee',              // Stripe for WooCommerce.
			'_paypal_fee',              // PayPal.
			'_paypal_transaction_fee',  // PayPal alternative.
			'_wcpay_transaction_fee',   // WooCommerce Payments.
			'_square_fee',              // Square.
			'_payment_gateway_fee',     // Generic.
			'_transaction_fee',         // Generic.
		];

		// Allow plugins to add custom meta keys.
		$fee_meta_keys = apply_filters( 'zbooks_payment_fee_meta_keys', $fee_meta_keys, $order );

		foreach ( $fee_meta_keys as $meta_key ) {
			$fee = $order->get_meta( $meta_key );
			if ( ! empty( $fee ) && is_numeric( $fee ) ) {
				return round( (float) $fee, 2 );
			}
		}

		return 0.0;
	}

	/**
	 * Get the currency of the payment gateway fee.
	 *
	 * Payment gateways may process in a different currency than the order.
	 * For example, Stripe may process a USD order in local currency (AED).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null Fee currency code, or null if same as order.
	 */
	private function get_fee_currency( WC_Order $order ): ?string {
		// Stripe stores the processing currency.
		$stripe_currency = $order->get_meta( '_stripe_currency' );
		if ( ! empty( $stripe_currency ) ) {
			return strtoupper( $stripe_currency );
		}

		// PayPal may store currency.
		$paypal_currency = $order->get_meta( '_paypal_currency' );
		if ( ! empty( $paypal_currency ) ) {
			return strtoupper( $paypal_currency );
		}

		// WooCommerce Payments currency.
		$wcpay_currency = $order->get_meta( '_wcpay_currency' );
		if ( ! empty( $wcpay_currency ) ) {
			return strtoupper( $wcpay_currency );
		}

		// Default: assume fee is in order currency.
		return null;
	}

	/**
	 * Calculate exchange rate from gateway data.
	 *
	 * Stripe provides net amount and fee in the processing currency.
	 * Exchange rate = (net + fee) / order_total
	 *
	 * Example:
	 *   Order: $499 USD
	 *   Stripe net: 1742.63 AED, fee: 71.76 AED
	 *   Total AED: 1814.39
	 *   Rate: 1814.39 / 499 = 3.636 AED/USD
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return float|null Exchange rate (gateway_currency per order_currency), or null if cannot calculate.
	 */
	private function calculate_gateway_exchange_rate( WC_Order $order ): ?float {
		$order_total = (float) $order->get_total();
		if ( $order_total <= 0 ) {
			return null;
		}

		// Try Stripe data first.
		$stripe_net = $order->get_meta( '_stripe_net' );
		$stripe_fee = $order->get_meta( '_stripe_fee' );

		if ( ! empty( $stripe_net ) && is_numeric( $stripe_net ) ) {
			$net = (float) $stripe_net;
			$fee = ! empty( $stripe_fee ) && is_numeric( $stripe_fee ) ? (float) $stripe_fee : 0.0;
			$total_in_gateway_currency = $net + $fee;

			if ( $total_in_gateway_currency > 0 ) {
				return $total_in_gateway_currency / $order_total;
			}
		}

		// Could add other gateway calculations here (PayPal, etc.)

		return null;
	}

	/**
	 * Get payment details from Zoho.
	 *
	 * @param string $payment_id Zoho payment ID.
	 * @return array|null Payment data or null.
	 */
	public function get_payment( string $payment_id ): ?array {
		try {
			$response = $this->client->request(
				function ( $client ) use ( $payment_id ) {
					return $client->customerpayments->get( $payment_id );
				},
				[
					'endpoint'   => 'customerpayments.get',
					'payment_id' => $payment_id,
				]
			);

			if ( is_array( $response ) ) {
				return $response['payment'] ?? $response;
			}

			if ( method_exists( $response, 'toArray' ) ) {
				return $response->toArray();
			}

			return null;
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to get payment',
				[
					'payment_id' => $payment_id,
					'error'      => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Find payment by reference number.
	 *
	 * @param string $reference Reference number.
	 * @return string|null Payment ID or null.
	 */
	public function find_payment_by_reference( string $reference ): ?string {
		try {
			$payments = $this->client->request(
				function ( $client ) use ( $reference ) {
					return $client->customerpayments->getList(
						[
							'reference_number' => $reference,
						]
					);
				},
				[
					'endpoint'  => 'customerpayments.getList',
					'filter'    => 'reference_number',
					'reference' => $reference,
				]
			);

			$list = is_array( $payments ) ? ( $payments['customerpayments'] ?? $payments ) : [];

			if ( is_object( $payments ) && method_exists( $payments, 'toArray' ) ) {
				$list = $payments->toArray();
			}

			if ( ! empty( $list ) && isset( $list[0]['payment_id'] ) ) {
				return (string) $list[0]['payment_id'];
			}
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to search for payment',
				[
					'reference' => $reference,
					'error'     => $e->getMessage(),
				]
			);
		}

		return null;
	}

	/**
	 * Delete a payment from Zoho.
	 *
	 * @param string $payment_id Zoho payment ID.
	 * @return bool True on success.
	 */
	public function delete_payment( string $payment_id ): bool {
		try {
			$this->client->request(
				function ( $client ) use ( $payment_id ) {
					return $client->customerpayments->delete( $payment_id );
				},
				[
					'endpoint'   => 'customerpayments.delete',
					'payment_id' => $payment_id,
				]
			);

			$this->logger->info(
				'Payment deleted',
				[
					'payment_id' => $payment_id,
				]
			);

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to delete payment',
				[
					'payment_id' => $payment_id,
					'error'      => $e->getMessage(),
				]
			);
			return false;
		}
	}
}
