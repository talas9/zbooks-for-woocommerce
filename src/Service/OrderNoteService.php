<?php
/**
 * Order note service.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

use WC_Order;
use Zbooks\Helper\ZohoUrlHelper;
use Zbooks\Model\SyncStatus;

defined('ABSPATH') || exit;

/**
 * Service for adding WooCommerce order notes related to Zoho sync.
 */
class OrderNoteService {

    /**
     * Add note when invoice is created.
     *
     * @param WC_Order   $order      WooCommerce order.
     * @param string     $invoice_id Zoho invoice ID.
     * @param SyncStatus $status     Sync status (DRAFT or SYNCED).
     */
    public function add_invoice_created_note(WC_Order $order, string $invoice_id, SyncStatus $status): void {
        $link = ZohoUrlHelper::link('invoice', $invoice_id, $invoice_id);

        if ($status === SyncStatus::DRAFT) {
            $note = sprintf(
                /* translators: %s: Invoice link */
                __('Order synced to Zoho Books: Invoice %s (draft)', 'zbooks-for-woocommerce'),
                $link
            );
        } else {
            $note = sprintf(
                /* translators: %s: Invoice link */
                __('Order synced to Zoho Books: Invoice %s', 'zbooks-for-woocommerce'),
                $link
            );
        }

        $order->add_order_note($note);
    }

    /**
     * Add note when payment is applied.
     *
     * @param WC_Order $order      WooCommerce order.
     * @param string   $payment_id Zoho payment ID.
     * @param string   $invoice_id Zoho invoice ID.
     */
    public function add_payment_applied_note(WC_Order $order, string $payment_id, string $invoice_id): void {
        $invoice_link = ZohoUrlHelper::link('invoice', $invoice_id, $invoice_id);
        $payment_link = ZohoUrlHelper::link('payment', $payment_id, $payment_id);

        $note = sprintf(
            /* translators: 1: Payment link, 2: Invoice link */
            __('Payment recorded in Zoho Books: %1$s for Invoice %2$s (paid)', 'zbooks-for-woocommerce'),
            $payment_link,
            $invoice_link
        );

        $order->add_order_note($note);
    }

    /**
     * Add note when credit note is created.
     *
     * @param WC_Order    $order          WooCommerce order.
     * @param string      $credit_note_id Zoho credit note ID.
     * @param float       $amount         Refund amount.
     * @param string|null $refund_id      Zoho refund ID (if created).
     */
    public function add_credit_note_created_note(
        WC_Order $order,
        string $credit_note_id,
        float $amount,
        ?string $refund_id = null
    ): void {
        $credit_note_link = ZohoUrlHelper::link('creditnote', $credit_note_id, $credit_note_id);
        $formatted_amount = wc_price($amount);

        if (!empty($refund_id)) {
            $note = sprintf(
                /* translators: 1: Credit note link, 2: Amount */
                __('Credit note created in Zoho Books: %1$s for %2$s (refunded)', 'zbooks-for-woocommerce'),
                $credit_note_link,
                $formatted_amount
            );
        } else {
            $note = sprintf(
                /* translators: 1: Credit note link, 2: Amount */
                __('Credit note created in Zoho Books: %1$s for %2$s', 'zbooks-for-woocommerce'),
                $credit_note_link,
                $formatted_amount
            );
        }

        $order->add_order_note($note);
    }

    /**
     * Add note when sync fails.
     *
     * @param WC_Order $order WooCommerce order.
     * @param string   $error Error message.
     */
    public function add_sync_failed_note(WC_Order $order, string $error): void {
        $note = sprintf(
            /* translators: %s: Error message */
            __('Zoho Books sync failed: %s', 'zbooks-for-woocommerce'),
            $error
        );

        $order->add_order_note($note);
    }

    /**
     * Add note when existing invoice is linked.
     *
     * @param WC_Order $order      WooCommerce order.
     * @param string   $invoice_id Zoho invoice ID.
     */
    public function add_invoice_linked_note(WC_Order $order, string $invoice_id): void {
        $link = ZohoUrlHelper::link('invoice', $invoice_id, $invoice_id);

        $note = sprintf(
            /* translators: %s: Invoice link */
            __('Order linked to existing Zoho Books invoice: %s', 'zbooks-for-woocommerce'),
            $link
        );

        $order->add_order_note($note);
    }
}
