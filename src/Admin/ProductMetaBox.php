<?php
/**
 * Product meta box.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use WC_Product;
use Zbooks\Api\ZohoClient;
use Zbooks\Repository\ItemMappingRepository;

defined('ABSPATH') || exit;

/**
 * Meta box for displaying Zoho item info on product page.
 */
class ProductMetaBox {

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
     * Constructor.
     *
     * @param ZohoClient            $client      Zoho client.
     * @param ItemMappingRepository $mapping_repo Item mapping repository.
     */
    public function __construct(ZohoClient $client, ItemMappingRepository $mapping_repo) {
        $this->client = $client;
        $this->mapping_repo = $mapping_repo;
        $this->register_hooks();
    }

    /**
     * Register hooks.
     */
    private function register_hooks(): void {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('wp_ajax_zbooks_create_zoho_item', [$this, 'ajax_create_item']);
        add_action('wp_ajax_zbooks_sync_product_to_zoho', [$this, 'ajax_sync_product']);
        add_action('wp_ajax_zbooks_search_and_link_item', [$this, 'ajax_search_and_link_item']);
    }

    /**
     * Add meta box to product page.
     */
    public function add_meta_box(): void {
        add_meta_box(
            'zbooks_product_sync',
            __('Zoho Books Item', 'zbooks-for-woocommerce'),
            [$this, 'render_meta_box'],
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render meta box content.
     *
     * @param \WP_Post $post Post object.
     */
    public function render_meta_box($post): void {
        $product = wc_get_product($post->ID);

        if (!$product) {
            return;
        }

        $product_id = $product->get_id();
        $zoho_item_id = $this->mapping_repo->get_zoho_item_id($product_id);
        $zoho_item = null;

        if ($zoho_item_id) {
            $zoho_item = $this->get_zoho_item($zoho_item_id);
        }

        wp_nonce_field('zbooks_product_meta', 'zbooks_product_nonce');
        ?>
        <div class="zbooks-product-meta-box">
            <?php if ($zoho_item_id && $zoho_item) : ?>
                <div class="zbooks-item-info">
                    <p>
                        <strong><?php esc_html_e('Status:', 'zbooks-for-woocommerce'); ?></strong>
                        <span class="zbooks-status zbooks-status-synced">
                            <?php esc_html_e('Linked', 'zbooks-for-woocommerce'); ?>
                        </span>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Zoho Item ID:', 'zbooks-for-woocommerce'); ?></strong>
                        <a href="<?php echo esc_url($this->get_zoho_url($zoho_item_id)); ?>" target="_blank">
                            <?php echo esc_html($zoho_item_id); ?>
                        </a>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Name:', 'zbooks-for-woocommerce'); ?></strong>
                        <?php echo esc_html($zoho_item['name'] ?? '-'); ?>
                    </p>
                    <?php if (!empty($zoho_item['sku'])) : ?>
                        <p>
                            <strong><?php esc_html_e('SKU:', 'zbooks-for-woocommerce'); ?></strong>
                            <?php echo esc_html($zoho_item['sku']); ?>
                        </p>
                    <?php endif; ?>
                    <?php if (isset($zoho_item['rate'])) : ?>
                        <p>
                            <strong><?php esc_html_e('Rate:', 'zbooks-for-woocommerce'); ?></strong>
                            <?php echo wp_kses_post(wc_price($zoho_item['rate'])); ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($zoho_item['status'])) : ?>
                        <p>
                            <strong><?php esc_html_e('Item Status:', 'zbooks-for-woocommerce'); ?></strong>
                            <?php echo esc_html(ucfirst($zoho_item['status'])); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <hr>

                <p>
                    <button type="button"
                        class="button zbooks-sync-product-btn"
                        data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Update in Zoho', 'zbooks-for-woocommerce'); ?>
                    </button>
                    <button type="button"
                        class="button zbooks-unlink-btn"
                        data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Unlink', 'zbooks-for-woocommerce'); ?>
                    </button>
                </p>
            <?php elseif ($zoho_item_id && !$zoho_item) : ?>
                <p>
                    <strong><?php esc_html_e('Status:', 'zbooks-for-woocommerce'); ?></strong>
                    <span class="zbooks-status zbooks-status-pending" style="color: #dba617;">
                        <?php esc_html_e('Linked (item not found)', 'zbooks-for-woocommerce'); ?>
                    </span>
                </p>
                <p>
                    <strong><?php esc_html_e('Zoho Item ID:', 'zbooks-for-woocommerce'); ?></strong>
                    <?php echo esc_html($zoho_item_id); ?>
                </p>
                <p class="description" style="color: #d63638;">
                    <?php esc_html_e('The linked Zoho item could not be found. It may have been deleted.', 'zbooks-for-woocommerce'); ?>
                </p>

                <hr>

                <p>
                    <button type="button"
                        class="button button-primary zbooks-create-item-btn"
                        data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Create in Zoho', 'zbooks-for-woocommerce'); ?>
                    </button>
                    <button type="button"
                        class="button zbooks-unlink-btn"
                        data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Unlink', 'zbooks-for-woocommerce'); ?>
                    </button>
                </p>
            <?php else : ?>
                <p>
                    <strong><?php esc_html_e('Status:', 'zbooks-for-woocommerce'); ?></strong>
                    <span class="zbooks-status zbooks-status-none">
                        <?php esc_html_e('Not linked', 'zbooks-for-woocommerce'); ?>
                    </span>
                </p>
                <p class="description">
                    <?php esc_html_e('This product is not linked to a Zoho Books item.', 'zbooks-for-woocommerce'); ?>
                </p>

                <hr>

                <?php $this->render_inventory_tracking_option($product); ?>

                <p>
                    <button type="button"
                        class="button button-primary zbooks-create-item-btn"
                        data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Create in Zoho', 'zbooks-for-woocommerce'); ?>
                    </button>
                    <button type="button"
                        class="button zbooks-link-existing-btn"
                        data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Link Existing', 'zbooks-for-woocommerce'); ?>
                    </button>
                </p>
            <?php endif; ?>

            <p class="zbooks-product-result"></p>
        </div>

        <style>
            .zbooks-product-meta-box .zbooks-status {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
            }
            .zbooks-product-meta-box .zbooks-status-synced {
                background: #d4edda;
                color: #155724;
            }
            .zbooks-product-meta-box .zbooks-status-none {
                background: #f0f0f1;
                color: #50575e;
            }
            .zbooks-product-meta-box hr {
                margin: 12px 0;
            }
            .zbooks-product-meta-box .button {
                margin-right: 5px;
                margin-bottom: 5px;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo esc_js(wp_create_nonce('zbooks_product_ajax')); ?>';

            function createZohoItem(productId, trackInventory, $btn, $result) {
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Creating...', 'zbooks-for-woocommerce')); ?>');
                $result.html('');

                $.post(ajaxurl, {
                    action: 'zbooks_create_zoho_item',
                    nonce: nonce,
                    product_id: productId,
                    track_inventory: trackInventory ? '1' : '0'
                }, function(response) {
                    if (response.success) {
                        $result.html('<span style="color:green;">' + response.data.message + '</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Create in Zoho', 'zbooks-for-woocommerce')); ?>');

                        // Check if this is a duplicate/already exists error.
                        if (response.data && response.data.can_link_existing) {
                            showDuplicateErrorDialog(response.data.message, productId, response.data.search_term || '', $btn, $result);
                        }
                        // Check if this is an inventory-related error with retry option.
                        else if (response.data && response.data.can_retry_without_tracking) {
                            showInventoryErrorDialog(response.data.message, productId, $btn, $result);
                        } else {
                            $result.html('<span style="color:red;">' + (response.data.message || 'Error creating item') + '</span>');
                        }
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Create in Zoho', 'zbooks-for-woocommerce')); ?>');
                    $result.html('<span style="color:red;">Network error</span>');
                });
            }

            function showInventoryErrorDialog(errorMessage, productId, $btn, $result) {
                var dialogHtml = '<div class="zbooks-inventory-error-dialog" style="background:#fff3cd;border:1px solid #ffc107;padding:12px;border-radius:4px;margin-top:10px;">' +
                    '<p style="margin:0 0 10px;color:#856404;"><strong><?php echo esc_js(__('Inventory Tracking Error', 'zbooks-for-woocommerce')); ?></strong></p>' +
                    '<p style="margin:0 0 10px;color:#856404;font-size:12px;">' + errorMessage + '</p>' +
                    '<p style="margin:0 0 10px;color:#856404;font-size:12px;"><?php echo esc_js(__('This feature requires Zoho Inventory integration with your Zoho Books subscription.', 'zbooks-for-woocommerce')); ?></p>' +
                    '<p style="margin:0;">' +
                    '<button type="button" class="button zbooks-retry-without-tracking" style="margin-right:5px;"><?php echo esc_js(__('Create without inventory tracking', 'zbooks-for-woocommerce')); ?></button>' +
                    '<button type="button" class="button zbooks-cancel-create"><?php echo esc_js(__('Cancel', 'zbooks-for-woocommerce')); ?></button>' +
                    '</p></div>';

                $result.html(dialogHtml);

                // Handle retry without tracking.
                $result.find('.zbooks-retry-without-tracking').on('click', function() {
                    // Uncheck the inventory tracking checkbox.
                    $('.zbooks-track-inventory').prop('checked', false);
                    createZohoItem(productId, false, $btn, $result);
                });

                // Handle cancel.
                $result.find('.zbooks-cancel-create').on('click', function() {
                    $result.html('');
                });
            }

            function showDuplicateErrorDialog(errorMessage, productId, searchTerm, $btn, $result) {
                var dialogHtml = '<div class="zbooks-duplicate-error-dialog" style="background:#f8d7da;border:1px solid #f5c6cb;padding:12px;border-radius:4px;margin-top:10px;">' +
                    '<p style="margin:0 0 10px;color:#721c24;"><strong><?php echo esc_js(__('Item Already Exists', 'zbooks-for-woocommerce')); ?></strong></p>' +
                    '<p style="margin:0 0 10px;color:#721c24;font-size:12px;">' + errorMessage + '</p>' +
                    '<p style="margin:0 0 10px;color:#721c24;font-size:12px;"><?php echo esc_js(__('Would you like to search for the existing item and link it to this product?', 'zbooks-for-woocommerce')); ?></p>' +
                    '<p style="margin:0;">' +
                    '<button type="button" class="button button-primary zbooks-search-existing" style="margin-right:5px;"><?php echo esc_js(__('Search & Link Existing', 'zbooks-for-woocommerce')); ?></button>' +
                    '<button type="button" class="button zbooks-cancel-create"><?php echo esc_js(__('Cancel', 'zbooks-for-woocommerce')); ?></button>' +
                    '</p></div>';

                $result.html(dialogHtml);

                // Handle search existing.
                $result.find('.zbooks-search-existing').on('click', function() {
                    searchAndShowItems(productId, searchTerm, $btn, $result);
                });

                // Handle cancel.
                $result.find('.zbooks-cancel-create').on('click', function() {
                    $result.html('');
                });
            }

            function searchAndShowItems(productId, searchTerm, $btn, $result) {
                $result.html('<p style="color:#666;"><span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span><?php echo esc_js(__('Searching...', 'zbooks-for-woocommerce')); ?></p>');

                $.post(ajaxurl, {
                    action: 'zbooks_search_and_link_item',
                    nonce: nonce,
                    product_id: productId,
                    search_term: searchTerm
                }, function(response) {
                    if (response.success && response.data.items && response.data.items.length > 0) {
                        showItemSelectionDialog(response.data.items, productId, $btn, $result);
                    } else {
                        $result.html('<span style="color:red;">' + (response.data.message || '<?php echo esc_js(__('No items found.', 'zbooks-for-woocommerce')); ?>') + '</span>');
                    }
                }).fail(function() {
                    $result.html('<span style="color:red;"><?php echo esc_js(__('Search failed. Please try again.', 'zbooks-for-woocommerce')); ?></span>');
                });
            }

            function showItemSelectionDialog(items, productId, $btn, $result) {
                var dialogHtml = '<div class="zbooks-item-selection" style="background:#e7f3ff;border:1px solid #b3d7ff;padding:12px;border-radius:4px;margin-top:10px;">' +
                    '<p style="margin:0 0 10px;color:#004085;"><strong><?php echo esc_js(__('Select Item to Link', 'zbooks-for-woocommerce')); ?></strong></p>' +
                    '<div style="max-height:200px;overflow-y:auto;margin-bottom:10px;">';

                items.forEach(function(item, index) {
                    var itemInfo = item.name;
                    if (item.sku) {
                        itemInfo += ' (SKU: ' + item.sku + ')';
                    }
                    if (item.rate) {
                        itemInfo += ' - ' + item.rate;
                    }

                    dialogHtml += '<label style="display:block;padding:8px;margin:4px 0;background:#fff;border:1px solid #ddd;border-radius:3px;cursor:pointer;">' +
                        '<input type="radio" name="zbooks_select_item" value="' + item.item_id + '" ' + (index === 0 ? 'checked' : '') + ' style="margin-right:8px;">' +
                        '<span>' + itemInfo + '</span>' +
                        '</label>';
                });

                dialogHtml += '</div>' +
                    '<p style="margin:0;">' +
                    '<button type="button" class="button button-primary zbooks-link-selected" style="margin-right:5px;"><?php echo esc_js(__('Link Selected', 'zbooks-for-woocommerce')); ?></button>' +
                    '<button type="button" class="button zbooks-cancel-create"><?php echo esc_js(__('Cancel', 'zbooks-for-woocommerce')); ?></button>' +
                    '</p></div>';

                $result.html(dialogHtml);

                // Handle link selected.
                $result.find('.zbooks-link-selected').on('click', function() {
                    var selectedItemId = $result.find('input[name="zbooks_select_item"]:checked').val();
                    if (selectedItemId) {
                        linkItemToProduct(productId, selectedItemId, $result);
                    }
                });

                // Handle cancel.
                $result.find('.zbooks-cancel-create').on('click', function() {
                    $result.html('');
                });
            }

            function linkItemToProduct(productId, itemId, $result) {
                $result.html('<p style="color:#666;"><span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span><?php echo esc_js(__('Linking...', 'zbooks-for-woocommerce')); ?></p>');

                $.post(ajaxurl, {
                    action: 'zbooks_save_mapping',
                    nonce: '<?php echo esc_js(wp_create_nonce('zbooks_mapping')); ?>',
                    product_id: productId,
                    zoho_item_id: itemId
                }, function(response) {
                    if (response.success) {
                        $result.html('<span style="color:green;"><?php echo esc_js(__('Item linked successfully!', 'zbooks-for-woocommerce')); ?></span>');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.html('<span style="color:red;">' + (response.data.message || '<?php echo esc_js(__('Failed to link item.', 'zbooks-for-woocommerce')); ?>') + '</span>');
                    }
                }).fail(function() {
                    $result.html('<span style="color:red;"><?php echo esc_js(__('Network error while linking.', 'zbooks-for-woocommerce')); ?></span>');
                });
            }

            $('.zbooks-create-item-btn').on('click', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');
                var $result = $('.zbooks-product-result');
                var trackInventory = $('.zbooks-track-inventory').is(':checked');

                createZohoItem(productId, trackInventory, $btn, $result);
            });

            $('.zbooks-link-existing-btn').on('click', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');
                var $result = $('.zbooks-product-result');
                var $createBtn = $('.zbooks-create-item-btn');

                searchAndShowItems(productId, '', $createBtn, $result);
            });

            $('.zbooks-sync-product-btn').on('click', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');
                var $result = $('.zbooks-product-result');

                $btn.prop('disabled', true).text('<?php echo esc_js(__('Updating...', 'zbooks-for-woocommerce')); ?>');
                $result.html('');

                $.post(ajaxurl, {
                    action: 'zbooks_sync_product_to_zoho',
                    nonce: nonce,
                    product_id: productId
                }, function(response) {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Update in Zoho', 'zbooks-for-woocommerce')); ?>');
                    if (response.success) {
                        $result.html('<span style="color:green;">' + response.data.message + '</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.html('<span style="color:red;">' + (response.data.message || 'Error updating item') + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Update in Zoho', 'zbooks-for-woocommerce')); ?>');
                    $result.html('<span style="color:red;">Network error</span>');
                });
            });

            $('.zbooks-unlink-btn').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Remove the link to this Zoho item? This will not delete the item in Zoho.', 'zbooks-for-woocommerce')); ?>')) {
                    return;
                }

                var $btn = $(this);
                var productId = $btn.data('product-id');
                var $result = $('.zbooks-product-result');

                $btn.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'zbooks_remove_mapping',
                    nonce: '<?php echo esc_js(wp_create_nonce('zbooks_mapping')); ?>',
                    product_id: productId
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        $btn.prop('disabled', false);
                        $result.html('<span style="color:red;">' + (response.data.message || 'Error unlinking') + '</span>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get Zoho item details.
     *
     * @param string $item_id Zoho item ID.
     * @return array|null Item details or null.
     */
    private function get_zoho_item(string $item_id): ?array {
        if (!$this->client->is_configured()) {
            return null;
        }

        // Try cache first.
        $cached = get_transient('zbooks_zoho_item_' . $item_id);
        if ($cached !== false && is_array($cached) && !empty($cached['item_id'])) {
            return $cached;
        }

        try {
            $response = $this->client->request(function ($client) use ($item_id) {
                return $client->items->get($item_id);
            });

            $item = null;

            // Handle SDK model object (Webleit\ZohoBooksApi\Models\Item).
            if (is_object($response)) {
                // Try toArray first (most SDK models have this).
                if (method_exists($response, 'toArray')) {
                    $item = $response->toArray();
                } elseif (method_exists($response, 'getData')) {
                    $item = $response->getData();
                } elseif (method_exists($response, 'getArrayCopy')) {
                    $item = $response->getArrayCopy();
                } else {
                    // Try json encode/decode to get all properties.
                    $json = wp_json_encode($response);
                    if ($json) {
                        $item = json_decode($json, true);
                    }
                    // Fallback to casting.
                    if (empty($item)) {
                        $item = (array) $response;
                    }
                }
            } elseif (is_array($response)) {
                $item = $response['item'] ?? $response;
            }

            // Validate item has required fields.
            if ($item && !empty($item['item_id'])) {
                set_transient('zbooks_zoho_item_' . $item_id, $item, 5 * MINUTE_IN_SECONDS);
                return $item;
            }

            // If item_id not in data, try to add it from the requested ID.
            if ($item && is_array($item)) {
                $item['item_id'] = $item_id;
                set_transient('zbooks_zoho_item_' . $item_id, $item, 5 * MINUTE_IN_SECONDS);
                return $item;
            }

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[ZBooks for WooCommerce] Get item returned invalid data for ' . $item_id . ': ' . wp_json_encode($response));
            return null;
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[ZBooks for WooCommerce] Get item error for ' . $item_id . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get Zoho Books URL for an item.
     *
     * @param string $item_id Item ID.
     * @return string URL.
     */
    private function get_zoho_url(string $item_id): string {
        $datacenter = get_option('zbooks_datacenter', 'us');

        $domains = [
            'us' => 'books.zoho.com',
            'eu' => 'books.zoho.eu',
            'in' => 'books.zoho.in',
            'au' => 'books.zoho.com.au',
            'jp' => 'books.zoho.jp',
        ];

        $domain = $domains[$datacenter] ?? 'books.zoho.com';

        return sprintf('https://%s/app#/inventory/items/%s', $domain, $item_id);
    }

    /**
     * AJAX handler for creating a Zoho item.
     */
    public function ajax_create_item(): void {
        check_ajax_referer('zbooks_product_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $track_inventory = isset($_POST['track_inventory']) && $_POST['track_inventory'] === '1';

        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID.', 'zbooks-for-woocommerce')]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => __('Product not found.', 'zbooks-for-woocommerce')]);
        }

        if (!$this->client->is_configured()) {
            wp_send_json_error(['message' => __('Zoho Books not configured.', 'zbooks-for-woocommerce')]);
        }

        try {
            $item_data = $this->build_item_data($product, $track_inventory);

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[ZBooks for WooCommerce] Creating Zoho item for product #' . $product_id . ': ' . wp_json_encode($item_data));

            $response = $this->client->request(function ($client) use ($item_data) {
                return $client->items->create($item_data);
            });

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[ZBooks for WooCommerce] Zoho create response type: ' . gettype($response) . (is_object($response) ? ' (' . get_class($response) . ')' : ''));

            // SDK returns Item model object directly, not an array.
            $zoho_item_id = null;

            if (is_object($response)) {
                // Handle Webleit\ZohoBooksApi\Models\Item object.
                if (method_exists($response, 'getId')) {
                    $zoho_item_id = $response->getId();
                } elseif (property_exists($response, 'item_id')) {
                    $zoho_item_id = $response->item_id;
                } elseif (method_exists($response, 'toArray')) {
                    $arr = $response->toArray();
                    $zoho_item_id = $arr['item_id'] ?? null;
                }
            } elseif (is_array($response)) {
                // Handle array response (legacy or different SDK version).
                $zoho_item_id = $response['item']['item_id'] ?? $response['item_id'] ?? null;
            }

            if ($zoho_item_id) {
                $this->mapping_repo->set_mapping($product_id, $zoho_item_id);

                // Cache the item data immediately so it's available on page reload.
                $item_cache = null;
                if (is_object($response)) {
                    if (method_exists($response, 'toArray')) {
                        $item_cache = $response->toArray();
                    } elseif (method_exists($response, 'getData')) {
                        $item_cache = $response->getData();
                    }
                } elseif (is_array($response)) {
                    $item_cache = $response['item'] ?? $response;
                }

                if ($item_cache) {
                    set_transient('zbooks_zoho_item_' . $zoho_item_id, $item_cache, 5 * MINUTE_IN_SECONDS);
                }

                wp_send_json_success([
                    'message' => __('Item created in Zoho Books!', 'zbooks-for-woocommerce'),
                    'item_id' => $zoho_item_id,
                ]);
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[ZBooks for WooCommerce] Could not extract item_id from response');
                wp_send_json_error(['message' => __('Failed to create item. Could not get item ID.', 'zbooks-for-woocommerce')]);
            }
        } catch (\Throwable $e) {
            // Log full error details.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[ZBooks for WooCommerce] Create item error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

            $error_message = $this->extract_api_error($e);

            // Check if this is an inventory subscription error.
            $is_inventory_error = $track_inventory && $this->is_inventory_subscription_error($error_message);

            // Check if this is a duplicate/already exists error.
            $is_duplicate_error = $this->is_duplicate_item_error($error_message);

            wp_send_json_error([
                'message' => $error_message,
                'inventory_error' => $is_inventory_error,
                'can_retry_without_tracking' => $is_inventory_error,
                'duplicate_error' => $is_duplicate_error,
                'can_link_existing' => $is_duplicate_error,
                'search_term' => $is_duplicate_error ? $product->get_sku() ?: $product->get_name() : '',
            ]);
        }
    }

    /**
     * AJAX handler for syncing/updating a product to Zoho.
     */
    public function ajax_sync_product(): void {
        check_ajax_referer('zbooks_product_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID.', 'zbooks-for-woocommerce')]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => __('Product not found.', 'zbooks-for-woocommerce')]);
        }

        $zoho_item_id = $this->mapping_repo->get_zoho_item_id($product_id);
        if (!$zoho_item_id) {
            wp_send_json_error(['message' => __('Product not linked to Zoho.', 'zbooks-for-woocommerce')]);
        }

        if (!$this->client->is_configured()) {
            wp_send_json_error(['message' => __('Zoho Books not configured.', 'zbooks-for-woocommerce')]);
        }

        try {
            $item_data = $this->build_item_data($product);

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[ZBooks for WooCommerce] Updating Zoho item ' . $zoho_item_id . ': ' . wp_json_encode($item_data));

            $this->client->request(function ($client) use ($zoho_item_id, $item_data) {
                return $client->items->update($zoho_item_id, $item_data);
            });

            // Clear cache.
            delete_transient('zbooks_zoho_item_' . $zoho_item_id);

            wp_send_json_success([
                'message' => __('Item updated in Zoho Books!', 'zbooks-for-woocommerce'),
            ]);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[ZBooks for WooCommerce] Update item error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

            $error_message = $this->extract_api_error($e);
            wp_send_json_error(['message' => $error_message]);
        }
    }

    /**
     * Extract detailed error message from API exceptions.
     *
     * @param \Throwable $e Exception.
     * @return string Error message.
     */
    private function extract_api_error(\Throwable $e): string {
        $message = $e->getMessage();

        // Check for Guzzle HTTP exceptions with response body.
        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if ($response && method_exists($response, 'getBody')) {
                $body = (string) $response->getBody();
                $data = json_decode($body, true);
                if (isset($data['message'])) {
                    $message = $data['message'];
                    if (isset($data['code'])) {
                        $message = '[' . $data['code'] . '] ' . $message;
                    }
                }
            }
        }

        // Check previous exception.
        $prev = $e->getPrevious();
        if ($prev && method_exists($prev, 'getResponse')) {
            $response = $prev->getResponse();
            if ($response && method_exists($response, 'getBody')) {
                $body = (string) $response->getBody();
                $data = json_decode($body, true);
                if (isset($data['message'])) {
                    $message = $data['message'];
                    if (isset($data['code'])) {
                        $message = '[' . $data['code'] . '] ' . $message;
                    }
                }
            }
        }

        return $message;
    }

    /**
     * Build item data for Zoho API.
     *
     * @param WC_Product $product        WooCommerce product.
     * @param bool       $track_inventory Whether to track inventory in Zoho.
     * @return array Item data.
     */
    private function build_item_data(WC_Product $product, bool $track_inventory = false): array {
        $is_virtual = $product->is_virtual();

        $data = [
            'name' => $product->get_name(),
            'rate' => (float) $product->get_price(),
            'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
            'product_type' => $is_virtual ? 'service' : 'goods',
        ];

        // Set item_type based on inventory tracking option.
        // - 'inventory': Tracks stock (requires Zoho Inventory integration).
        // - 'sales': Standard item, no stock tracking.
        // - 'service': For virtual/downloadable products.
        if ($is_virtual) {
            $data['item_type'] = 'service';
        } elseif ($track_inventory) {
            $data['item_type'] = 'inventory';
            // Include stock quantity if managing stock and price is set.
            // Zoho requires a positive opening stock rate.
            if ($product->managing_stock()) {
                $stock_qty = $product->get_stock_quantity() ?: 0;
                $stock_rate = (float) $product->get_price();

                // Only include initial stock if both quantity and rate are positive.
                if ($stock_qty > 0 && $stock_rate > 0) {
                    $data['initial_stock'] = $stock_qty;
                    $data['initial_stock_rate'] = $stock_rate;
                }
            }
        } else {
            $data['item_type'] = 'sales';
        }

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

        return apply_filters('zbooks_item_data', $data, $product);
    }

    /**
     * Render inventory tracking option for create form.
     *
     * @param WC_Product $product WooCommerce product.
     */
    private function render_inventory_tracking_option(WC_Product $product): void {
        // Don't show for virtual products (they become services).
        if ($product->is_virtual()) {
            return;
        }

        $manages_stock = $product->managing_stock();
        ?>
        <div class="zbooks-inventory-option" style="margin-bottom: 12px;">
            <?php if ($manages_stock) : ?>
                <label>
                    <input type="checkbox" class="zbooks-track-inventory" value="1" checked>
                    <?php esc_html_e('Track inventory in Zoho', 'zbooks-for-woocommerce'); ?>
                </label>
                <p class="description" style="margin-top: 4px; margin-left: 24px;">
                    <?php esc_html_e('Requires Zoho Inventory integration with your Zoho Books account.', 'zbooks-for-woocommerce'); ?>
                </p>
            <?php else : ?>
                <p class="description" style="color: #646970;">
                    <span class="dashicons dashicons-info" style="font-size: 16px; width: 16px; height: 16px;"></span>
                    <?php
                    printf(
                        /* translators: %s: link to inventory tab */
                        esc_html__('To track inventory in Zoho, first enable %s in the Inventory tab of this product.', 'zbooks-for-woocommerce'),
                        '<strong>' . esc_html__('Stock management', 'zbooks-for-woocommerce') . '</strong>'
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Check if error is related to Zoho Inventory subscription.
     *
     * @param string $error_message Error message.
     * @return bool True if inventory subscription error.
     */
    private function is_inventory_subscription_error(string $error_message): bool {
        $inventory_error_patterns = [
            'inventory',
            'subscription',
            'upgrade',
            'not enabled',
            'not available',
            'item_type',
            'initial_stock',
        ];

        $lower_message = strtolower($error_message);
        foreach ($inventory_error_patterns as $pattern) {
            if (str_contains($lower_message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if error indicates a duplicate item exists.
     *
     * @param string $error_message Error message.
     * @return bool True if duplicate item error.
     */
    private function is_duplicate_item_error(string $error_message): bool {
        $duplicate_error_patterns = [
            'already exists',
            'duplicate',
            'sku already',
            'item name already',
            'unique constraint',
            'already been used',
        ];

        $lower_message = strtolower($error_message);
        foreach ($duplicate_error_patterns as $pattern) {
            if (str_contains($lower_message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * AJAX handler for searching and linking to existing Zoho item.
     */
    public function ajax_search_and_link_item(): void {
        check_ajax_referer('zbooks_product_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $search_term = isset($_POST['search_term']) ? sanitize_text_field(wp_unslash($_POST['search_term'])) : '';

        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID.', 'zbooks-for-woocommerce')]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => __('Product not found.', 'zbooks-for-woocommerce')]);
        }

        if (!$this->client->is_configured()) {
            wp_send_json_error(['message' => __('Zoho Books not configured.', 'zbooks-for-woocommerce')]);
        }

        // Use SKU for search if available, otherwise use name.
        if (empty($search_term)) {
            $search_term = $product->get_sku() ?: $product->get_name();
        }

        try {
            // Search for items in Zoho.
            $items = $this->search_zoho_items($search_term);

            if (empty($items)) {
                wp_send_json_error([
                    'message' => __('No matching items found in Zoho Books.', 'zbooks-for-woocommerce'),
                    'items' => [],
                ]);
            }

            // Return found items for user to select.
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %d: number of items found */
                    _n('%d item found', '%d items found', count($items), 'zbooks-for-woocommerce'),
                    count($items)
                ),
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[ZBooks for WooCommerce] Search items error: ' . $e->getMessage());

            $error_message = $this->extract_api_error($e);
            wp_send_json_error(['message' => $error_message]);
        }
    }

    /**
     * Search for items in Zoho Books.
     *
     * @param string $search_term Search term (SKU or name).
     * @return array List of matching items.
     */
    private function search_zoho_items(string $search_term): array {
        $response = $this->client->request(function ($client) use ($search_term) {
            return $client->items->getList(['search_text' => $search_term]);
        });

        $items = [];

        // Handle different response types.
        if (is_object($response)) {
            if (method_exists($response, 'toArray')) {
                $response = $response->toArray();
            } else {
                $response = json_decode(wp_json_encode($response), true);
            }
        }

        if (is_array($response)) {
            $items = $response['items'] ?? $response;
        }

        // Format items for display.
        $formatted = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                $item_data = is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : (array) $item;

                if (isset($item_data['item_id'], $item_data['name'])) {
                    $formatted[] = [
                        'item_id' => $item_data['item_id'],
                        'name' => $item_data['name'],
                        'sku' => $item_data['sku'] ?? '',
                        'rate' => $item_data['rate'] ?? 0,
                        'status' => $item_data['status'] ?? '',
                    ];
                }
            }
        }

        return array_slice($formatted, 0, 10); // Limit to 10 results.
    }
}
