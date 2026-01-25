<?php
/**
 * Reconciliation report model.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Model;

defined('ABSPATH') || exit;

/**
 * Represents a reconciliation report comparing WooCommerce orders to Zoho invoices.
 */
class ReconciliationReport {

    /**
     * Report ID.
     *
     * @var int|null
     */
    private ?int $id = null;

    /**
     * Period start date.
     *
     * @var \DateTimeInterface
     */
    private \DateTimeInterface $period_start;

    /**
     * Period end date.
     *
     * @var \DateTimeInterface
     */
    private \DateTimeInterface $period_end;

    /**
     * Report generated timestamp.
     *
     * @var \DateTimeInterface
     */
    private \DateTimeInterface $generated_at;

    /**
     * Report status: pending, running, completed, failed.
     *
     * @var string
     */
    private string $status = 'pending';

    /**
     * Summary statistics.
     *
     * @var array
     */
    private array $summary = [];

    /**
     * List of discrepancies found.
     *
     * @var array
     */
    private array $discrepancies = [];

    /**
     * Error message if report failed.
     *
     * @var string|null
     */
    private ?string $error = null;

    /**
     * Constructor.
     *
     * @param \DateTimeInterface $period_start Period start date.
     * @param \DateTimeInterface $period_end   Period end date.
     */
    public function __construct(\DateTimeInterface $period_start, \DateTimeInterface $period_end) {
        $this->period_start = $period_start;
        $this->period_end = $period_end;
        $this->generated_at = new \DateTimeImmutable();
        $this->summary = $this->get_default_summary();
    }

    /**
     * Get default summary structure.
     *
     * @return array
     */
    private function get_default_summary(): array {
        return [
            'total_wc_orders' => 0,
            'total_zoho_invoices' => 0,
            'matched_count' => 0,
            'missing_in_zoho' => 0,
            'missing_in_wc' => 0,
            'amount_mismatches' => 0,
            'status_mismatches' => 0,
            'wc_total_amount' => 0.0,
            'zoho_total_amount' => 0.0,
            'amount_difference' => 0.0,
        ];
    }

    /**
     * Get report ID.
     *
     * @return int|null
     */
    public function get_id(): ?int {
        return $this->id;
    }

    /**
     * Set report ID.
     *
     * @param int $id Report ID.
     * @return self
     */
    public function set_id(int $id): self {
        $this->id = $id;
        return $this;
    }

    /**
     * Get period start date.
     *
     * @return \DateTimeInterface
     */
    public function get_period_start(): \DateTimeInterface {
        return $this->period_start;
    }

    /**
     * Get period end date.
     *
     * @return \DateTimeInterface
     */
    public function get_period_end(): \DateTimeInterface {
        return $this->period_end;
    }

    /**
     * Get generated timestamp.
     *
     * @return \DateTimeInterface
     */
    public function get_generated_at(): \DateTimeInterface {
        return $this->generated_at;
    }

    /**
     * Set generated timestamp.
     *
     * @param \DateTimeInterface $timestamp Timestamp.
     * @return self
     */
    public function set_generated_at(\DateTimeInterface $timestamp): self {
        $this->generated_at = $timestamp;
        return $this;
    }

    /**
     * Get report status.
     *
     * @return string
     */
    public function get_status(): string {
        return $this->status;
    }

    /**
     * Set report status.
     *
     * @param string $status Status (pending, running, completed, failed).
     * @return self
     */
    public function set_status(string $status): self {
        $valid_statuses = ['pending', 'running', 'completed', 'failed'];
        if (in_array($status, $valid_statuses, true)) {
            $this->status = $status;
        }
        return $this;
    }

    /**
     * Get summary statistics.
     *
     * @return array
     */
    public function get_summary(): array {
        return $this->summary;
    }

    /**
     * Set summary statistics.
     *
     * @param array $summary Summary data.
     * @return self
     */
    public function set_summary(array $summary): self {
        $this->summary = array_merge($this->get_default_summary(), $summary);
        return $this;
    }

    /**
     * Update a single summary value.
     *
     * @param string $key   Summary key.
     * @param mixed  $value Summary value.
     * @return self
     */
    public function update_summary(string $key, $value): self {
        $this->summary[$key] = $value;
        return $this;
    }

    /**
     * Increment a summary counter.
     *
     * @param string $key    Summary key.
     * @param int    $amount Amount to increment.
     * @return self
     */
    public function increment_summary(string $key, int $amount = 1): self {
        if (isset($this->summary[$key])) {
            $this->summary[$key] += $amount;
        }
        return $this;
    }

    /**
     * Get discrepancies.
     *
     * @return array
     */
    public function get_discrepancies(): array {
        return $this->discrepancies;
    }

    /**
     * Set discrepancies.
     *
     * @param array $discrepancies List of discrepancies.
     * @return self
     */
    public function set_discrepancies(array $discrepancies): self {
        $this->discrepancies = $discrepancies;
        return $this;
    }

    /**
     * Add a discrepancy.
     *
     * @param array $discrepancy Discrepancy data.
     * @return self
     */
    public function add_discrepancy(array $discrepancy): self {
        $this->discrepancies[] = $discrepancy;
        return $this;
    }

    /**
     * Get error message.
     *
     * @return string|null
     */
    public function get_error(): ?string {
        return $this->error;
    }

    /**
     * Set error message.
     *
     * @param string|null $error Error message.
     * @return self
     */
    public function set_error(?string $error): self {
        $this->error = $error;
        return $this;
    }

    /**
     * Check if report has discrepancies.
     *
     * @return bool
     */
    public function has_discrepancies(): bool {
        return !empty($this->discrepancies);
    }

    /**
     * Get discrepancy count.
     *
     * @return int
     */
    public function get_discrepancy_count(): int {
        return count($this->discrepancies);
    }

    /**
     * Check if report is healthy (no significant issues).
     *
     * @return bool
     */
    public function is_healthy(): bool {
        return $this->status === 'completed'
            && $this->summary['missing_in_zoho'] === 0
            && $this->summary['amount_mismatches'] === 0;
    }

    /**
     * Convert report to array for storage.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'id' => $this->id,
            'period_start' => $this->period_start->format('Y-m-d'),
            'period_end' => $this->period_end->format('Y-m-d'),
            'generated_at' => $this->generated_at->format('Y-m-d H:i:s'),
            'status' => $this->status,
            'summary' => $this->summary,
            'discrepancies' => $this->discrepancies,
            'error' => $this->error,
        ];
    }

    /**
     * Create report from array (database row).
     *
     * @param array $data Report data.
     * @return self
     */
    public static function from_array(array $data): self {
        $report = new self(
            new \DateTimeImmutable($data['period_start']),
            new \DateTimeImmutable($data['period_end'])
        );

        if (!empty($data['id'])) {
            $report->set_id((int) $data['id']);
        }

        if (!empty($data['generated_at'])) {
            $report->set_generated_at(new \DateTimeImmutable($data['generated_at']));
        }

        if (!empty($data['status'])) {
            $report->set_status($data['status']);
        }

        if (!empty($data['summary'])) {
            $summary = is_string($data['summary']) ? json_decode($data['summary'], true) : $data['summary'];
            $report->set_summary($summary ?: []);
        }

        if (!empty($data['discrepancies'])) {
            $discrepancies = is_string($data['discrepancies']) ? json_decode($data['discrepancies'], true) : $data['discrepancies'];
            $report->set_discrepancies($discrepancies ?: []);
        }

        if (!empty($data['error'])) {
            $report->set_error($data['error']);
        }

        return $report;
    }
}
