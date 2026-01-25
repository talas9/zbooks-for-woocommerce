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
     * @param string $fee_account_id    Zoho expense account for bank charges.
     * @return bool True on success.
     */
    public function save(
        string $wc_method_id,
        string $zoho_mode,
        string $zoho_account_id,
        string $zoho_account_name = '',
        string $fee_account_id = ''
    ): bool {
        $mappings = $this->get_all();

        $mappings[$wc_method_id] = [
            'zoho_mode' => $zoho_mode,
            'zoho_account_id' => $zoho_account_id,
            'zoho_account_name' => $zoho_account_name,
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
     * Get fee account ID (expense account for bank charges) for a WC method.
     *
     * @param string $wc_method_id WooCommerce payment method ID.
     * @return string|null Fee account ID or null.
     */
    public function get_fee_account_id(string $wc_method_id): ?string {
        $mapping = $this->get($wc_method_id);
        return !empty($mapping['fee_account_id']) ? $mapping['fee_account_id'] : null;
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
