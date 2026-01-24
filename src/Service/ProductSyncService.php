<?php
/**
 * Product sync service.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

use WC_Product;
use Zbooks\Api\ZohoClient;
use Zbooks\Repository\ItemMappingRepository;
use Zbooks\Logger\SyncLogger;

defined('ABSPATH') || exit;

/**
 * Service for syncing WooCommerce products to Zoho Books.
 */
class ProductSyncService {

    /**
     * Zoho client.
     *
     * @var ZohoClient
     */
    private ZohoClient $client;

    /**
     * Item mapping repository.
     *
     * @var ItemMappingRepository
     */
    private ItemMappingRepository $mapping_repo;

    /**
     * Logger.
     *
     * @var SyncLogger
     */
    private SyncLogger $logger;

    /**
     * Constructor.
     *
     * @param ZohoClient            $client      Zoho client.
     * @param ItemMappingRepository $mapping_repo Item mapping repository.
     * @param SyncLogger            $logger      Logger.
     */
    public function __construct(
        ZohoClient $client,
        ItemMappingRepository $mapping_repo,
        SyncLogger $logger
    ) {
        $this->client = $client;
        $this->mapping_repo = $mapping_repo;
        $this->logger = $logger;
    }

    /**
     * Create a product in Zoho Books.
     *
     * @param WC_Product $product WooCommerce product.
     * @return array{success: bool, item_id?: string, error?: string}
     */
    public function create_item(WC_Product $product): array {
        $product_id = $product->get_id();

        // Check if already mapped.
        $existing = $this->mapping_repo->get_zoho_item_id($product_id);
        if ($existing) {
            return [
                'success' => false,
                'error' => __('Product already linked to a Zoho item.', 'zbooks-for-woocommerce'),
            ];
        }

        try {
            $item_data = $this->build_item_data($product);

            $this->logger->info('Creating Zoho item', [
                'product_id' => $product_id,
                'name' => $item_data['name'],
            ]);

            $response = $this->client->request(function ($client) use ($item_data) {
                return $client->items->create($item_data);
            });

            // SDK returns Item model object directly, not an array.
            $zoho_item_id = $this->extract_item_id($response);

            if ($zoho_item_id) {
                $this->mapping_repo->set_mapping($product_id, $zoho_item_id);

                $this->logger->info('Zoho item created', [
                    'product_id' => $product_id,
                    'zoho_item_id' => $zoho_item_id,
                ]);

                return [
                    'success' => true,
                    'item_id' => $zoho_item_id,
                ];
            }

            return [
                'success' => false,
                'error' => __('Unexpected response from Zoho.', 'zbooks-for-woocommerce'),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to create Zoho item', [
                'product_id' => $product_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update an existing item in Zoho Books.
     *
     * @param WC_Product $product WooCommerce product.
     * @return array{success: bool, error?: string}
     */
    public function update_item(WC_Product $product): array {
        $product_id = $product->get_id();
        $zoho_item_id = $this->mapping_repo->get_zoho_item_id($product_id);

        if (!$zoho_item_id) {
            return [
                'success' => false,
                'error' => __('Product not linked to a Zoho item.', 'zbooks-for-woocommerce'),
            ];
        }

        try {
            $item_data = $this->build_item_data($product);

            $this->logger->info('Updating Zoho item', [
                'product_id' => $product_id,
                'zoho_item_id' => $zoho_item_id,
            ]);

            $this->client->request(function ($client) use ($zoho_item_id, $item_data) {
                return $client->items->update($zoho_item_id, $item_data);
            });

            // Clear cache.
            delete_transient('zbooks_zoho_item_' . $zoho_item_id);

            $this->logger->info('Zoho item updated', [
                'product_id' => $product_id,
                'zoho_item_id' => $zoho_item_id,
            ]);

            return ['success' => true];
        } catch (\Exception $e) {
            $this->logger->error('Failed to update Zoho item', [
                'product_id' => $product_id,
                'zoho_item_id' => $zoho_item_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Bulk create products in Zoho Books.
     *
     * @param array $product_ids WooCommerce product IDs.
     * @return array{success: int, failed: int, results: array}
     */
    public function bulk_create_items(array $product_ids): array {
        $results = [
            'success' => 0,
            'failed' => 0,
            'results' => [],
        ];

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);

            if (!$product) {
                $results['failed']++;
                $results['results'][$product_id] = [
                    'success' => false,
                    'error' => __('Product not found.', 'zbooks-for-woocommerce'),
                ];
                continue;
            }

            // Skip if already mapped.
            if ($this->mapping_repo->is_mapped($product_id)) {
                $results['results'][$product_id] = [
                    'success' => false,
                    'error' => __('Already linked.', 'zbooks-for-woocommerce'),
                    'skipped' => true,
                ];
                continue;
            }

            $result = $this->create_item($product);

            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }

            $results['results'][$product_id] = $result;
        }

        return $results;
    }

    /**
     * Build item data for Zoho API.
     *
     * @param WC_Product $product WooCommerce product.
     * @return array Item data.
     */
    private function build_item_data(WC_Product $product): array {
        $data = [
            'name' => $product->get_name(),
            'rate' => (float) $product->get_price(),
            'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
            'product_type' => $product->is_virtual() ? 'service' : 'goods',
        ];

        $sku = $product->get_sku();
        if (!empty($sku)) {
            $data['sku'] = $sku;
        }

        // Tax settings.
        $tax_status = $product->get_tax_status();
        if ($tax_status === 'taxable') {
            $data['is_taxable'] = true;
        } else {
            $data['is_taxable'] = false;
        }

        // Allow filtering.
        return apply_filters('zbooks_item_data', $data, $product);
    }

    /**
     * Extract item_id from Zoho API response.
     *
     * The SDK may return either an array or an Item model object.
     *
     * @param mixed $response API response.
     * @return string|null Item ID or null.
     */
    private function extract_item_id($response): ?string {
        if (is_object($response)) {
            // Handle Webleit\ZohoBooksApi\Models\Item object.
            if (method_exists($response, 'getId')) {
                return $response->getId();
            }
            if (property_exists($response, 'item_id')) {
                return $response->item_id;
            }
            if (method_exists($response, 'toArray')) {
                $arr = $response->toArray();
                return $arr['item_id'] ?? null;
            }
        } elseif (is_array($response)) {
            // Handle array response.
            return $response['item']['item_id'] ?? $response['item_id'] ?? null;
        }

        return null;
    }
}
