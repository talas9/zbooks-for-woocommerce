<?php
/**
 * Order meta box.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use WC_Order;
use Zbooks\Helper\ZohoUrlHelper;
use Zbooks\Repository\OrderMetaRepository;

defined('ABSPATH') || exit;

/**
 * Meta box for displaying sync status on order page.
 */
class OrderMetaBox {

    /**
     * Repository.
     *
     * @var OrderMetaRepository
     */
    private OrderMetaRepository $repository;

    /**
     * Constructor.
     *
     * @param OrderMetaRepository $repository Order meta repository.
     */
    public function __construct(OrderMetaRepository $repository) {
        $this->repository = $repository;
        $this->register_hooks();
    }

    /**
     * Register hooks.
     */
    private function register_hooks(): void {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
    }

    /**
     * Add meta box to order page.
     */
    public function add_meta_box(): void {
        // Add to HPOS orders screen if available.
        if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            add_meta_box(
                'zbooks_sync_status',
                __('Zoho Books Sync', 'zbooks-for-woocommerce'),
                [$this, 'render_meta_box'],
                'woocommerce_page_wc-orders',
                'side',
                'default'
            );
        }

        // Also add to legacy shop_order post type for backwards compatibility.
        add_meta_box(
            'zbooks_sync_status',
            __('Zoho Books Sync', 'zbooks-for-woocommerce'),
            [$this, 'render_meta_box'],
            'shop_order',
            'side',
            'default'
        );
    }

    /**
     * Render meta box content.
     *
     * @param WC_Order $order WooCommerce order.
     */
    public function render_meta_box($order): void {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order->ID);
        }

        if (!$order) {
            return;
        }

        $status = $this->repository->get_sync_status($order);
        $invoice_id = $this->repository->get_invoice_id($order);
        $contact_id = $this->repository->get_contact_id($order);
        $payment_id = $this->repository->get_payment_id($order);
        $refunds = $this->repository->get_refund_ids($order);
        $error = $this->repository->get_sync_error($order);
        $last_attempt = $this->repository->get_last_sync_attempt($order);
        ?>
        <div class="zbooks-meta-box">
            <p>
                <strong><?php esc_html_e('Status:', 'zbooks-for-woocommerce'); ?></strong>
                <?php if ($status) : ?>
                    <span class="zbooks-status <?php echo esc_attr($status->css_class()); ?>">
                        <?php echo esc_html($status->label()); ?>
                    </span>
                <?php else : ?>
                    <span class="zbooks-status zbooks-status-none">
                        <?php esc_html_e('Not synced', 'zbooks-for-woocommerce'); ?>
                    </span>
                <?php endif; ?>
            </p>

            <?php if ($invoice_id) : ?>
                <p>
                    <strong><?php esc_html_e('Invoice ID:', 'zbooks-for-woocommerce'); ?></strong>
                    <a href="<?php echo esc_url(ZohoUrlHelper::invoice($invoice_id)); ?>" target="_blank">
                        <?php echo esc_html($invoice_id); ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if ($contact_id) : ?>
                <p>
                    <strong><?php esc_html_e('Customer:', 'zbooks-for-woocommerce'); ?></strong>
                    <a href="<?php echo esc_url(ZohoUrlHelper::contact($contact_id)); ?>" target="_blank" style="font-size: 12px;">
                        <?php echo esc_html($contact_id); ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if ($payment_id) : ?>
                <p>
                    <strong><?php esc_html_e('Payment ID:', 'zbooks-for-woocommerce'); ?></strong>
                    <a href="<?php echo esc_url(ZohoUrlHelper::payment($payment_id)); ?>" target="_blank">
                        <?php echo esc_html($payment_id); ?>
                    </a>
                    <span class="zbooks-status zbooks-status-synced" style="font-size: 11px; margin-left: 5px;">
                        <?php esc_html_e('Paid', 'zbooks-for-woocommerce'); ?>
                    </span>
                </p>
            <?php elseif ($invoice_id) : ?>
                <p>
                    <strong><?php esc_html_e('Payment:', 'zbooks-for-woocommerce'); ?></strong>
                    <span style="color: #dba617;"><?php esc_html_e('Not recorded', 'zbooks-for-woocommerce'); ?></span>
                </p>
            <?php endif; ?>

            <?php if (!empty($refunds)) : ?>
                <div class="zbooks-refunds" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
                    <strong><?php esc_html_e('Refunds / Credit Notes:', 'zbooks-for-woocommerce'); ?></strong>
                    <?php foreach ($refunds as $refund_data) :
                        $wc_refund = wc_get_order($refund_data['refund_id']);
                        $refund_amount = $wc_refund ? $wc_refund->get_amount() : null;
                        ?>
                        <div style="margin: 8px 0; padding: 8px; background: #f9f9f9; border-left: 3px solid #dba617;">
                            <div>
                                <strong>
                                    <?php
                                    printf(
                                        /* translators: %s: refund ID */
                                        esc_html__('WC Refund #%s', 'zbooks-for-woocommerce'),
                                        esc_html($refund_data['refund_id'])
                                    );
                                    ?>
                                </strong>
                                <?php if ($refund_amount) : ?>
                                    <span style="color: #d63638; margin-left: 5px;">
                                        -<?php echo wp_kses_post(wc_price($refund_amount)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($refund_data['zoho_credit_note_id'])) : ?>
                                <div style="margin-top: 4px; font-size: 12px;">
                                    <span style="color: #646970;"><?php esc_html_e('Credit Note:', 'zbooks-for-woocommerce'); ?></span>
                                    <a href="<?php echo esc_url(ZohoUrlHelper::credit_note($refund_data['zoho_credit_note_id'])); ?>" target="_blank">
                                        <?php echo esc_html($refund_data['zoho_credit_note_id']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($refund_data['zoho_refund_id'])) : ?>
                                <div style="margin-top: 2px; font-size: 12px;">
                                    <span style="color: #646970;"><?php esc_html_e('Zoho Refund:', 'zbooks-for-woocommerce'); ?></span>
                                    <?php echo esc_html($refund_data['zoho_refund_id']); ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($refund_data['created_at'])) : ?>
                                <div style="margin-top: 2px; font-size: 11px; color: #888;">
                                    <?php echo esc_html($refund_data['created_at']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($error) : ?>
                <p class="zbooks-error">
                    <strong><?php esc_html_e('Error:', 'zbooks-for-woocommerce'); ?></strong>
                    <?php echo esc_html($error); ?>
                </p>
            <?php endif; ?>

            <?php if ($last_attempt) : ?>
                <p>
                    <strong><?php esc_html_e('Last attempt:', 'zbooks-for-woocommerce'); ?></strong>
                    <?php echo esc_html($last_attempt->format('Y-m-d H:i:s')); ?>
                </p>
            <?php endif; ?>

            <hr>

            <p>
                <button type="button"
                    class="button zbooks-sync-btn"
                    data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                    data-draft="false">
                    <?php esc_html_e('Sync Now', 'zbooks-for-woocommerce'); ?>
                </button>
                <button type="button"
                    class="button zbooks-sync-btn"
                    data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                    data-draft="true">
                    <?php esc_html_e('Sync as Draft', 'zbooks-for-woocommerce'); ?>
                </button>
            </p>

            <?php if ($invoice_id && !$payment_id && $order->is_paid()) : ?>
                <p>
                    <button type="button"
                        class="button zbooks-apply-payment-btn"
                        data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                        <?php esc_html_e('Apply Payment', 'zbooks-for-woocommerce'); ?>
                    </button>
                </p>
            <?php endif; ?>

            <p class="zbooks-sync-result"></p>
        </div>
        <?php
    }

}
