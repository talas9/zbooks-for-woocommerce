<?php
/**
 * Product mapping admin page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Api\ZohoClient;
use Zbooks\Repository\ItemMappingRepository;
use Zbooks\Logger\SyncLogger;

defined('ABSPATH') || exit;

/**
 * Admin page for mapping WooCommerce products to Zoho items.
 */
class ProductMappingPage {

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
     * Cached Zoho items.
     *
     * @var array|null
     */
    private ?array $zoho_items_cache = null;

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
        $this->register_hooks();
    }

    /**
     * Register hooks.
     */
    private function register_hooks(): void {
        // AJAX handlers only - menu registration moved to SettingsPage.
        add_action('wp_ajax_zbooks_save_mapping', [$this, 'ajax_save_mapping']);
        add_action('wp_ajax_zbooks_remove_mapping', [$this, 'ajax_remove_mapping']);
        add_action('wp_ajax_zbooks_auto_map_products', [$this, 'ajax_auto_map']);
        add_action('wp_ajax_zbooks_fetch_zoho_items', [$this, 'ajax_fetch_zoho_items']);
        add_action('wp_ajax_zbooks_bulk_create_items', [$this, 'ajax_bulk_create_items']);
    }

    /**
     * Render the mapping page content.
     * Called by SettingsPage for the Products tab.
     */
    public function render_content(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameters for display only.
        $paged = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        $per_page = 20;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameter for display only.
        $filter = isset($_GET['filter']) ? sanitize_key(wp_unslash($_GET['filter'])) : 'all';

        $products = $this->get_products($filter, $paged, $per_page);
        $total_products = $this->count_products($filter);
        $total_pages = ceil($total_products / $per_page);

        $zoho_items = $this->get_zoho_items();
        $mappings = $this->mapping_repo->get_all();
        $mapping_count = count($mappings);

        wp_enqueue_style('zbooks-admin');
        wp_enqueue_script('zbooks-admin');
        ?>
        <div class="zbooks-products-tab">
            <h2><?php esc_html_e('Product Mapping', 'zbooks-for-woocommerce'); ?></h2>

            <p class="description">
                <?php esc_html_e('Map WooCommerce products to Zoho Books items. Mapped products will use the Zoho item ID when creating invoices.', 'zbooks-for-woocommerce'); ?>
            </p>

            <div class="zbooks-mapping-stats" style="margin: 15px 0; padding: 10px 15px; background: #f0f0f1; display: inline-flex; gap: 20px;">
                <span>
                    <strong><?php esc_html_e('Total Products:', 'zbooks-for-woocommerce'); ?></strong>
                    <?php echo esc_html($this->count_products('all')); ?>
                </span>
                <span style="color: #00a32a;">
                    <strong><?php esc_html_e('Mapped:', 'zbooks-for-woocommerce'); ?></strong>
                    <?php echo esc_html($mapping_count); ?>
                </span>
                <span style="color: #dba617;">
                    <strong><?php esc_html_e('Unmapped:', 'zbooks-for-woocommerce'); ?></strong>
                    <?php echo esc_html($this->count_products('all') - $mapping_count); ?>
                </span>
                <span>
                    <strong><?php esc_html_e('Zoho Items:', 'zbooks-for-woocommerce'); ?></strong>
                    <?php echo esc_html(count($zoho_items)); ?>
                </span>
            </div>

            <div class="zbooks-mapping-actions" style="margin: 15px 0;">
                <button type="button" id="zbooks-auto-map" class="button button-primary">
                    <?php esc_html_e('Auto-Map by SKU', 'zbooks-for-woocommerce'); ?>
                </button>
                <button type="button" id="zbooks-bulk-create" class="button button-primary" disabled>
                    <?php esc_html_e('Create Selected in Zoho', 'zbooks-for-woocommerce'); ?>
                </button>
                <button type="button" id="zbooks-refresh-items" class="button">
                    <?php esc_html_e('Refresh Zoho Items', 'zbooks-for-woocommerce'); ?>
                </button>
                <span id="zbooks-selected-count" style="margin-left: 10px; color: #646970;"></span>
                <span id="zbooks-action-status" style="margin-left: 10px;"></span>
            </div>

            <ul class="subsubsub">
                <li>
                    <a href="<?php echo esc_url(add_query_arg('filter', 'all')); ?>"
                       class="<?php echo $filter === 'all' ? 'current' : ''; ?>">
                        <?php esc_html_e('All', 'zbooks-for-woocommerce'); ?>
                        <span class="count">(<?php echo esc_html($this->count_products('all')); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('filter', 'mapped')); ?>"
                       class="<?php echo $filter === 'mapped' ? 'current' : ''; ?>">
                        <?php esc_html_e('Mapped', 'zbooks-for-woocommerce'); ?>
                        <span class="count">(<?php echo esc_html($mapping_count); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('filter', 'unmapped')); ?>"
                       class="<?php echo $filter === 'unmapped' ? 'current' : ''; ?>">
                        <?php esc_html_e('Unmapped', 'zbooks-for-woocommerce'); ?>
                        <span class="count">(<?php echo esc_html($this->count_products('all') - $mapping_count); ?>)</span>
                    </a>
                </li>
            </ul>

            <table class="widefat fixed striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" id="zbooks-select-all-products"></th>
                        <th style="width: 60px;"><?php esc_html_e('ID', 'zbooks-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Product', 'zbooks-for-woocommerce'); ?></th>
                        <th style="width: 120px;"><?php esc_html_e('SKU', 'zbooks-for-woocommerce'); ?></th>
                        <th style="width: 300px;"><?php esc_html_e('Zoho Item', 'zbooks-for-woocommerce'); ?></th>
                        <th style="width: 150px;"><?php esc_html_e('Actions', 'zbooks-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e('No products found.', 'zbooks-for-woocommerce'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($products as $product) :
                            $product_id = $product->get_id();
                            $zoho_item_id = $mappings[$product_id] ?? '';
                            $is_mapped = !empty($zoho_item_id);
                            ?>
                            <tr data-product-id="<?php echo esc_attr($product_id); ?>">
                                <td>
                                    <?php if (!$is_mapped) : ?>
                                        <input type="checkbox" class="zbooks-product-checkbox" value="<?php echo esc_attr($product_id); ?>">
                                    <?php else : ?>
                                        <span class="dashicons dashicons-yes" style="color: #00a32a;" title="<?php esc_attr_e('Mapped', 'zbooks-for-woocommerce'); ?>"></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($product_id); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>">
                                        <?php echo esc_html($product->get_name()); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($product->get_sku() ?: '-'); ?></td>
                                <td>
                                    <select class="zbooks-zoho-item-select" data-product-id="<?php echo esc_attr($product_id); ?>" style="width: 100%;">
                                        <option value=""><?php esc_html_e('-- Not Mapped --', 'zbooks-for-woocommerce'); ?></option>
                                        <?php foreach ($zoho_items as $item) : ?>
                                            <option value="<?php echo esc_attr($item['item_id']); ?>"
                                                    data-sku="<?php echo esc_attr($item['sku'] ?? ''); ?>"
                                                    <?php selected($zoho_item_id, $item['item_id']); ?>>
                                                <?php echo esc_html($item['name']); ?>
                                                <?php if (!empty($item['sku'])) : ?>
                                                    (<?php echo esc_html($item['sku']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <?php if (!$is_mapped) : ?>
                                        <button type="button" class="button button-small zbooks-create-single"
                                                data-product-id="<?php echo esc_attr($product_id); ?>">
                                            <?php esc_html_e('Create', 'zbooks-for-woocommerce'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small zbooks-save-mapping"
                                            data-product-id="<?php echo esc_attr($product_id); ?>">
                                        <?php esc_html_e('Link', 'zbooks-for-woocommerce'); ?>
                                    </button>
                                    <?php if ($is_mapped) : ?>
                                        <button type="button" class="button button-small zbooks-remove-mapping"
                                                data-product-id="<?php echo esc_attr($product_id); ?>">
                                            <?php esc_html_e('Unlink', 'zbooks-for-woocommerce'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post(
                            paginate_links([
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $paged,
                            ])
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div><!-- .zbooks-products-tab -->

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo esc_js(wp_create_nonce('zbooks_mapping')); ?>';

            // Update selected count.
            function updateSelectedCount() {
                var count = $('.zbooks-product-checkbox:checked').length;
                var $countSpan = $('#zbooks-selected-count');
                var $bulkBtn = $('#zbooks-bulk-create');

                if (count > 0) {
                    $countSpan.text(count + ' <?php echo esc_js(__('selected', 'zbooks-for-woocommerce')); ?>');
                    $bulkBtn.prop('disabled', false);
                } else {
                    $countSpan.text('');
                    $bulkBtn.prop('disabled', true);
                }
            }

            // Select all checkbox.
            $('#zbooks-select-all-products').on('change', function() {
                $('.zbooks-product-checkbox').prop('checked', $(this).is(':checked'));
                updateSelectedCount();
            });

            // Individual checkbox.
            $('.zbooks-product-checkbox').on('change', updateSelectedCount);

            // Single create button.
            $('.zbooks-create-single').on('click', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');
                var $status = $('#zbooks-action-status');

                $btn.prop('disabled', true).text('<?php echo esc_js(__('Creating...', 'zbooks-for-woocommerce')); ?>');

                $.post(ajaxurl, {
                    action: 'zbooks_bulk_create_items',
                    nonce: nonce,
                    product_ids: [productId]
                }, function(response) {
                    if (response.success) {
                        $status.text('<?php echo esc_js(__('Created!', 'zbooks-for-woocommerce')); ?>');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Create', 'zbooks-for-woocommerce')); ?>');
                        $status.text(response.data.message || 'Error creating item');
                    }
                });
            });

            // Bulk create button.
            $('#zbooks-bulk-create').on('click', function() {
                var $btn = $(this);
                var $status = $('#zbooks-action-status');
                var productIds = [];

                $('.zbooks-product-checkbox:checked').each(function() {
                    productIds.push($(this).val());
                });

                if (productIds.length === 0) {
                    return;
                }

                if (!confirm('<?php echo esc_js(__('Create', 'zbooks-for-woocommerce')); ?> ' + productIds.length + ' <?php echo esc_js(__('items in Zoho Books?', 'zbooks-for-woocommerce')); ?>')) {
                    return;
                }

                $btn.prop('disabled', true).text('<?php echo esc_js(__('Creating...', 'zbooks-for-woocommerce')); ?>');
                $status.text('<?php echo esc_js(__('Creating items in Zoho...', 'zbooks-for-woocommerce')); ?>');

                $.post(ajaxurl, {
                    action: 'zbooks_bulk_create_items',
                    nonce: nonce,
                    product_ids: productIds
                }, function(response) {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Create Selected in Zoho', 'zbooks-for-woocommerce')); ?>');
                    if (response.success) {
                        $status.text(response.data.message);
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $status.text(response.data.message || 'Error creating items');
                    }
                });
            });

            // Save mapping (link to existing).
            $('.zbooks-save-mapping').on('click', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');
                var zohoItemId = $('select[data-product-id="' + productId + '"]').val();

                if (!zohoItemId) {
                    alert('<?php echo esc_js(__('Please select a Zoho item to link.', 'zbooks-for-woocommerce')); ?>');
                    return;
                }

                $btn.prop('disabled', true).text('<?php echo esc_js(__('Linking...', 'zbooks-for-woocommerce')); ?>');

                $.post(ajaxurl, {
                    action: 'zbooks_save_mapping',
                    nonce: nonce,
                    product_id: productId,
                    zoho_item_id: zohoItemId
                }, function(response) {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Link', 'zbooks-for-woocommerce')); ?>');
                    if (response.success) {
                        $btn.text('<?php echo esc_js(__('Linked!', 'zbooks-for-woocommerce')); ?>');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        alert(response.data.message || 'Error saving mapping');
                    }
                });
            });

            // Remove mapping (unlink).
            $('.zbooks-remove-mapping').on('click', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');

                if (!confirm('<?php echo esc_js(__('Unlink this product from Zoho?', 'zbooks-for-woocommerce')); ?>')) {
                    return;
                }

                $btn.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'zbooks_remove_mapping',
                    nonce: nonce,
                    product_id: productId
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        $btn.prop('disabled', false);
                        alert(response.data.message || 'Error removing mapping');
                    }
                });
            });

            // Auto-map by SKU.
            $('#zbooks-auto-map').on('click', function() {
                var $btn = $(this);
                var $status = $('#zbooks-action-status');

                $btn.prop('disabled', true);
                $status.text('<?php echo esc_js(__('Auto-mapping products by SKU...', 'zbooks-for-woocommerce')); ?>');

                $.post(ajaxurl, {
                    action: 'zbooks_auto_map_products',
                    nonce: nonce
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $status.text(response.data.message);
                        if (response.data.mapped > 0) {
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        }
                    } else {
                        $status.text(response.data.message || 'Error during auto-mapping');
                    }
                });
            });

            // Refresh Zoho items.
            $('#zbooks-refresh-items').on('click', function() {
                var $btn = $(this);
                var $status = $('#zbooks-action-status');

                $btn.prop('disabled', true);
                $status.text('<?php echo esc_js(__('Fetching Zoho items...', 'zbooks-for-woocommerce')); ?>');

                $.post(ajaxurl, {
                    action: 'zbooks_fetch_zoho_items',
                    nonce: nonce
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $status.text(response.data.message);
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        $status.text(response.data.message || 'Error fetching items');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get WooCommerce products.
     *
     * @param string $filter Filter type (all, mapped, unmapped).
     * @param int    $page   Page number.
     * @param int    $per_page Products per page.
     * @return array Products.
     */
    private function get_products(string $filter, int $page, int $per_page): array {
        $args = [
            'status' => 'publish',
            'limit' => $per_page,
            'page' => $page,
            'orderby' => 'name',
            'order' => 'ASC',
            'return' => 'objects',
        ];

        $mappings = $this->mapping_repo->get_all();
        $mapped_ids = array_keys($mappings);

        if ($filter === 'mapped' && !empty($mapped_ids)) {
            $args['include'] = $mapped_ids;
        } elseif ($filter === 'mapped' && empty($mapped_ids)) {
            return [];
        } elseif ($filter === 'unmapped' && !empty($mapped_ids)) {
            $args['exclude'] = $mapped_ids;
        }

        return wc_get_products($args);
    }

    /**
     * Count products.
     *
     * @param string $filter Filter type.
     * @return int Count.
     */
    private function count_products(string $filter): int {
        $args = [
            'status' => 'publish',
            'limit' => -1,
            'return' => 'ids',
        ];

        $mappings = $this->mapping_repo->get_all();
        $mapped_ids = array_keys($mappings);

        if ($filter === 'mapped') {
            return count($mapped_ids);
        } elseif ($filter === 'unmapped') {
            if (!empty($mapped_ids)) {
                $args['exclude'] = $mapped_ids;
            }
        }

        return count(wc_get_products($args));
    }

    /**
     * Get Zoho items (cached in transient).
     *
     * @return array Zoho items.
     */
    private function get_zoho_items(): array {
        if ($this->zoho_items_cache !== null) {
            return $this->zoho_items_cache;
        }

        $cached = get_transient('zbooks_zoho_items');
        if ($cached !== false) {
            $this->zoho_items_cache = $cached;
            return $cached;
        }

        $items = $this->fetch_zoho_items();
        if (!empty($items)) {
            set_transient('zbooks_zoho_items', $items, HOUR_IN_SECONDS);
        }

        $this->zoho_items_cache = $items;
        return $items;
    }

    /**
     * Fetch Zoho items from API.
     *
     * @return array Zoho items.
     */
    private function fetch_zoho_items(): array {
        if (!$this->client->is_configured()) {
            return [];
        }

        try {
            $response = $this->client->request(function ($client) {
                return $client->items->getList(['per_page' => 200]);
            }, [
                'endpoint' => 'items.getList',
            ]);

            $items = [];

            // Convert object to array if needed.
            if (is_object($response)) {
                $response = json_decode(wp_json_encode($response), true);
            }

            if (is_array($response)) {
                $items_data = $response['items'] ?? $response;
                if (is_array($items_data)) {
                    foreach ($items_data as $item) {
                        if (is_array($item) && isset($item['item_id'], $item['name'])) {
                            $items[] = [
                                'item_id' => $item['item_id'],
                                'name' => $item['name'],
                                'sku' => $item['sku'] ?? '',
                                'rate' => $item['rate'] ?? 0,
                            ];
                        }
                    }
                }
            }

            usort($items, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });

            return $items;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch Zoho items', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * AJAX handler for saving a mapping.
     */
    public function ajax_save_mapping(): void {
        check_ajax_referer('zbooks_mapping', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $product_id = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        $zoho_item_id = isset($_POST['zoho_item_id']) ? sanitize_text_field(wp_unslash($_POST['zoho_item_id'])) : '';

        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID.', 'zbooks-for-woocommerce')]);
        }

        if (empty($zoho_item_id)) {
            $this->mapping_repo->remove_mapping($product_id);
            wp_send_json_success(['message' => __('Mapping removed.', 'zbooks-for-woocommerce')]);
        }

        $result = $this->mapping_repo->set_mapping($product_id, $zoho_item_id);

        if ($result) {
            $this->logger->info('Product mapping saved', [
                'product_id' => $product_id,
                'zoho_item_id' => $zoho_item_id,
            ]);
            wp_send_json_success(['message' => __('Mapping saved.', 'zbooks-for-woocommerce')]);
        } else {
            wp_send_json_error(['message' => __('Failed to save mapping.', 'zbooks-for-woocommerce')]);
        }
    }

    /**
     * AJAX handler for removing a mapping.
     */
    public function ajax_remove_mapping(): void {
        check_ajax_referer('zbooks_mapping', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $product_id = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;

        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID.', 'zbooks-for-woocommerce')]);
        }

        $this->mapping_repo->remove_mapping($product_id);

        $this->logger->info('Product mapping removed', [
            'product_id' => $product_id,
        ]);

        wp_send_json_success(['message' => __('Mapping removed.', 'zbooks-for-woocommerce')]);
    }

    /**
     * AJAX handler for auto-mapping products by SKU.
     */
    public function ajax_auto_map(): void {
        check_ajax_referer('zbooks_mapping', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $zoho_items = $this->fetch_zoho_items();
        if (empty($zoho_items)) {
            wp_send_json_error(['message' => __('No Zoho items available for mapping.', 'zbooks-for-woocommerce')]);
        }

        $mapped_count = $this->mapping_repo->auto_map_by_sku($zoho_items);

        $this->logger->info('Auto-mapping completed', [
            'mapped_count' => $mapped_count,
        ]);

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of products mapped */
                __('Auto-mapped %d product(s) by SKU.', 'zbooks-for-woocommerce'),
                $mapped_count
            ),
            'mapped' => $mapped_count,
        ]);
    }

    /**
     * AJAX handler for fetching Zoho items.
     */
    public function ajax_fetch_zoho_items(): void {
        check_ajax_referer('zbooks_mapping', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        delete_transient('zbooks_zoho_items');

        $items = $this->fetch_zoho_items();
        if (!empty($items)) {
            set_transient('zbooks_zoho_items', $items, HOUR_IN_SECONDS);
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of items fetched */
                __('Fetched %d Zoho item(s).', 'zbooks-for-woocommerce'),
                count($items)
            ),
            'count' => count($items),
        ]);
    }

    /**
     * AJAX handler for bulk creating items in Zoho.
     */
    public function ajax_bulk_create_items(): void {
        check_ajax_referer('zbooks_mapping', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $product_ids = isset($_POST['product_ids']) ? array_map('absint', wp_unslash((array) $_POST['product_ids'])) : [];

        if (empty($product_ids)) {
            wp_send_json_error(['message' => __('No products selected.', 'zbooks-for-woocommerce')]);
        }

        if (!$this->client->is_configured()) {
            wp_send_json_error(['message' => __('Zoho Books not configured.', 'zbooks-for-woocommerce')]);
        }

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);

            if (!$product) {
                $failed++;
                continue;
            }

            // Skip if already mapped.
            if ($this->mapping_repo->is_mapped($product_id)) {
                continue;
            }

            try {
                $item_data = $this->build_item_data($product);

                $response = $this->client->request(function ($client) use ($item_data) {
                    return $client->items->create($item_data);
                }, [
                    'endpoint' => 'items.create',
                    'product_id' => $product_id,
                    'product_name' => $product->get_name(),
                ]);

                // SDK returns Item model object directly, not an array.
                $zoho_item_id = $this->extract_item_id($response);

                if ($zoho_item_id) {
                    $this->mapping_repo->set_mapping($product_id, $zoho_item_id);

                    $this->logger->info('Zoho item created via bulk', [
                        'product_id' => $product_id,
                        'zoho_item_id' => $zoho_item_id,
                    ]);

                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
                $errors[] = $product->get_name() . ': ' . $e->getMessage();

                $this->logger->error('Failed to create Zoho item via bulk', [
                    'product_id' => $product_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = sprintf(
            /* translators: 1: success count, 2: failed count */
            __('Created %1$d item(s), %2$d failed.', 'zbooks-for-woocommerce'),
            $success,
            $failed
        );

        if ($failed > 0 && !empty($errors)) {
            $message .= ' ' . implode('; ', array_slice($errors, 0, 3));
        }

        wp_send_json_success([
            'message' => $message,
            'success' => $success,
            'failed' => $failed,
        ]);
    }

    /**
     * Build item data for Zoho API.
     *
     * @param \WC_Product $product WooCommerce product.
     * @return array Item data.
     */
    private function build_item_data(\WC_Product $product): array {
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
