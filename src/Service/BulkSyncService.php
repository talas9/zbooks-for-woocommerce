<?php
/**
 * Bulk sync service.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

use Zbooks\Logger\SyncLogger;
use Zbooks\Model\SyncResult;
use Zbooks\Repository\OrderMetaRepository;

defined('ABSPATH') || exit;

/**
 * Service for bulk syncing orders.
 */
class BulkSyncService {

    /**
     * Sync orchestrator.
     *
     * @var SyncOrchestrator
     */
    private SyncOrchestrator $orchestrator;

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
     * Constructor.
     *
     * @param SyncOrchestrator    $orchestrator Sync orchestrator.
     * @param OrderMetaRepository $repository   Order meta repository.
     * @param SyncLogger          $logger       Logger.
     */
    public function __construct(
        SyncOrchestrator $orchestrator,
        OrderMetaRepository $repository,
        SyncLogger $logger
    ) {
        $this->orchestrator = $orchestrator;
        $this->repository = $repository;
        $this->logger = $logger;
    }

    /**
     * Get orders available for bulk sync.
     *
     * @param string|null $date_from Start date.
     * @param string|null $date_to   End date.
     * @param int         $limit     Max orders.
     * @return array
     */
    public function get_syncable_orders(
        ?string $date_from = null,
        ?string $date_to = null,
        int $limit = 100
    ): array {
        return $this->repository->get_unsynced_orders($date_from, $date_to, $limit);
    }

    /**
     * Sync multiple orders.
     *
     * @param array $order_ids Order IDs to sync.
     * @param bool  $as_draft  Create as draft.
     * @return array{success: int, failed: int, results: array}
     */
    public function sync_orders(array $order_ids, bool $as_draft = false): array {
        $results = [
            'success' => 0,
            'failed' => 0,
            'results' => [],
        ];

        $this->logger->info('Starting bulk sync', [
            'order_count' => count($order_ids),
            'as_draft' => $as_draft,
        ]);

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);

            if (!$order) {
                $results['failed']++;
                $results['results'][$order_id] = [
                    'success' => false,
                    'error' => __('Order not found', 'zbooks-for-woocommerce'),
                ];
                continue;
            }

            $result = $this->orchestrator->sync_order($order, $as_draft);

            if ($result->success) {
                $results['success']++;
            } else {
                $results['failed']++;
            }

            $results['results'][$order_id] = $result->to_array();

            // Small delay to respect rate limits.
            usleep(100000); // 100ms
        }

        $this->logger->info('Bulk sync completed', [
            'success' => $results['success'],
            'failed' => $results['failed'],
        ]);

        return $results;
    }

    /**
     * Sync orders in date range.
     *
     * @param string $date_from Start date (Y-m-d).
     * @param string $date_to   End date (Y-m-d).
     * @param bool   $as_draft  Create as draft.
     * @param int    $batch     Batch size.
     * @return array
     */
    public function sync_date_range(
        string $date_from,
        string $date_to,
        bool $as_draft = false,
        int $batch = 50
    ): array {
        $orders = $this->get_syncable_orders($date_from, $date_to, $batch);
        $order_ids = array_map(fn($order) => $order->get_id(), $orders);

        return $this->sync_orders($order_ids, $as_draft);
    }

    /**
     * Get bulk sync statistics.
     *
     * @param string|null $date_from Start date.
     * @param string|null $date_to   End date.
     * @return array{total: int, synced: int, pending: int, failed: int}
     */
    public function get_statistics(?string $date_from = null, ?string $date_to = null): array {
        global $wpdb;

        $meta_key = OrderMetaRepository::META_SYNC_STATUS;

        $date_clause = '';
        if ($date_from) {
            $date_clause .= $wpdb->prepare(' AND p.post_date >= %s', $date_from);
        }
        if ($date_to) {
            $date_clause .= $wpdb->prepare(' AND p.post_date <= %s', $date_to);
        }

        // For HPOS compatibility, use wc_get_orders with count.
        $all_orders = wc_get_orders([
            'limit' => -1,
            'return' => 'ids',
            'type' => 'shop_order',
            'date_created' => $date_from && $date_to ? $date_from . '...' . $date_to : null,
        ]);

        $total = count($all_orders);

        $synced = wc_get_orders([
            'limit' => -1,
            'return' => 'ids',
            'type' => 'shop_order',
            'meta_key' => $meta_key,
            'meta_value' => 'synced',
        ]);

        $draft = wc_get_orders([
            'limit' => -1,
            'return' => 'ids',
            'type' => 'shop_order',
            'meta_key' => $meta_key,
            'meta_value' => 'draft',
        ]);

        $failed = wc_get_orders([
            'limit' => -1,
            'return' => 'ids',
            'type' => 'shop_order',
            'meta_key' => $meta_key,
            'meta_value' => 'failed',
        ]);

        $synced_count = count($synced) + count($draft);
        $failed_count = count($failed);
        $pending_count = $total - $synced_count - $failed_count;

        return [
            'total' => $total,
            'synced' => $synced_count,
            'pending' => max(0, $pending_count),
            'failed' => $failed_count,
        ];
    }
}
