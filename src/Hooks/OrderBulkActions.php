<?php
/**
 * Order bulk actions.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Hooks;

use Zbooks\Service\BulkSyncService;

defined('ABSPATH') || exit;

/**
 * Adds bulk sync actions to WooCommerce orders list.
 */
class OrderBulkActions {

    /**
     * Bulk sync service.
     *
     * @var BulkSyncService
     */
    private BulkSyncService $bulk_sync_service;

    /**
     * Constructor.
     *
     * @param BulkSyncService $bulk_sync_service Bulk sync service.
     */
    public function __construct(BulkSyncService $bulk_sync_service) {
        $this->bulk_sync_service = $bulk_sync_service;
        $this->register_hooks();
    }

    /**
     * Register hooks.
     */
    private function register_hooks(): void {
        // Add bulk action to legacy orders screen.
        add_filter('bulk_actions-edit-shop_order', [$this, 'add_bulk_action']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_bulk_action'], 10, 3);

        // Add bulk action to HPOS orders screen.
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'add_bulk_action']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handle_bulk_action'], 10, 3);

        // Display admin notices for bulk action results.
        add_action('admin_notices', [$this, 'display_bulk_action_notices']);
    }

    /**
     * Add bulk action to the dropdown.
     *
     * @param array $actions Existing bulk actions.
     * @return array Modified bulk actions.
     */
    public function add_bulk_action(array $actions): array {
        $actions['zbooks_sync'] = __('Sync to Zoho Books', 'zbooks-for-woocommerce');
        $actions['zbooks_sync_draft'] = __('Sync to Zoho Books (Draft)', 'zbooks-for-woocommerce');
        return $actions;
    }

    /**
     * Handle the bulk action.
     *
     * @param string $redirect_url Redirect URL.
     * @param string $action       Action name.
     * @param array  $order_ids    Selected order IDs.
     * @return string Modified redirect URL.
     */
    public function handle_bulk_action(string $redirect_url, string $action, array $order_ids): string {
        if (!in_array($action, ['zbooks_sync', 'zbooks_sync_draft'], true)) {
            return $redirect_url;
        }

        if (empty($order_ids)) {
            return $redirect_url;
        }

        if (!current_user_can('edit_shop_orders')) {
            return $redirect_url;
        }

        $as_draft = $action === 'zbooks_sync_draft';
        $results = $this->bulk_sync_service->sync_orders($order_ids, $as_draft);

        // Add query args to show notice.
        $redirect_url = add_query_arg([
            'zbooks_bulk_synced' => $results['success'],
            'zbooks_bulk_failed' => $results['failed'],
        ], $redirect_url);

        return $redirect_url;
    }

    /**
     * Display admin notices after bulk action.
     */
    public function display_bulk_action_notices(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['zbooks_bulk_synced'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $synced = absint($_GET['zbooks_bulk_synced']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $failed = isset($_GET['zbooks_bulk_failed']) ? absint($_GET['zbooks_bulk_failed']) : 0;

        if ($synced > 0) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %d: number of orders synced */
                        _n(
                            '%d order synced to Zoho Books.',
                            '%d orders synced to Zoho Books.',
                            $synced,
                            'zbooks-for-woocommerce'
                        ),
                        $synced
                    )
                )
            );
        }

        if ($failed > 0) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %d: number of orders that failed */
                        _n(
                            '%d order failed to sync. Check the logs for details.',
                            '%d orders failed to sync. Check the logs for details.',
                            $failed,
                            'zbooks-for-woocommerce'
                        ),
                        $failed
                    )
                )
            );
        }
    }
}
