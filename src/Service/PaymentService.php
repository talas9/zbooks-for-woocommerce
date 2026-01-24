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

defined('ABSPATH') || exit;

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
        $this->client = $client;
        $this->logger = $logger;
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
    public function apply_payment(WC_Order $order, string $invoice_id, string $contact_id): array {
        $order_id = $order->get_id();
        $amount = (float) $order->get_total();

        // Don't create payment for zero amount.
        if ($amount <= 0) {
            $this->logger->debug('Skipping payment for zero amount order', [
                'order_id' => $order_id,
            ]);
            return [
                'success' => true,
                'payment_id' => null,
                'error' => null,
            ];
        }

        // Check if invoice is already paid.
        $invoice = $this->get_invoice_status($invoice_id);
        if ($invoice && $invoice['status'] === 'paid') {
            $this->logger->debug('Invoice already paid', [
                'order_id' => $order_id,
                'invoice_id' => $invoice_id,
            ]);
            return [
                'success' => true,
                'payment_id' => null,
                'error' => null,
            ];
        }

        $payment_data = $this->map_order_to_payment($order, $invoice_id, $contact_id);

        $this->logger->info('Applying payment to invoice', [
            'order_id' => $order_id,
            'invoice_id' => $invoice_id,
            'amount' => $amount,
        ]);

        try {
            $response = $this->client->request(function ($client) use ($payment_data) {
                return $client->customerpayments->create($payment_data);
            });

            $payment_id = (string) ($response['payment_id'] ?? $response['payment']['payment_id'] ?? '');

            $this->logger->info('Payment applied successfully', [
                'order_id' => $order_id,
                'invoice_id' => $invoice_id,
                'payment_id' => $payment_id,
            ]);

            return [
                'success' => true,
                'payment_id' => $payment_id,
                'error' => null,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to apply payment', [
                'order_id' => $order_id,
                'invoice_id' => $invoice_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'payment_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get invoice status from Zoho.
     *
     * @param string $invoice_id Zoho invoice ID.
     * @return array|null Invoice data or null.
     */
    private function get_invoice_status(string $invoice_id): ?array {
        try {
            $response = $this->client->request(function ($client) use ($invoice_id) {
                return $client->invoices->get($invoice_id);
            });

            if (is_array($response)) {
                return $response['invoice'] ?? $response;
            }

            if (method_exists($response, 'toArray')) {
                return $response->toArray();
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get invoice status', [
                'invoice_id' => $invoice_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Map WooCommerce order to Zoho payment format.
     *
     * @param WC_Order $order      WooCommerce order.
     * @param string   $invoice_id Zoho invoice ID.
     * @param string   $contact_id Zoho contact ID.
     * @return array Payment data.
     */
    private function map_order_to_payment(WC_Order $order, string $invoice_id, string $contact_id): array {
        $payment_date = $order->get_date_paid();
        if (!$payment_date) {
            $payment_date = $order->get_date_completed() ?? $order->get_date_created();
        }

        $payment = [
            'customer_id' => $contact_id,
            'date' => $payment_date->format('Y-m-d'),
            'amount' => (float) $order->get_total(),
            'invoices' => [
                [
                    'invoice_id' => $invoice_id,
                    'amount_applied' => (float) $order->get_total(),
                ],
            ],
        ];

        // Add payment method info.
        $wc_method = $order->get_payment_method();
        $payment_method = $order->get_payment_method_title();

        if (!empty($payment_method)) {
            $payment['payment_mode'] = $this->map_payment_mode($wc_method);
            $payment['reference_number'] = $this->get_payment_reference($order);
            $payment['description'] = sprintf(
                /* translators: 1: Order number, 2: Payment method */
                __('Payment for Order #%1$s via %2$s', 'zbooks-for-woocommerce'),
                $order->get_order_number(),
                $payment_method
            );

            // Add deposit account (bank/cash account) if mapped.
            $account_id = $this->mapping_repository->get_zoho_account_id($wc_method);
            if (!empty($account_id)) {
                $payment['account_id'] = $account_id;
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
    private function map_payment_mode(string $wc_method): string {
        // Check repository mapping first.
        $mapped_mode = $this->mapping_repository->get_zoho_mode($wc_method);
        if (!empty($mapped_mode)) {
            return $mapped_mode;
        }

        // Fall back to default mappings.
        $default_mappings = [
            'paypal' => 'PayPal',
            'stripe' => 'Credit Card',
            'stripe_cc' => 'Credit Card',
            'bacs' => 'Bank Transfer',
            'cheque' => 'Check',
            'cod' => 'Cash',
            'square' => 'Credit Card',
            'braintree' => 'Credit Card',
            'amazon_payments_advanced' => 'Amazon Pay',
        ];

        // Allow filtering payment mode mapping.
        $default_mappings = apply_filters('zbooks_payment_mode_mapping', $default_mappings);

        return $default_mappings[$wc_method] ?? 'Others';
    }

    /**
     * Get payment reference number from order.
     *
     * @param WC_Order $order WooCommerce order.
     * @return string Reference number.
     */
    private function get_payment_reference(WC_Order $order): string {
        // Try to get transaction ID first.
        $transaction_id = $order->get_transaction_id();
        if (!empty($transaction_id)) {
            return $transaction_id;
        }

        // Fallback to order number.
        return $order->get_order_number();
    }

    /**
     * Get payment details from Zoho.
     *
     * @param string $payment_id Zoho payment ID.
     * @return array|null Payment data or null.
     */
    public function get_payment(string $payment_id): ?array {
        try {
            $response = $this->client->request(function ($client) use ($payment_id) {
                return $client->customerpayments->get($payment_id);
            });

            if (is_array($response)) {
                return $response['payment'] ?? $response;
            }

            if (method_exists($response, 'toArray')) {
                return $response->toArray();
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get payment', [
                'payment_id' => $payment_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Find payment by reference number.
     *
     * @param string $reference Reference number.
     * @return string|null Payment ID or null.
     */
    public function find_payment_by_reference(string $reference): ?string {
        try {
            $payments = $this->client->request(function ($client) use ($reference) {
                return $client->customerpayments->getList([
                    'reference_number' => $reference,
                ]);
            });

            $list = is_array($payments) ? ($payments['customerpayments'] ?? $payments) : [];

            if (method_exists($payments, 'toArray')) {
                $list = $payments->toArray();
            }

            if (!empty($list) && isset($list[0]['payment_id'])) {
                return (string) $list[0]['payment_id'];
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to search for payment', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Delete a payment from Zoho.
     *
     * @param string $payment_id Zoho payment ID.
     * @return bool True on success.
     */
    public function delete_payment(string $payment_id): bool {
        try {
            $this->client->request(function ($client) use ($payment_id) {
                return $client->customerpayments->delete($payment_id);
            });

            $this->logger->info('Payment deleted', [
                'payment_id' => $payment_id,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete payment', [
                'payment_id' => $payment_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
