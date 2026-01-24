<?php
/**
 * Cron job for retrying failed order syncs.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Cron;

use Zbooks\Api\ZohoClient;
use Zbooks\Logger\SyncLogger;
use Zbooks\Repository\OrderMetaRepository;
use Zbooks\Service\SyncOrchestrator;

defined('ABSPATH') || exit;

/**
 * WP Cron job that retries failed order syncs.
 *
 * Runs every 15 minutes via the zbooks_retry_failed_syncs hook.
 * Uses exponential backoff and respects retry settings (max_retries, indefinite, manual).
 */
class RetryFailedSyncs {

    /**
     * Maximum orders to process per cron run.
     */
    private const BATCH_LIMIT = 10;

    /**
     * Zoho API client.
     *
     * @var ZohoClient
     */
    private ZohoClient $zoho_client;

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
     * @param ZohoClient          $zoho_client  Zoho API client.
     * @param SyncOrchestrator    $orchestrator Sync orchestrator.
     * @param OrderMetaRepository $repository   Order meta repository.
     * @param SyncLogger          $logger       Logger.
     */
    public function __construct(
        ZohoClient $zoho_client,
        SyncOrchestrator $orchestrator,
        OrderMetaRepository $repository,
        SyncLogger $logger
    ) {
        $this->zoho_client = $zoho_client;
        $this->orchestrator = $orchestrator;
        $this->repository = $repository;
        $this->logger = $logger;

        $this->register_hooks();
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks(): void {
        add_action('zbooks_retry_failed_syncs', [$this, 'run']);
    }

    /**
     * Run the cron job to retry failed syncs.
     *
     * Gets failed orders from the repository, checks retry eligibility
     * based on settings and exponential backoff, then attempts to resync.
     */
    public function run(): void {
        // Skip if connection is not configured.
        if (!$this->zoho_client->is_configured()) {
            $this->logger->debug('Retry cron skipped: Zoho connection not configured');
            return;
        }

        // Skip if connection is not healthy.
        if (!$this->is_connection_healthy()) {
            $this->logger->debug('Retry cron skipped: Zoho connection not healthy');
            return;
        }

        $settings = $this->get_retry_settings();

        // Skip if manual mode is enabled.
        if ($settings['mode'] === 'manual') {
            $this->logger->debug('Retry cron skipped: manual mode enabled');
            return;
        }

        $this->logger->info('Starting retry cron job');

        $failed_orders = $this->repository->get_failed_orders(self::BATCH_LIMIT);

        if (empty($failed_orders)) {
            $this->logger->debug('No failed orders to retry');
            return;
        }

        $this->logger->info('Found failed orders to retry', [
            'count' => count($failed_orders),
        ]);

        $processed = 0;
        $succeeded = 0;
        $skipped = 0;

        foreach ($failed_orders as $order) {
            $order_id = $order->get_id();

            // Check if order can be retried based on settings.
            if (!$this->can_retry_order($order, $settings)) {
                $this->logger->debug('Order retry skipped: max retries reached', [
                    'order_id' => $order_id,
                    'retry_count' => $this->repository->get_retry_count($order),
                ]);
                $skipped++;
                continue;
            }

            // Check exponential backoff delay.
            if (!$this->should_retry_now($order)) {
                $this->logger->debug('Order retry skipped: backoff delay not elapsed', [
                    'order_id' => $order_id,
                ]);
                $skipped++;
                continue;
            }

            $this->logger->info('Retrying sync for order', [
                'order_id' => $order_id,
                'retry_count' => $this->repository->get_retry_count($order) + 1,
            ]);

            // Attempt retry.
            $result = $this->orchestrator->retry_sync($order);
            $processed++;

            if ($result->success) {
                $succeeded++;
                $this->logger->info('Retry successful', [
                    'order_id' => $order_id,
                    'invoice_id' => $result->invoice_id,
                ]);
            } else {
                $this->logger->warning('Retry failed', [
                    'order_id' => $order_id,
                    'error' => $result->error,
                ]);
            }
        }

        $this->logger->info('Retry cron job completed', [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Check if an order can be retried based on settings.
     *
     * @param \WC_Order $order    WooCommerce order.
     * @param array     $settings Retry settings.
     * @return bool
     */
    private function can_retry_order(\WC_Order $order, array $settings): bool {
        // Manual mode should never reach here, but check anyway.
        if ($settings['mode'] === 'manual') {
            return false;
        }

        // Indefinite mode always allows retry.
        if ($settings['mode'] === 'indefinite') {
            return true;
        }

        // Max retries mode - check count.
        $retry_count = $this->repository->get_retry_count($order);
        $max_retries = (int) $settings['max_count'];

        return $retry_count < $max_retries;
    }

    /**
     * Check if enough time has passed since last attempt (exponential backoff).
     *
     * @param \WC_Order $order WooCommerce order.
     * @return bool
     */
    private function should_retry_now(\WC_Order $order): bool {
        $last_attempt = $this->repository->get_last_sync_attempt($order);

        // No previous attempt recorded, allow retry.
        if ($last_attempt === null) {
            return true;
        }

        $delay_seconds = $this->orchestrator->get_retry_delay($order);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $next_retry_time = $last_attempt->modify("+{$delay_seconds} seconds");

        return $now >= $next_retry_time;
    }

    /**
     * Get retry settings from WordPress options.
     *
     * @return array{mode: string, max_count: int, backoff_minutes: int}
     */
    private function get_retry_settings(): array {
        $defaults = [
            'mode' => 'max_retries',
            'max_count' => 5,
            'backoff_minutes' => 15,
        ];

        $settings = get_option('zbooks_retry_settings', $defaults);

        return array_merge($defaults, $settings);
    }

    /**
     * Check if the Zoho connection is healthy.
     *
     * Uses a cached result to avoid excessive API calls.
     * Cache expires after 5 minutes or when manually cleared.
     *
     * @return bool
     */
    private function is_connection_healthy(): bool {
        $cache_key = 'zbooks_connection_healthy';
        $cached = get_transient($cache_key);

        // Return cached result if available.
        if ($cached !== false) {
            return $cached === 'yes';
        }

        // Test the connection.
        try {
            $healthy = $this->zoho_client->test_connection();

            // Cache result for 5 minutes.
            set_transient($cache_key, $healthy ? 'yes' : 'no', 5 * MINUTE_IN_SECONDS);

            return $healthy;
        } catch (\Exception $e) {
            $this->logger->warning('Connection health check failed', [
                'error' => $e->getMessage(),
            ]);

            // Cache failure for 5 minutes.
            set_transient($cache_key, 'no', 5 * MINUTE_IN_SECONDS);

            return false;
        }
    }
}
