<?php
/**
 * Payment method mapping repository.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Repository;

defined('ABSPATH') || exit;

/**
 * Repository for WooCommerce to Zoho payment method mappings.
 */
class PaymentMethodMappingRepository {

    /**
     * Option name for storing mappings.
     *
     * @var string
     */
    private const OPTION_NAME = 'zbooks_payment_method_mappings';

    /**
     * Get all payment method mappings.
     *
     * @return array Array of mappings keyed by WC payment method ID.
     */
    public function get_all(): array {
        return get_option(self::OPTION_NAME, []);
    }

    /**
     * Get mapping for a specific WooCommerce payment method.
     *
     * @param string $wc_method_id WooCommerce payment method ID.
     * @return array|null Mapping data or null if not found.
     */
    public function get(string $wc_method_id): ?array {
        $mappings = $this->get_all();
        return $mappings[$wc_method_id] ?? null;
    }

    /**
     * Save mapping for a WooCommerce payment method.
     *
     * @param string $wc_method_id      WooCommerce payment method ID.
     * @param string $zoho_mode         Zoho payment mode.
     * @param string $zoho_account_id   Zoho bank/cash account ID.
     * @param string $zoho_account_name Zoho account name (for display).
     * @param float  $fee_percentage    Processing fee percentage (e.g., 2.9 for 2.9%).
     * @param float  $fee_fixed         Fixed fee amount (e.g., 0.30).
     * @param string $fee_account_id    Zoho expense account for fees.
     * @return bool True on success.
     */
    public function save(
        string $wc_method_id,
        string $zoho_mode,
        string $zoho_account_id,
        string $zoho_account_name = '',
        float $fee_percentage = 0.0,
        float $fee_fixed = 0.0,
        string $fee_account_id = ''
    ): bool {
        $mappings = $this->get_all();

        $mappings[$wc_method_id] = [
            'zoho_mode' => $zoho_mode,
            'zoho_account_id' => $zoho_account_id,
            'zoho_account_name' => $zoho_account_name,
            'fee_percentage' => $fee_percentage,
            'fee_fixed' => $fee_fixed,
            'fee_account_id' => $fee_account_id,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        return update_option(self::OPTION_NAME, $mappings);
    }

    /**
     * Save multiple mappings at once.
     *
     * @param array $mappings Array of mappings keyed by WC method ID.
     * @return bool True on success.
     */
    public function save_all(array $mappings): bool {
        $sanitized = [];

        foreach ($mappings as $wc_method_id => $data) {
            if (empty($data['zoho_mode']) && empty($data['zoho_account_id'])) {
                continue; // Skip empty mappings.
            }

            $sanitized[sanitize_key($wc_method_id)] = [
                'zoho_mode' => sanitize_text_field($data['zoho_mode'] ?? ''),
                'zoho_account_id' => sanitize_text_field($data['zoho_account_id'] ?? ''),
                'zoho_account_name' => sanitize_text_field($data['zoho_account_name'] ?? ''),
                'fee_percentage' => (float) ($data['fee_percentage'] ?? 0.0),
                'fee_fixed' => (float) ($data['fee_fixed'] ?? 0.0),
                'fee_account_id' => sanitize_text_field($data['fee_account_id'] ?? ''),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
        }

        return update_option(self::OPTION_NAME, $sanitized);
    }

    /**
     * Delete mapping for a WooCommerce payment method.
     *
     * @param string $wc_method_id WooCommerce payment method ID.
     * @return bool True on success.
     */
    public function delete(string $wc_method_id): bool {
        $mappings = $this->get_all();

        if (!isset($mappings[$wc_method_id])) {
            return true;
        }

        unset($mappings[$wc_method_id]);

        return update_option(self::OPTION_NAME, $mappings);
    }

    /**
     * Get Zoho payment mode for a WC method.
     *
     * @param string $wc_method_id WooCommerce payment method ID.
     * @return string|null Zoho payment mode or null.
     */
    public function get_zoho_mode(string $wc_method_id): ?string {
        $mapping = $this->get($wc_method_id);
        return !empty($mapping['zoho_mode']) ? $mapping['zoho_mode'] : null;
    }

    /**
     * Get Zoho account ID (deposit to) for a WC method.
     *
     * @param string $wc_method_id WooCommerce payment method ID.
     * @return string|null Zoho account ID or null.
     */
    public function get_zoho_account_id(string $wc_method_id): ?string {
        $mapping = $this->get($wc_method_id);
        return !empty($mapping['zoho_account_id']) ? $mapping['zoho_account_id'] : null;
    }

    /**
     * Get Zoho account name (deposit to) for a WC method.
     *
     * @param string $wc_method_id WooCommerce payment method ID.
     * @return string|null Zoho account name or null.
     */
    public function get_zoho_account_name(string $wc_method_id): ?string {
        $mapping = $this->get($wc_method_id);
        return !empty($mapping['zoho_account_name']) ? $mapping['zoho_account_name'] : null;
    }

    /**
     * Get fee percentage for a WC method.
     *
     * @param string $wc_method_id WooCommerce payment method ID.
     * @return float Fee percentage (e.g., 2.9 for 2.9%).
     */
    public function get_fee_percentage(string $wc_method_id): float {
        $mapping = $this->get($wc_method_id);
        return (float) ($mapping['fee_percentage'] ?? 0.0);
    }

    /**
     * Get fixed fee amount for a WC method.
     *
     * @param string $wc_method_id WooCommerce payment method ID.
     * @return float Fixed fee amount.
     */
    public function get_fee_fixed(string $wc_method_id): float {
        $mapping = $this->get($wc_method_id);
        return (float) ($mapping['fee_fixed'] ?? 0.0);
    }

    /**
     * Get fee account ID for a WC method.
     *
     * @param string $wc_method_id WooCommerce payment method ID.
     * @return string|null Fee account ID or null.
     */
    public function get_fee_account_id(string $wc_method_id): ?string {
        $mapping = $this->get($wc_method_id);
        return !empty($mapping['fee_account_id']) ? $mapping['fee_account_id'] : null;
    }

    /**
     * Calculate bank fees for a payment amount.
     *
     * @param string $wc_method_id WooCommerce payment method ID.
     * @param float  $amount       Payment amount.
     * @return float Calculated fee amount.
     */
    public function calculate_fee(string $wc_method_id, float $amount): float {
        $percentage = $this->get_fee_percentage($wc_method_id);
        $fixed = $this->get_fee_fixed($wc_method_id);

        $fee = 0.0;
        if ($percentage > 0) {
            $fee += ($amount * $percentage / 100);
        }
        $fee += $fixed;

        return round($fee, 2);
    }

    /**
     * Clear all mappings.
     *
     * @return bool True on success.
     */
    public function clear_all(): bool {
        return delete_option(self::OPTION_NAME);
    }
}
