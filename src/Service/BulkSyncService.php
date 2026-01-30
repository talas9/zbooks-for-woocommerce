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

defined( 'ABSPATH' ) || exit;

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
		$this->repository   = $repository;
		$this->logger       = $logger;
	}

	/**
	 * Get orders available for bulk sync (all orders, including synced ones).
	 *
	 * @param string|null $date_from    Start date.
	 * @param string|null $date_to      End date.
	 * @param int         $limit        Max orders.
	 * @param array       $order_status Order statuses to filter by.
	 * @return array
	 */
	public function get_syncable_orders(
		?string $date_from = null,
		?string $date_to = null,
		int $limit = 100,
		array $order_status = [ 'all' ]
	): array {
		return $this->repository->get_orders_for_bulk_sync( $date_from, $date_to, $limit, $order_status );
	}

	/**
	 * Sync multiple orders.
	 *
	 * Each order is synced according to trigger settings - the system determines
	 * whether to create as draft or submit based on the order's current status
	 * and the configured trigger mappings.
	 *
	 * @param array $order_ids Order IDs to sync.
	 * @return array{success: int, failed: int, results: array}
	 */
	public function sync_orders( array $order_ids ): array {
		$results = [
			'success' => 0,
			'failed'  => 0,
			'results' => [],
		];

		$this->logger->info(
			'Starting bulk sync',
			[
				'order_count' => count( $order_ids ),
			]
		);

		// Get trigger settings once for all orders.
		$triggers = get_option(
			'zbooks_sync_triggers',
			[
				'sync_draft'        => 'processing',
				'sync_submit'       => 'completed',
				'create_creditnote' => 'refunded',
			]
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				++$results['failed'];
				$results['results'][ $order_id ] = [
					'success' => false,
					'error'   => __( 'Order not found', 'zbooks-for-woocommerce' ),
				];
				continue;
			}

			// Determine if this order should be created as draft based on its status.
			$as_draft = $this->should_create_as_draft( $order, $triggers );

			$this->logger->debug(
				'Syncing order in bulk',
				[
					'order_id' => $order_id,
					'status'   => $order->get_status(),
					'as_draft' => $as_draft,
				]
			);

			$result = $this->orchestrator->sync_order( $order, $as_draft );

			// Apply payment if:
			// 1. Sync was successful
			// 2. Not created as draft (matches sync_submit trigger)
			// 3. Order is marked as paid in WooCommerce
			// This matches the behavior of OrderStatusHooks::on_order_completed()
			// which applies payment when order status changes to completed.
			$payment_result = null;
			if ( $result->success && ! $as_draft && $order->is_paid() ) {
				$this->logger->debug(
					'Applying payment for bulk-synced order',
					[
						'order_id'   => $order_id,
						'invoice_id' => $result->invoice_id,
					]
				);
				$payment_result = $this->orchestrator->apply_payment( $order );

				// Log payment result but don't fail the sync if payment fails.
				// This matches the separate handling in OrderStatusHooks.
				if ( ! $payment_result['success'] && ! empty( $payment_result['error'] ) ) {
					$this->logger->warning(
						'Payment application failed during bulk sync',
						[
							'order_id' => $order_id,
							'error'    => $payment_result['error'],
						]
					);
				}
			}

			if ( $result->success ) {
				++$results['success'];
			} else {
				++$results['failed'];
			}

			// Include payment result in the sync result.
			$result_array = $result->to_array();
			if ( $payment_result !== null ) {
				$result_array['payment'] = $payment_result;
			}
			$results['results'][ $order_id ] = $result_array;

			// Small delay to respect rate limits.
			usleep( 100000 ); // 100ms
		}

		$this->logger->info(
			'Bulk sync completed',
			[
				'success' => $results['success'],
				'failed'  => $results['failed'],
			]
		);

		return $results;
	}

	/**
	 * Determine if invoice should be created as draft based on order status and triggers.
	 *
	 * @param \WC_Order $order    WooCommerce order.
	 * @param array     $triggers Trigger settings.
	 * @return bool True if should create as draft, false if should submit.
	 */
	private function should_create_as_draft( \WC_Order $order, array $triggers ): bool {
		$status = $order->get_status();

		// Check if sync_draft is configured for this status.
		if ( isset( $triggers['sync_draft'] ) && $triggers['sync_draft'] === $status ) {
			return true;
		}

		// Check if sync_submit is configured for this status.
		if ( isset( $triggers['sync_submit'] ) && $triggers['sync_submit'] === $status ) {
			return false;
		}

		// Default to draft for safety (orders not matching any trigger).
		return true;
	}

	/**
	 * Sync orders in date range.
	 *
	 * Each order is synced according to trigger settings.
	 *
	 * @param string $date_from Start date (Y-m-d).
	 * @param string $date_to   End date (Y-m-d).
	 * @param int    $batch     Batch size.
	 * @return array
	 */
	public function sync_date_range(
		string $date_from,
		string $date_to,
		int $batch = 50
	): array {
		$orders    = $this->get_syncable_orders( $date_from, $date_to, $batch );
		$order_ids = array_map( fn( $order ) => $order->get_id(), $orders );

		return $this->sync_orders( $order_ids );
	}

	/**
	 * Get bulk sync statistics.
	 *
	 * @param string|null $date_from Start date.
	 * @param string|null $date_to   End date.
	 * @return array{total: int, synced: int, pending: int, failed: int}
	 */
	public function get_statistics( ?string $date_from = null, ?string $date_to = null ): array {
		global $wpdb;

		$meta_key = OrderMetaRepository::META_SYNC_STATUS;

		$date_clause = '';
		if ( $date_from ) {
			$date_clause .= $wpdb->prepare( ' AND p.post_date >= %s', $date_from );
		}
		if ( $date_to ) {
			$date_clause .= $wpdb->prepare( ' AND p.post_date <= %s', $date_to );
		}

		// For HPOS compatibility, use wc_get_orders with count.
		$all_orders = wc_get_orders(
			[
				'limit'        => -1,
				'return'       => 'ids',
				'type'         => 'shop_order',
				'date_created' => $date_from && $date_to ? $date_from . '...' . $date_to : null,
			]
		);

		$total = count( $all_orders );

		$synced = wc_get_orders(
			[
				'limit'      => -1,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'meta_key'   => $meta_key,
				'meta_value' => 'synced',
			]
		);

		$draft = wc_get_orders(
			[
				'limit'      => -1,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'meta_key'   => $meta_key,
				'meta_value' => 'draft',
			]
		);

		$failed = wc_get_orders(
			[
				'limit'      => -1,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'meta_key'   => $meta_key,
				'meta_value' => 'failed',
			]
		);

		$synced_count  = count( $synced ) + count( $draft );
		$failed_count  = count( $failed );
		$pending_count = $total - $synced_count - $failed_count;

		return [
			'total'   => $total,
			'synced'  => $synced_count,
			'pending' => max( 0, $pending_count ),
			'failed'  => $failed_count,
		];
	}
}
