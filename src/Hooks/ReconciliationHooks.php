<?php
/**
 * Reconciliation hooks.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Hooks;

use Zbooks\Service\ReconciliationService;
use Zbooks\Repository\ReconciliationRepository;

defined('ABSPATH') || exit;

/**
 * Handles scheduled reconciliation via Action Scheduler.
 */
class ReconciliationHooks {

    /**
     * Action hook name for scheduled reconciliation.
     */
    private const SCHEDULED_ACTION = 'zbooks_scheduled_reconciliation';

    /**
     * Action hook name for cleanup.
     */
    private const CLEANUP_ACTION = 'zbooks_reconciliation_cleanup';

    /**
     * Reconciliation service.
     *
     * @var ReconciliationService
     */
    private ReconciliationService $service;

    /**
     * Repository.
     *
     * @var ReconciliationRepository
     */
    private ReconciliationRepository $repository;

    /**
     * Constructor.
     *
     * @param ReconciliationService    $service    Reconciliation service.
     * @param ReconciliationRepository $repository Repository.
     */
    public function __construct(ReconciliationService $service, ReconciliationRepository $repository) {
        $this->service = $service;
        $this->repository = $repository;
        $this->register_hooks();
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks(): void {
        // Register Action Scheduler callbacks.
        add_action(self::SCHEDULED_ACTION, [$this, 'execute_scheduled_reconciliation']);
        add_action(self::CLEANUP_ACTION, [$this, 'execute_cleanup']);

        // Schedule/unschedule based on settings changes.
        add_action('update_option_zbooks_reconciliation_settings', [$this, 'on_settings_update'], 10, 2);

        // Initial scheduling check on plugin load.
        add_action('init', [$this, 'maybe_schedule']);
    }

    /**
     * Check and schedule reconciliation if needed.
     */
    public function maybe_schedule(): void {
        $settings = $this->service->get_settings();

        if (empty($settings['enabled'])) {
            // Unschedule if disabled.
            $this->unschedule_reconciliation();
            $this->unschedule_cleanup();
            return;
        }

        // Schedule reconciliation if not already scheduled.
        if (!$this->is_scheduled(self::SCHEDULED_ACTION)) {
            $this->schedule_next_reconciliation();
        }

        // Schedule daily cleanup if not already scheduled.
        if (!$this->is_scheduled(self::CLEANUP_ACTION)) {
            $this->schedule_cleanup();
        }
    }

    /**
     * Handle settings update.
     *
     * @param mixed $old_value Old settings value.
     * @param mixed $new_value New settings value.
     */
    public function on_settings_update($old_value, $new_value): void {
        $was_enabled = !empty($old_value['enabled']);
        $is_enabled = !empty($new_value['enabled']);

        if ($is_enabled && !$was_enabled) {
            // Just enabled - schedule next run.
            $this->schedule_next_reconciliation();
            $this->schedule_cleanup();
        } elseif (!$is_enabled && $was_enabled) {
            // Just disabled - unschedule.
            $this->unschedule_reconciliation();
            $this->unschedule_cleanup();
        } elseif ($is_enabled) {
            // Settings changed while enabled - reschedule.
            $old_frequency = $old_value['frequency'] ?? 'weekly';
            $new_frequency = $new_value['frequency'] ?? 'weekly';
            $old_day = $old_value['day_of_week'] ?? 1;
            $new_day = $new_value['day_of_week'] ?? 1;

            if ($old_frequency !== $new_frequency || $old_day !== $new_day) {
                $this->unschedule_reconciliation();
                $this->schedule_next_reconciliation();
            }
        }
    }

    /**
     * Schedule the next reconciliation run.
     */
    public function schedule_next_reconciliation(): void {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        $next_run = $this->service->get_next_scheduled_run();

        if (!$next_run) {
            return;
        }

        as_schedule_single_action(
            $next_run->getTimestamp(),
            self::SCHEDULED_ACTION,
            [],
            'zbooks'
        );
    }

    /**
     * Schedule daily cleanup of old reports.
     */
    private function schedule_cleanup(): void {
        if (!function_exists('as_schedule_recurring_action')) {
            return;
        }

        // Run cleanup daily at 3 AM.
        $tomorrow_3am = strtotime('tomorrow 03:00:00');

        as_schedule_recurring_action(
            $tomorrow_3am,
            DAY_IN_SECONDS,
            self::CLEANUP_ACTION,
            [],
            'zbooks'
        );
    }

    /**
     * Unschedule reconciliation.
     */
    private function unschedule_reconciliation(): void {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions(self::SCHEDULED_ACTION, [], 'zbooks');
    }

    /**
     * Unschedule cleanup.
     */
    private function unschedule_cleanup(): void {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions(self::CLEANUP_ACTION, [], 'zbooks');
    }

    /**
     * Check if an action is already scheduled.
     *
     * @param string $hook Action hook name.
     * @return bool
     */
    private function is_scheduled(string $hook): bool {
        if (!function_exists('as_has_scheduled_action')) {
            return false;
        }

        return as_has_scheduled_action($hook, [], 'zbooks');
    }

    /**
     * Execute scheduled reconciliation.
     */
    public function execute_scheduled_reconciliation(): void {
        $settings = $this->service->get_settings();

        // Double-check enabled in case settings changed.
        if (empty($settings['enabled'])) {
            return;
        }

        // Get the period to reconcile.
        $period = $this->service->get_reconciliation_period();

        // Run reconciliation.
        $report = $this->service->run($period['start'], $period['end']);

        // Send email notification if enabled.
        $this->service->send_email_notification($report);

        // Schedule the next run.
        $this->schedule_next_reconciliation();
    }

    /**
     * Execute cleanup of old reports.
     */
    public function execute_cleanup(): void {
        // Get log settings for retention days.
        $log_settings = get_option('zbooks_log_settings', ['retention_days' => 30]);
        $retention_days = (int) ($log_settings['retention_days'] ?? 30);

        // Delete reports older than retention period.
        $deleted = $this->repository->delete_old_reports($retention_days);

        if ($deleted > 0) {
            do_action('zbooks_reconciliation_cleanup_completed', $deleted);
        }
    }

    /**
     * Get the next scheduled run timestamp.
     *
     * @return int|null Timestamp or null if not scheduled.
     */
    public function get_next_scheduled_timestamp(): ?int {
        if (!function_exists('as_next_scheduled_action')) {
            return null;
        }

        $timestamp = as_next_scheduled_action(self::SCHEDULED_ACTION, [], 'zbooks');

        return $timestamp ?: null;
    }

    /**
     * Manually trigger reconciliation (for testing/debugging).
     *
     * @return void
     */
    public function trigger_now(): void {
        $this->execute_scheduled_reconciliation();
    }
}
