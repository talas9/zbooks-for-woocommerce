<?php
/**
 * Item mapping repository.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Repository;

defined('ABSPATH') || exit;

/**
 * Repository for managing WooCommerce product to Zoho item mappings.
 *
 * Stores mappings in wp_options as a serialized array.
 */
class ItemMappingRepository {

    /**
     * Option name for item mappings.
     */
    private const OPTION_NAME = 'zbooks_item_mappings';

    /**
     * Cached mappings.
     *
     * @var array|null
     */
    private ?array $cache = null;

    /**
     * Get all mappings.
     *
     * @return array<int, string> Product ID => Zoho item ID.
     */
    public function get_all(): array {
        if ($this->cache === null) {
            $this->cache = get_option(self::OPTION_NAME, []);
        }
        return $this->cache;
    }

    /**
     * Get Zoho item ID for a WooCommerce product.
     *
     * @param int $product_id WooCommerce product ID.
     * @return string|null Zoho item ID or null if not mapped.
     */
    public function get_zoho_item_id(int $product_id): ?string {
        $mappings = $this->get_all();
        return $mappings[$product_id] ?? null;
    }

    /**
     * Set mapping for a product.
     *
     * @param int    $product_id   WooCommerce product ID.
     * @param string $zoho_item_id Zoho item ID.
     * @return bool
     */
    public function set_mapping(int $product_id, string $zoho_item_id): bool {
        $mappings = $this->get_all();
        $mappings[$product_id] = $zoho_item_id;
        $this->cache = $mappings;
        return update_option(self::OPTION_NAME, $mappings);
    }

    /**
     * Remove mapping for a product.
     *
     * @param int $product_id WooCommerce product ID.
     * @return bool
     */
    public function remove_mapping(int $product_id): bool {
        $mappings = $this->get_all();
        if (!isset($mappings[$product_id])) {
            return true;
        }
        unset($mappings[$product_id]);
        $this->cache = $mappings;
        return update_option(self::OPTION_NAME, $mappings);
    }

    /**
     * Set multiple mappings at once.
     *
     * @param array<int, string> $mappings Product ID => Zoho item ID.
     * @return bool
     */
    public function set_mappings(array $mappings): bool {
        $current = $this->get_all();
        $merged = array_merge($current, $mappings);
        $this->cache = $merged;
        return update_option(self::OPTION_NAME, $merged);
    }

    /**
     * Clear all mappings.
     *
     * @return bool
     */
    public function clear_all(): bool {
        $this->cache = [];
        return update_option(self::OPTION_NAME, []);
    }

    /**
     * Get count of mapped products.
     *
     * @return int
     */
    public function get_count(): int {
        return count($this->get_all());
    }

    /**
     * Check if a product is mapped.
     *
     * @param int $product_id WooCommerce product ID.
     * @return bool
     */
    public function is_mapped(int $product_id): bool {
        return $this->get_zoho_item_id($product_id) !== null;
    }

    /**
     * Get unmapped WooCommerce products.
     *
     * @param int $limit Max products to return.
     * @return array List of unmapped products.
     */
    public function get_unmapped_products(int $limit = 100): array {
        $mappings = $this->get_all();
        $mapped_ids = array_keys($mappings);

        $args = [
            'status' => 'publish',
            'limit' => $limit,
            'exclude' => $mapped_ids,
            'return' => 'objects',
        ];

        return wc_get_products($args);
    }

    /**
     * Auto-map products by SKU matching.
     *
     * Attempts to match WooCommerce product SKUs with Zoho item SKUs.
     *
     * @param array $zoho_items List of Zoho items with 'item_id' and 'sku' keys.
     * @return int Number of products mapped.
     */
    public function auto_map_by_sku(array $zoho_items): int {
        $mapped_count = 0;

        // Index Zoho items by SKU.
        $zoho_by_sku = [];
        foreach ($zoho_items as $item) {
            $sku = $item['sku'] ?? '';
            if (!empty($sku)) {
                $zoho_by_sku[strtolower($sku)] = $item['item_id'];
            }
        }

        if (empty($zoho_by_sku)) {
            return 0;
        }

        // Get all WooCommerce products with SKUs.
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'return' => 'objects',
        ]);

        $new_mappings = [];

        foreach ($products as $product) {
            $sku = $product->get_sku();
            if (empty($sku)) {
                continue;
            }

            $sku_lower = strtolower($sku);
            if (isset($zoho_by_sku[$sku_lower])) {
                $product_id = $product->get_id();
                if (!$this->is_mapped($product_id)) {
                    $new_mappings[$product_id] = $zoho_by_sku[$sku_lower];
                    $mapped_count++;
                }
            }
        }

        if (!empty($new_mappings)) {
            $this->set_mappings($new_mappings);
        }

        return $mapped_count;
    }
}
