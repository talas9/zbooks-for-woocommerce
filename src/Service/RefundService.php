<?php
/**
 * Refund service for Zoho Books.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

use WC_Order;
use WC_Order_Refund;
use Zbooks\Api\ZohoClient;
use Zbooks\Logger\SyncLogger;

defined('ABSPATH') || exit;

/**
 * Service for managing Zoho Books refunds and credit notes.
 */
class RefundService {

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
     * Constructor.
     *
     * @param ZohoClient $client Zoho client instance.
     * @param SyncLogger $logger Logger instance.
     */
    public function __construct(ZohoClient $client, SyncLogger $logger) {
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * Process a WooCommerce refund.
     *
     * Creates a credit note in Zoho Books and optionally applies it as a refund.
     *
     * @param WC_Order        $order      WooCommerce order.
     * @param WC_Order_Refund $refund     WooCommerce refund.
     * @param string          $invoice_id Zoho invoice ID.
     * @param string          $contact_id Zoho contact ID.
     * @return array{success: bool, credit_note_id: ?string, refund_id: ?string, error: ?string}
     */
    public function process_refund(
        WC_Order $order,
        WC_Order_Refund $refund,
        string $invoice_id,
        string $contact_id
    ): array {
        $refund_id = $refund->get_id();
        $refund_amount = abs((float) $refund->get_total());

        $this->logger->info('Processing refund', [
            'order_id' => $order->get_id(),
            'refund_id' => $refund_id,
            'amount' => $refund_amount,
            'invoice_id' => $invoice_id,
        ]);

        // Skip zero amount refunds.
        if ($refund_amount <= 0) {
            $this->logger->debug('Skipping zero amount refund', [
                'refund_id' => $refund_id,
            ]);
            return [
                'success' => true,
                'credit_note_id' => null,
                'refund_id' => null,
                'error' => null,
            ];
        }

        try {
            // Step 1: Create credit note from invoice.
            $credit_note_id = $this->create_credit_note($order, $refund, $invoice_id, $contact_id);

            if (!$credit_note_id) {
                return [
                    'success' => false,
                    'credit_note_id' => null,
                    'refund_id' => null,
                    'error' => __('Failed to create credit note', 'zbooks-for-woocommerce'),
                ];
            }

            // Step 2: Apply credit note to invoice.
            $this->apply_credit_to_invoice($credit_note_id, $invoice_id, $refund_amount);

            // Step 3: Create refund (actual money back to customer) if enabled.
            $zoho_refund_id = null;
            $refund_settings = get_option('zbooks_refund_settings', ['create_cash_refund' => true]);
            if (!empty($refund_settings['create_cash_refund'])) {
                $zoho_refund_id = $this->create_refund_from_credit($credit_note_id, $refund_amount, $order);
            } else {
                $this->logger->debug('Cash refund creation skipped (disabled in settings)', [
                    'credit_note_id' => $credit_note_id,
                ]);
            }

            $this->logger->info('Refund processed successfully', [
                'order_id' => $order->get_id(),
                'refund_id' => $refund_id,
                'credit_note_id' => $credit_note_id,
                'zoho_refund_id' => $zoho_refund_id,
            ]);

            return [
                'success' => true,
                'credit_note_id' => $credit_note_id,
                'refund_id' => $zoho_refund_id,
                'error' => null,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to process refund', [
                'order_id' => $order->get_id(),
                'refund_id' => $refund_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'credit_note_id' => null,
                'refund_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a credit note in Zoho Books.
     *
     * @param WC_Order        $order      WooCommerce order.
     * @param WC_Order_Refund $refund     WooCommerce refund.
     * @param string          $invoice_id Zoho invoice ID.
     * @param string          $contact_id Zoho contact ID.
     * @return string|null Credit note ID or null on failure.
     */
    private function create_credit_note(
        WC_Order $order,
        WC_Order_Refund $refund,
        string $invoice_id,
        string $contact_id
    ): ?string {
        $refund_amount = abs((float) $refund->get_total());
        $refund_reason = $refund->get_reason() ?: __('Refund', 'zbooks-for-woocommerce');

        // Build credit note data.
        $credit_note_data = [
            'customer_id' => $contact_id,
            'creditnote_number' => sprintf('CN-%s-%d', $order->get_order_number(), $refund->get_id()),
            'reference_number' => sprintf('Refund for Order #%s', $order->get_order_number()),
            'date' => gmdate('Y-m-d'),
            'line_items' => [
                [
                    'name' => sprintf(
                        /* translators: %s: Order number */
                        __('Refund for Order #%s', 'zbooks-for-woocommerce'),
                        $order->get_order_number()
                    ),
                    'description' => $refund_reason,
                    'quantity' => 1,
                    'rate' => $refund_amount,
                ],
            ],
            'notes' => $refund_reason,
        ];

        // Add refund line items if available.
        $refund_items = $refund->get_items();
        if (!empty($refund_items)) {
            $credit_note_data['line_items'] = $this->map_refund_items($refund);
        }

        try {
            $response = $this->client->request(function ($client) use ($credit_note_data) {
                return $client->creditnotes->create($credit_note_data);
            });

            $credit_note_id = $response['creditnote_id']
                ?? $response['credit_note']['creditnote_id']
                ?? null;

            if ($credit_note_id) {
                $this->logger->debug('Credit note created', [
                    'credit_note_id' => $credit_note_id,
                ]);
            }

            return $credit_note_id ? (string) $credit_note_id : null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create credit note', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Map WooCommerce refund items to Zoho line items.
     *
     * @param WC_Order_Refund $refund WooCommerce refund.
     * @return array Line items.
     */
    private function map_refund_items(WC_Order_Refund $refund): array {
        $line_items = [];

        foreach ($refund->get_items() as $item) {
            $quantity = abs($item->get_quantity());
            if ($quantity <= 0) {
                continue;
            }

            $total = abs((float) $item->get_total());
            $rate = $quantity > 0 ? $total / $quantity : $total;

            $line_items[] = [
                'name' => $item->get_name(),
                'quantity' => $quantity,
                'rate' => round($rate, 2),
            ];
        }

        // Add refunded shipping.
        $shipping_total = abs((float) $refund->get_shipping_total());
        if ($shipping_total > 0) {
            $line_items[] = [
                'name' => __('Shipping Refund', 'zbooks-for-woocommerce'),
                'quantity' => 1,
                'rate' => $shipping_total,
            ];
        }

        // If no items, add a generic refund line.
        if (empty($line_items)) {
            $line_items[] = [
                'name' => __('Refund', 'zbooks-for-woocommerce'),
                'quantity' => 1,
                'rate' => abs((float) $refund->get_total()),
            ];
        }

        return $line_items;
    }

    /**
     * Apply credit note to an invoice.
     *
     * @param string $credit_note_id Credit note ID.
     * @param string $invoice_id     Invoice ID.
     * @param float  $amount         Amount to apply.
     */
    private function apply_credit_to_invoice(
        string $credit_note_id,
        string $invoice_id,
        float $amount
    ): void {
        try {
            $this->client->request(function ($client) use ($credit_note_id, $invoice_id, $amount) {
                return $client->creditnotes->applyToInvoices($credit_note_id, [
                    'invoices' => [
                        [
                            'invoice_id' => $invoice_id,
                            'amount_applied' => $amount,
                        ],
                    ],
                ]);
            });

            $this->logger->debug('Credit note applied to invoice', [
                'credit_note_id' => $credit_note_id,
                'invoice_id' => $invoice_id,
                'amount' => $amount,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to apply credit note to invoice', [
                'credit_note_id' => $credit_note_id,
                'invoice_id' => $invoice_id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - credit note still exists.
        }
    }

    /**
     * Create a refund from credit note balance.
     *
     * @param string   $credit_note_id Credit note ID.
     * @param float    $amount         Refund amount.
     * @param WC_Order $order          WooCommerce order.
     * @return string|null Refund ID or null.
     */
    private function create_refund_from_credit(
        string $credit_note_id,
        float $amount,
        WC_Order $order
    ): ?string {
        try {
            $refund_data = [
                'date' => gmdate('Y-m-d'),
                'refund_mode' => $this->map_refund_mode($order->get_payment_method()),
                'amount' => $amount,
                'description' => sprintf(
                    /* translators: %s: Order number */
                    __('Refund for Order #%s', 'zbooks-for-woocommerce'),
                    $order->get_order_number()
                ),
            ];

            $response = $this->client->request(
                function ($client) use ($credit_note_id, $refund_data) {
                    return $client->creditnotes->addRefund($credit_note_id, $refund_data);
                }
            );

            $refund_id = $response['creditnote_refund_id']
                ?? $response['creditnote_refund']['creditnote_refund_id']
                ?? null;

            return $refund_id ? (string) $refund_id : null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to create refund from credit note', [
                'credit_note_id' => $credit_note_id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - credit note still exists and is applied.
            return null;
        }
    }

    /**
     * Map WooCommerce payment method to Zoho refund mode.
     *
     * @param string $wc_method WooCommerce payment method slug.
     * @return string Zoho refund mode.
     */
    private function map_refund_mode(string $wc_method): string {
        $mappings = [
            'paypal' => 'PayPal',
            'stripe' => 'Credit Card',
            'stripe_cc' => 'Credit Card',
            'bacs' => 'Bank Transfer',
            'cheque' => 'Check',
            'cod' => 'Cash',
        ];

        $mappings = apply_filters('zbooks_refund_mode_mapping', $mappings);

        return $mappings[$wc_method] ?? 'Others';
    }

    /**
     * Get credit note details from Zoho.
     *
     * @param string $credit_note_id Zoho credit note ID.
     * @return array|null Credit note data or null.
     */
    public function get_credit_note(string $credit_note_id): ?array {
        try {
            $response = $this->client->request(function ($client) use ($credit_note_id) {
                return $client->creditnotes->get($credit_note_id);
            });

            if (is_array($response)) {
                return $response['credit_note'] ?? $response;
            }

            if (method_exists($response, 'toArray')) {
                return $response->toArray();
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get credit note', [
                'credit_note_id' => $credit_note_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Void/delete a credit note.
     *
     * @param string $credit_note_id Credit note ID.
     * @return bool True on success.
     */
    public function void_credit_note(string $credit_note_id): bool {
        try {
            $this->client->request(function ($client) use ($credit_note_id) {
                return $client->creditnotes->void($credit_note_id);
            });

            $this->logger->info('Credit note voided', [
                'credit_note_id' => $credit_note_id,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to void credit note', [
                'credit_note_id' => $credit_note_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
