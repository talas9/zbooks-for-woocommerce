<?php
/**
 * Reconciliation report repository.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Repository;

use Zbooks\Model\ReconciliationReport;

defined('ABSPATH') || exit;

/**
 * Repository for storing and retrieving reconciliation reports.
 */
class ReconciliationRepository {

    /**
     * Table name (without prefix).
     *
     * @var string
     */
    private const TABLE_NAME = 'zbooks_reconciliation_reports';

    /**
     * Get the full table name with prefix.
     *
     * @return string
     */
    public function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create the database table.
     *
     * @return bool True if table created successfully.
     */
    public function create_table(): bool {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            period_start date NOT NULL,
            period_end date NOT NULL,
            generated_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            summary longtext,
            discrepancies longtext,
            error text,
            PRIMARY KEY (id),
            KEY period_start (period_start),
            KEY period_end (period_end),
            KEY status (status),
            KEY generated_at (generated_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        return true;
    }

    /**
     * Drop the database table.
     *
     * @return bool True if table dropped successfully.
     */
    public function drop_table(): bool {
        global $wpdb;

        $table_name = $this->get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

        return true;
    }

    /**
     * Save a reconciliation report.
     *
     * @param ReconciliationReport $report Report to save.
     * @return int|false Report ID or false on failure.
     */
    public function save(ReconciliationReport $report) {
        global $wpdb;

        $data = [
            'period_start' => $report->get_period_start()->format('Y-m-d'),
            'period_end' => $report->get_period_end()->format('Y-m-d'),
            'generated_at' => $report->get_generated_at()->format('Y-m-d H:i:s'),
            'status' => $report->get_status(),
            'summary' => wp_json_encode($report->get_summary()),
            'discrepancies' => wp_json_encode($report->get_discrepancies()),
            'error' => $report->get_error(),
        ];

        $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($report->get_id()) {
            // Update existing report.
            $result = $wpdb->update(
                $this->get_table_name(),
                $data,
                ['id' => $report->get_id()],
                $format,
                ['%d']
            );

            return $result !== false ? $report->get_id() : false;
        }

        // Insert new report.
        $result = $wpdb->insert(
            $this->get_table_name(),
            $data,
            $format
        );

        if ($result) {
            $report->set_id($wpdb->insert_id);
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get a report by ID.
     *
     * @param int $id Report ID.
     * @return ReconciliationReport|null
     */
    public function get(int $id): ?ReconciliationReport {
        global $wpdb;

        $table_name = $this->get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id), ARRAY_A);

        if (!$row) {
            return null;
        }

        return ReconciliationReport::from_array($row);
    }

    /**
     * Get the most recent report.
     *
     * @return ReconciliationReport|null
     */
    public function get_latest(): ?ReconciliationReport {
        global $wpdb;

        $table_name = $this->get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
        $row = $wpdb->get_row("SELECT * FROM {$table_name} ORDER BY generated_at DESC LIMIT 1", ARRAY_A);

        if (!$row) {
            return null;
        }

        return ReconciliationReport::from_array($row);
    }

    /**
     * Get reports with pagination.
     *
     * @param int $page     Page number (1-indexed).
     * @param int $per_page Items per page.
     * @return array{reports: ReconciliationReport[], total: int, pages: int}
     */
    public function get_paginated(int $page = 1, int $per_page = 10): array {
        global $wpdb;

        $table_name = $this->get_table_name();
        $offset = ($page - 1) * $per_page;

        // Get total count.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        // Get reports.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY generated_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $reports = [];
        foreach ($rows as $row) {
            $reports[] = ReconciliationReport::from_array($row);
        }

        return [
            'reports' => $reports,
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    /**
     * Get reports by status.
     *
     * @param string $status Report status.
     * @param int    $limit  Maximum number of reports.
     * @return ReconciliationReport[]
     */
    public function get_by_status(string $status, int $limit = 10): array {
        global $wpdb;

        $table_name = $this->get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE status = %s ORDER BY generated_at DESC LIMIT %d",
                $status,
                $limit
            ),
            ARRAY_A
        );

        $reports = [];
        foreach ($rows as $row) {
            $reports[] = ReconciliationReport::from_array($row);
        }

        return $reports;
    }

    /**
     * Delete a report.
     *
     * @param int $id Report ID.
     * @return bool True on success.
     */
    public function delete(int $id): bool {
        global $wpdb;

        $result = $wpdb->delete(
            $this->get_table_name(),
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Mark stale "running" reports as failed.
     *
     * Reports that have been "running" for more than the timeout are considered stale.
     *
     * @param int $timeout_minutes Timeout in minutes (default: 30).
     * @return int Number of reports marked as failed.
     */
    public function mark_stale_reports_failed(int $timeout_minutes = 30): int {
        global $wpdb;

        $table_name = $this->get_table_name();
        $cutoff_time = gmdate('Y-m-d H:i:s', strtotime("-{$timeout_minutes} minutes"));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name}
                SET status = 'failed', error = %s
                WHERE status = 'running' AND generated_at < %s",
                __('Process timed out or crashed', 'zbooks-for-woocommerce'),
                $cutoff_time
            )
        );

        return (int) $updated;
    }

    /**
     * Delete old reports based on retention policy.
     *
     * @param int $days Number of days to retain reports.
     * @return int Number of reports deleted.
     */
    public function delete_old_reports(int $days): int {
        global $wpdb;

        $table_name = $this->get_table_name();
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE generated_at < %s",
                $cutoff_date
            )
        );

        return (int) $deleted;
    }

    /**
     * Check if a report exists for a given period.
     *
     * @param \DateTimeInterface $start Period start.
     * @param \DateTimeInterface $end   Period end.
     * @return bool
     */
    public function exists_for_period(\DateTimeInterface $start, \DateTimeInterface $end): bool {
        global $wpdb;

        $table_name = $this->get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE period_start = %s AND period_end = %s AND status = 'completed'",
                $start->format('Y-m-d'),
                $end->format('Y-m-d')
            )
        );

        return (int) $count > 0;
    }

    /**
     * Get reports with discrepancies in a date range.
     *
     * @param \DateTimeInterface $start Start date.
     * @param \DateTimeInterface $end   End date.
     * @return ReconciliationReport[]
     */
    public function get_with_discrepancies(\DateTimeInterface $start, \DateTimeInterface $end): array {
        global $wpdb;

        $table_name = $this->get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name}
                WHERE generated_at BETWEEN %s AND %s
                AND status = 'completed'
                AND (
                    JSON_EXTRACT(summary, '$.missing_in_zoho') > 0
                    OR JSON_EXTRACT(summary, '$.amount_mismatches') > 0
                )
                ORDER BY generated_at DESC",
                $start->format('Y-m-d 00:00:00'),
                $end->format('Y-m-d 23:59:59')
            ),
            ARRAY_A
        );

        $reports = [];
        foreach ($rows as $row) {
            $reports[] = ReconciliationReport::from_array($row);
        }

        return $reports;
    }
}
