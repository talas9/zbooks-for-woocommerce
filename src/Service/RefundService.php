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
use Zbooks\Repository\FieldMappingRepository;

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
     * @param FieldMappingRepository $field_mapping Field mapping repository.
     */
    public function __construct(
        ZohoClient $client,
        SyncLogger $logger,
        FieldMappingRepository $field_mapping
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->field_mapping = $field_mapping;
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
     * @return array{success: bool, credit_note_id: ?string, credit_note_number: ?string, refund_id: ?string, error: ?string}
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
            'order_number' => $order->get_order_number(),
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
                'credit_note_number' => null,
                'refund_id' => null,
                'error' => null,
            ];
        }

        try {
            // Step 1: Create credit note from invoice.
            $credit_note_result = $this->create_credit_note($order, $refund, $invoice_id, $contact_id);

            if (!$credit_note_result['id']) {
                return [
                    'success' => false,
                    'credit_note_id' => null,
                    'credit_note_number' => null,
                    'refund_id' => null,
                    'error' => __('Failed to create credit note', 'zbooks-for-woocommerce'),
                ];
            }

            $credit_note_id = $credit_note_result['id'];
            $credit_note_number = $credit_note_result['number'];

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
                'order_number' => $order->get_order_number(),
                'refund_id' => $refund_id,
                'amount' => $refund_amount,
                'credit_note_id' => $credit_note_id,
                'credit_note_number' => $credit_note_number,
                'zoho_refund_id' => $zoho_refund_id,
            ]);

            return [
                'success' => true,
                'credit_note_id' => $credit_note_id,
                'credit_note_number' => $credit_note_number,
                'refund_id' => $zoho_refund_id,
                'error' => null,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to process refund', [
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'refund_id' => $refund_id,
                'amount' => $refund_amount,
                'invoice_id' => $invoice_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'credit_note_id' => null,
                'credit_note_number' => null,
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
     * @return array{id: ?string, number: ?string} Credit note ID and number.
     */
    private function create_credit_note(
        WC_Order $order,
        WC_Order_Refund $refund,
        string $invoice_id,
        string $contact_id
    ): array {
        $refund_amount = abs((float) $refund->get_total());
        $refund_reason = $refund->get_reason() ?: __('Refund', 'zbooks-for-woocommerce');

        // Build credit note data (let Zoho auto-generate the credit note number).
        $credit_note_data = [
            'customer_id' => $contact_id,
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

        // Add custom fields from mappings.
        $custom_fields = $this->field_mapping->build_custom_fields($order, 'creditnote', $refund);
        if (!empty($custom_fields)) {
            $credit_note_data['custom_fields'] = $custom_fields;
        }

        try {
            $response = $this->client->request(function ($client) use ($credit_note_data) {
                return $client->creditnotes->create($credit_note_data);
            }, [
                'endpoint' => 'creditnotes.create',
                'order_id' => $order->get_id(),
                'refund_id' => $refund->get_id(),
                'refund_amount' => $refund_amount,
            ]);

            // Convert object to array if needed.
            if (is_object($response)) {
                $response = json_decode(wp_json_encode($response), true);
            }

            $credit_note = $response['creditnote'] ?? $response['credit_note'] ?? $response;

            $credit_note_id = $credit_note['creditnote_id'] ?? null;
            $credit_note_number = $credit_note['creditnote_number'] ?? null;

            if ($credit_note_id) {
                $this->logger->debug('Credit note created', [
                    'credit_note_id' => $credit_note_id,
                    'credit_note_number' => $credit_note_number,
                ]);
            }

            return [
                'id' => $credit_note_id ? (string) $credit_note_id : null,
                'number' => $credit_note_number,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to create credit note', [
                'order_id' => $order->get_id(),
                'refund_id' => $refund->get_id(),
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
     * Tries two approaches:
     * 1. POST /invoices/{invoice_id}/credits - requires ZohoBooks.invoices.UPDATE scope
     * 2. POST /creditnotes/{credit_note_id}/invoices - alternative endpoint
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
        // Check invoice status first - credits can't be applied to draft/closed/void invoices.
        $status_check = $this->can_apply_credit_to_invoice($invoice_id);
        if (!$status_check['can_apply']) {
            $this->logger->warning('Cannot apply credit note due to invoice status', [
                'credit_note_id' => $credit_note_id,
                'invoice_id' => $invoice_id,
                'invoice_status' => $status_check['status'],
                'reason' => $status_check['reason'],
            ]);
            return;
        }

        // Try the invoice-centric endpoint first (often has better permissions).
        try {
            $this->client->raw_request('POST', '/invoices/' . $invoice_id . '/credits', [
                'apply_creditnotes' => [
                    [
                        'creditnote_id' => $credit_note_id,
                        'amount_applied' => $amount,
                    ],
                ],
            ]);

            $this->logger->debug('Credit note applied to invoice via invoice endpoint', [
                'credit_note_id' => $credit_note_id,
                'invoice_id' => $invoice_id,
                'amount' => $amount,
            ]);
            return;
        } catch (\Exception $e) {
            $this->logger->debug('Invoice endpoint failed, trying creditnote endpoint', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to credit note endpoint.
        try {
            $this->client->raw_request('POST', '/creditnotes/' . $credit_note_id . '/invoices', [
                'invoices' => [
                    [
                        'invoice_id' => $invoice_id,
                        'amount_applied' => $amount,
                    ],
                ],
            ]);

            $this->logger->debug('Credit note applied to invoice via creditnote endpoint', [
                'credit_note_id' => $credit_note_id,
                'invoice_id' => $invoice_id,
                'amount' => $amount,
            ]);
        } catch (\Exception $e) {
            $error_msg = $e->getMessage();

            // Check for specific error conditions.
            if (strpos($error_msg, 'not authorized') !== false) {
                $this->logger->warning('Credit note application requires additional OAuth scopes', [
                    'credit_note_id' => $credit_note_id,
                    'invoice_id' => $invoice_id,
                    'required_scopes' => 'ZohoBooks.invoices.UPDATE, ZohoBooks.creditnotes.UPDATE',
                    'error' => $error_msg,
                ]);
            } elseif (strpos($error_msg, 'draft') !== false) {
                $this->logger->warning('Cannot apply credit to draft invoice', [
                    'invoice_id' => $invoice_id,
                ]);
            } else {
                $this->logger->warning('Failed to apply credit note to invoice', [
                    'credit_note_id' => $credit_note_id,
                    'invoice_id' => $invoice_id,
                    'error' => $error_msg,
                ]);
            }
            // Don't throw - credit note still exists and can be applied manually.
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
            // Get default bank account for refunds.
            $refund_settings = get_option('zbooks_refund_settings', []);
            $account_id = $refund_settings['refund_account_id'] ?? null;

            // If no account configured, try to get the default from payment mappings.
            if (empty($account_id)) {
                $payment_method = $order->get_payment_method();
                $payment_mappings = get_option('zbooks_payment_method_mappings', []);
                if (!empty($payment_mappings[$payment_method]['zoho_account_id'])) {
                    $account_id = $payment_mappings[$payment_method]['zoho_account_id'];
                }
            }

            if (empty($account_id)) {
                $this->logger->debug('No refund account configured, skipping refund record creation', [
                    'credit_note_id' => $credit_note_id,
                ]);
                return null;
            }

            $refund_mode = $this->map_refund_mode($order->get_payment_method());

            $refund_data = [
                'date' => gmdate('Y-m-d'),
                'refund_mode' => $refund_mode,
                'amount' => $amount,
                'account_id' => $account_id,
                'description' => sprintf(
                    /* translators: %s: Order number */
                    __('Refund for Order #%s', 'zbooks-for-woocommerce'),
                    $order->get_order_number()
                ),
            ];

            $this->logger->debug('Creating credit note refund', [
                'credit_note_id' => $credit_note_id,
                'amount' => $amount,
                'account_id' => $account_id,
            ]);

            $response = $this->client->raw_request(
                'POST',
                '/creditnotes/' . $credit_note_id . '/refunds',
                $refund_data
            );

            $refund_id = $response['creditnote_refund']['creditnote_refund_id']
                ?? $response['refund']['refund_id']
                ?? null;

            if ($refund_id) {
                $this->logger->info('Credit note refund created', [
                    'credit_note_id' => $credit_note_id,
                    'refund_id' => $refund_id,
                ]);
            }

            return $refund_id ? (string) $refund_id : null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to create credit note refund', [
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
     * Get invoice details from Zoho.
     *
     * @param string $invoice_id Zoho invoice ID.
     * @return array|null Invoice data or null.
     */
    private function get_invoice(string $invoice_id): ?array {
        try {
            $response = $this->client->request(function ($client) use ($invoice_id) {
                return $client->invoices->get($invoice_id);
            }, [
                'endpoint' => 'invoices.get',
                'invoice_id' => $invoice_id,
            ]);

            if (is_object($response) && method_exists($response, 'toArray')) {
                return $response->toArray();
            }

            if (is_array($response)) {
                return $response['invoice'] ?? $response;
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get invoice', [
                'invoice_id' => $invoice_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if invoice status allows credit note application.
     *
     * Credits cannot be applied to draft, closed, or void invoices.
     *
     * @param string $invoice_id Invoice ID.
     * @return array{can_apply: bool, status: string, reason: ?string}
     */
    private function can_apply_credit_to_invoice(string $invoice_id): array {
        $invoice = $this->get_invoice($invoice_id);

        if (!$invoice) {
            return [
                'can_apply' => false,
                'status' => 'unknown',
                'reason' => 'Could not retrieve invoice details',
            ];
        }

        $status = strtolower($invoice['status'] ?? 'unknown');

        // Statuses that don't allow credit application.
        $blocked_statuses = ['draft', 'closed', 'void'];

        if (in_array($status, $blocked_statuses, true)) {
            $reasons = [
                'draft' => 'Credits cannot be applied to draft invoices. Mark the invoice as sent first.',
                'closed' => 'Credits cannot be applied to closed invoices.',
                'void' => 'Credits cannot be applied to void invoices.',
            ];

            return [
                'can_apply' => false,
                'status' => $status,
                'reason' => $reasons[$status] ?? 'Invoice status does not allow credit application',
            ];
        }

        return [
            'can_apply' => true,
            'status' => $status,
            'reason' => null,
        ];
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
            }, [
                'endpoint' => 'creditnotes.get',
                'credit_note_id' => $credit_note_id,
            ]);

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
            }, [
                'endpoint' => 'creditnotes.void',
                'credit_note_id' => $credit_note_id,
            ]);

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
