<?php
/**
 * Repository for custom field mappings.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Repository;

defined('ABSPATH') || exit;

/**
 * Stores and retrieves custom field mappings between WooCommerce and Zoho Books.
 */
class FieldMappingRepository {

    /**
     * Option key for customer field mappings.
     *
     * @var string
     */
    private const CUSTOMER_MAPPINGS_KEY = 'zbooks_customer_field_mappings';

    /**
     * Option key for invoice field mappings.
     *
     * @var string
     */
    private const INVOICE_MAPPINGS_KEY = 'zbooks_invoice_field_mappings';

    /**
     * Get all customer field mappings.
     *
     * @return array<int, array{wc_field: string, zoho_field: string, zoho_field_label: string}>
     */
    public function get_customer_mappings(): array {
        $mappings = get_option(self::CUSTOMER_MAPPINGS_KEY, []);
        return is_array($mappings) ? $mappings : [];
    }

    /**
     * Save customer field mappings.
     *
     * @param array<int, array{wc_field: string, zoho_field: string, zoho_field_label: string}> $mappings Mappings to save.
     * @return bool
     */
    public function save_customer_mappings(array $mappings): bool {
        $sanitized = $this->sanitize_mappings($mappings);
        return update_option(self::CUSTOMER_MAPPINGS_KEY, $sanitized);
    }

    /**
     * Get all invoice field mappings.
     *
     * @return array<int, array{wc_field: string, zoho_field: string, zoho_field_label: string}>
     */
    public function get_invoice_mappings(): array {
        $mappings = get_option(self::INVOICE_MAPPINGS_KEY, []);
        return is_array($mappings) ? $mappings : [];
    }

    /**
     * Save invoice field mappings.
     *
     * @param array<int, array{wc_field: string, zoho_field: string, zoho_field_label: string}> $mappings Mappings to save.
     * @return bool
     */
    public function save_invoice_mappings(array $mappings): bool {
        $sanitized = $this->sanitize_mappings($mappings);
        return update_option(self::INVOICE_MAPPINGS_KEY, $sanitized);
    }

    /**
     * Get available WooCommerce customer fields.
     *
     * @return array<string, string> Field key => Field label.
     */
    public function get_available_customer_fields(): array {
        return [
            // Billing fields.
            'billing_first_name' => __('Billing First Name', 'zbooks-for-woocommerce'),
            'billing_last_name' => __('Billing Last Name', 'zbooks-for-woocommerce'),
            'billing_company' => __('Billing Company', 'zbooks-for-woocommerce'),
            'billing_address_1' => __('Billing Address 1', 'zbooks-for-woocommerce'),
            'billing_address_2' => __('Billing Address 2', 'zbooks-for-woocommerce'),
            'billing_city' => __('Billing City', 'zbooks-for-woocommerce'),
            'billing_state' => __('Billing State', 'zbooks-for-woocommerce'),
            'billing_postcode' => __('Billing Postcode', 'zbooks-for-woocommerce'),
            'billing_country' => __('Billing Country', 'zbooks-for-woocommerce'),
            'billing_email' => __('Billing Email', 'zbooks-for-woocommerce'),
            'billing_phone' => __('Billing Phone', 'zbooks-for-woocommerce'),
            // Shipping fields.
            'shipping_first_name' => __('Shipping First Name', 'zbooks-for-woocommerce'),
            'shipping_last_name' => __('Shipping Last Name', 'zbooks-for-woocommerce'),
            'shipping_company' => __('Shipping Company', 'zbooks-for-woocommerce'),
            'shipping_address_1' => __('Shipping Address 1', 'zbooks-for-woocommerce'),
            'shipping_address_2' => __('Shipping Address 2', 'zbooks-for-woocommerce'),
            'shipping_city' => __('Shipping City', 'zbooks-for-woocommerce'),
            'shipping_state' => __('Shipping State', 'zbooks-for-woocommerce'),
            'shipping_postcode' => __('Shipping Postcode', 'zbooks-for-woocommerce'),
            'shipping_country' => __('Shipping Country', 'zbooks-for-woocommerce'),
            'shipping_phone' => __('Shipping Phone', 'zbooks-for-woocommerce'),
            // User meta.
            'user_id' => __('WordPress User ID', 'zbooks-for-woocommerce'),
            'user_email' => __('WordPress User Email', 'zbooks-for-woocommerce'),
            'display_name' => __('Display Name', 'zbooks-for-woocommerce'),
        ];
    }

    /**
     * Get available WooCommerce order/invoice fields.
     *
     * @return array<string, string> Field key => Field label.
     */
    public function get_available_invoice_fields(): array {
        return [
            // Order fields.
            'order_id' => __('Order ID', 'zbooks-for-woocommerce'),
            'order_number' => __('Order Number', 'zbooks-for-woocommerce'),
            'order_date' => __('Order Date', 'zbooks-for-woocommerce'),
            'order_status' => __('Order Status', 'zbooks-for-woocommerce'),
            'order_total' => __('Order Total', 'zbooks-for-woocommerce'),
            'order_subtotal' => __('Order Subtotal', 'zbooks-for-woocommerce'),
            'order_discount' => __('Order Discount', 'zbooks-for-woocommerce'),
            'order_shipping_total' => __('Shipping Total', 'zbooks-for-woocommerce'),
            'order_tax_total' => __('Tax Total', 'zbooks-for-woocommerce'),
            'payment_method' => __('Payment Method', 'zbooks-for-woocommerce'),
            'payment_method_title' => __('Payment Method Title', 'zbooks-for-woocommerce'),
            'transaction_id' => __('Transaction ID', 'zbooks-for-woocommerce'),
            'customer_note' => __('Customer Note', 'zbooks-for-woocommerce'),
            'order_currency' => __('Currency', 'zbooks-for-woocommerce'),
            // Billing fields from order.
            'billing_first_name' => __('Billing First Name', 'zbooks-for-woocommerce'),
            'billing_last_name' => __('Billing Last Name', 'zbooks-for-woocommerce'),
            'billing_full_name' => __('Billing Full Name', 'zbooks-for-woocommerce'),
            'billing_company' => __('Billing Company', 'zbooks-for-woocommerce'),
            'billing_address_1' => __('Billing Address 1', 'zbooks-for-woocommerce'),
            'billing_address_2' => __('Billing Address 2', 'zbooks-for-woocommerce'),
            'billing_city' => __('Billing City', 'zbooks-for-woocommerce'),
            'billing_state' => __('Billing State', 'zbooks-for-woocommerce'),
            'billing_postcode' => __('Billing Postcode', 'zbooks-for-woocommerce'),
            'billing_country' => __('Billing Country', 'zbooks-for-woocommerce'),
            'billing_email' => __('Billing Email', 'zbooks-for-woocommerce'),
            'billing_phone' => __('Billing Phone', 'zbooks-for-woocommerce'),
            // Shipping fields from order.
            'shipping_first_name' => __('Shipping First Name', 'zbooks-for-woocommerce'),
            'shipping_last_name' => __('Shipping Last Name', 'zbooks-for-woocommerce'),
            'shipping_full_name' => __('Shipping Full Name', 'zbooks-for-woocommerce'),
            'shipping_company' => __('Shipping Company', 'zbooks-for-woocommerce'),
            'shipping_address_1' => __('Shipping Address 1', 'zbooks-for-woocommerce'),
            'shipping_address_2' => __('Shipping Address 2', 'zbooks-for-woocommerce'),
            'shipping_city' => __('Shipping City', 'zbooks-for-woocommerce'),
            'shipping_state' => __('Shipping State', 'zbooks-for-woocommerce'),
            'shipping_postcode' => __('Shipping Postcode', 'zbooks-for-woocommerce'),
            'shipping_country' => __('Shipping Country', 'zbooks-for-woocommerce'),
            'shipping_phone' => __('Shipping Phone', 'zbooks-for-woocommerce'),
            // Coupon info.
            'coupon_codes' => __('Coupon Codes', 'zbooks-for-woocommerce'),
            // Custom meta placeholder.
            'meta:' => __('Custom Order Meta (prefix with meta:)', 'zbooks-for-woocommerce'),
        ];
    }

    /**
     * Extract value from WooCommerce order based on field key.
     *
     * @param \WC_Order $order WooCommerce order.
     * @param string    $field_key Field key to extract.
     * @return string
     */
    public function extract_order_field_value(\WC_Order $order, string $field_key): string {
        // Handle custom meta fields.
        if (str_starts_with($field_key, 'meta:')) {
            $meta_key = substr($field_key, 5);
            $value = $order->get_meta($meta_key);
            return is_string($value) ? $value : '';
        }

        switch ($field_key) {
            case 'order_id':
                return (string) $order->get_id();
            case 'order_number':
                return $order->get_order_number();
            case 'order_date':
                $date = $order->get_date_created();
                return $date ? $date->format('Y-m-d H:i:s') : '';
            case 'order_status':
                return $order->get_status();
            case 'order_total':
                return $order->get_total();
            case 'order_subtotal':
                return $order->get_subtotal();
            case 'order_discount':
                return $order->get_discount_total();
            case 'order_shipping_total':
                return $order->get_shipping_total();
            case 'order_tax_total':
                return $order->get_total_tax();
            case 'payment_method':
                return $order->get_payment_method();
            case 'payment_method_title':
                return $order->get_payment_method_title();
            case 'transaction_id':
                return $order->get_transaction_id();
            case 'customer_note':
                return $order->get_customer_note();
            case 'order_currency':
                return $order->get_currency();
            case 'billing_first_name':
                return $order->get_billing_first_name();
            case 'billing_last_name':
                return $order->get_billing_last_name();
            case 'billing_full_name':
                return $order->get_formatted_billing_full_name();
            case 'billing_company':
                return $order->get_billing_company();
            case 'billing_address_1':
                return $order->get_billing_address_1();
            case 'billing_address_2':
                return $order->get_billing_address_2();
            case 'billing_city':
                return $order->get_billing_city();
            case 'billing_state':
                return $order->get_billing_state();
            case 'billing_postcode':
                return $order->get_billing_postcode();
            case 'billing_country':
                return $order->get_billing_country();
            case 'billing_email':
                return $order->get_billing_email();
            case 'billing_phone':
                return $order->get_billing_phone();
            case 'shipping_first_name':
                return $order->get_shipping_first_name();
            case 'shipping_last_name':
                return $order->get_shipping_last_name();
            case 'shipping_full_name':
                return $order->get_formatted_shipping_full_name();
            case 'shipping_company':
                return $order->get_shipping_company();
            case 'shipping_address_1':
                return $order->get_shipping_address_1();
            case 'shipping_address_2':
                return $order->get_shipping_address_2();
            case 'shipping_city':
                return $order->get_shipping_city();
            case 'shipping_state':
                return $order->get_shipping_state();
            case 'shipping_postcode':
                return $order->get_shipping_postcode();
            case 'shipping_country':
                return $order->get_shipping_country();
            case 'shipping_phone':
                return $order->get_shipping_phone();
            case 'coupon_codes':
                return implode(', ', $order->get_coupon_codes());
            default:
                return '';
        }
    }

    /**
     * Extract value from WooCommerce customer/order for customer sync.
     *
     * @param \WC_Order $order WooCommerce order (used for customer data).
     * @param string    $field_key Field key to extract.
     * @return string
     */
    public function extract_customer_field_value(\WC_Order $order, string $field_key): string {
        switch ($field_key) {
            case 'billing_first_name':
                return $order->get_billing_first_name();
            case 'billing_last_name':
                return $order->get_billing_last_name();
            case 'billing_company':
                return $order->get_billing_company();
            case 'billing_address_1':
                return $order->get_billing_address_1();
            case 'billing_address_2':
                return $order->get_billing_address_2();
            case 'billing_city':
                return $order->get_billing_city();
            case 'billing_state':
                return $order->get_billing_state();
            case 'billing_postcode':
                return $order->get_billing_postcode();
            case 'billing_country':
                return $order->get_billing_country();
            case 'billing_email':
                return $order->get_billing_email();
            case 'billing_phone':
                return $order->get_billing_phone();
            case 'shipping_first_name':
                return $order->get_shipping_first_name();
            case 'shipping_last_name':
                return $order->get_shipping_last_name();
            case 'shipping_company':
                return $order->get_shipping_company();
            case 'shipping_address_1':
                return $order->get_shipping_address_1();
            case 'shipping_address_2':
                return $order->get_shipping_address_2();
            case 'shipping_city':
                return $order->get_shipping_city();
            case 'shipping_state':
                return $order->get_shipping_state();
            case 'shipping_postcode':
                return $order->get_shipping_postcode();
            case 'shipping_country':
                return $order->get_shipping_country();
            case 'shipping_phone':
                return $order->get_shipping_phone();
            case 'user_id':
                return (string) $order->get_customer_id();
            case 'user_email':
                $customer_id = $order->get_customer_id();
                if ($customer_id) {
                    $user = get_user_by('id', $customer_id);
                    return $user ? $user->user_email : '';
                }
                return $order->get_billing_email();
            case 'display_name':
                $customer_id = $order->get_customer_id();
                if ($customer_id) {
                    $user = get_user_by('id', $customer_id);
                    return $user ? $user->display_name : '';
                }
                return $order->get_formatted_billing_full_name();
            default:
                return '';
        }
    }

    /**
     * Build custom fields array for Zoho API from mappings.
     *
     * @param \WC_Order $order WooCommerce order.
     * @param string    $type 'customer' or 'invoice'.
     * @return array<int, array{label: string, value: string}>
     */
    public function build_custom_fields(\WC_Order $order, string $type): array {
        $mappings = $type === 'customer'
            ? $this->get_customer_mappings()
            : $this->get_invoice_mappings();

        $custom_fields = [];

        foreach ($mappings as $mapping) {
            if (empty($mapping['wc_field']) || empty($mapping['zoho_field'])) {
                continue;
            }

            $value = $type === 'customer'
                ? $this->extract_customer_field_value($order, $mapping['wc_field'])
                : $this->extract_order_field_value($order, $mapping['wc_field']);

            if ($value !== '') {
                $custom_fields[] = [
                    'customfield_id' => $mapping['zoho_field'],
                    'value' => $value,
                ];
            }
        }

        return $custom_fields;
    }

    /**
     * Sanitize mappings array.
     *
     * @param array $mappings Raw mappings.
     * @return array Sanitized mappings.
     */
    private function sanitize_mappings(array $mappings): array {
        $sanitized = [];

        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $wc_field = isset($mapping['wc_field']) ? sanitize_text_field($mapping['wc_field']) : '';
            $zoho_field = isset($mapping['zoho_field']) ? sanitize_text_field($mapping['zoho_field']) : '';
            $zoho_field_label = isset($mapping['zoho_field_label']) ? sanitize_text_field($mapping['zoho_field_label']) : '';

            if ($wc_field && $zoho_field) {
                $sanitized[] = [
                    'wc_field' => $wc_field,
                    'zoho_field' => $zoho_field,
                    'zoho_field_label' => $zoho_field_label,
                ];
            }
        }

        return $sanitized;
    }

    /**
     * Delete all mappings (for uninstall).
     *
     * @return void
     */
    public function delete_all(): void {
        delete_option(self::CUSTOMER_MAPPINGS_KEY);
        delete_option(self::INVOICE_MAPPINGS_KEY);
    }
}
