<?php
/**
 * Main Plugin class.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks;

use Zbooks\Admin\SettingsPage;
use Zbooks\Admin\OrderMetaBox;
use Zbooks\Admin\AdminNotices;
use Zbooks\Admin\BulkSyncPage;
use Zbooks\Admin\SetupWizard;
use Zbooks\Admin\LogViewer;
use Zbooks\Admin\ProductMappingPage;
use Zbooks\Admin\ProductMetaBox;
use Zbooks\Admin\FieldMappingPage;
use Zbooks\Admin\PaymentMappingPage;
use Zbooks\Repository\ItemMappingRepository;
use Zbooks\Repository\FieldMappingRepository;
use Zbooks\Repository\PaymentMethodMappingRepository;
use Zbooks\Api\ZohoClient;
use Zbooks\Api\TokenManager;
use Zbooks\Api\RateLimiter;
use Zbooks\Service\CustomerService;
use Zbooks\Service\InvoiceService;
use Zbooks\Service\PaymentService;
use Zbooks\Service\RefundService;
use Zbooks\Service\SyncOrchestrator;
use Zbooks\Service\BulkSyncService;
use Zbooks\Hooks\OrderStatusHooks;
use Zbooks\Hooks\OrderBulkActions;
use Zbooks\Hooks\AjaxHandlers;
use Zbooks\Repository\OrderMetaRepository;
use Zbooks\Logger\SyncLogger;
use Zbooks\Cron\RetryFailedSyncs;

defined('ABSPATH') || exit;

/**
 * Main plugin singleton class.
 */
final class Plugin {

    /**
     * Plugin instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Service container.
     *
     * @var array<string, object>
     */
    private array $services = [];

    /**
     * Get plugin instance.
     *
     * @return self
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        $this->init_services();
        $this->init_hooks();
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserialization.
     *
     * @throws \Exception Always throws exception.
     */
    public function __wakeup(): void {
        throw new \Exception('Cannot unserialize singleton');
    }

    /**
     * Initialize services.
     */
    private function init_services(): void {
        // Core services.
        $this->services['logger'] = new SyncLogger();
        $this->services['token_manager'] = new TokenManager();
        $this->services['rate_limiter'] = new RateLimiter();

        // API client.
        $this->services['zoho_client'] = new ZohoClient(
            $this->get_service('token_manager'),
            $this->get_service('rate_limiter'),
            $this->get_service('logger')
        );

        // Repositories.
        $this->services['order_meta_repository'] = new OrderMetaRepository();
        $this->services['item_mapping_repository'] = new ItemMappingRepository();
        $this->services['field_mapping_repository'] = new FieldMappingRepository();
        $this->services['payment_method_mapping_repository'] = new PaymentMethodMappingRepository();

        // Business services.
        $this->services['customer_service'] = new CustomerService(
            $this->get_service('zoho_client'),
            $this->get_service('logger'),
            $this->get_service('field_mapping_repository')
        );

        $this->services['invoice_service'] = new InvoiceService(
            $this->get_service('zoho_client'),
            $this->get_service('logger'),
            $this->get_service('item_mapping_repository'),
            $this->get_service('field_mapping_repository')
        );

        $this->services['payment_service'] = new PaymentService(
            $this->get_service('zoho_client'),
            $this->get_service('logger'),
            $this->get_service('payment_method_mapping_repository')
        );

        $this->services['refund_service'] = new RefundService(
            $this->get_service('zoho_client'),
            $this->get_service('logger'),
            $this->get_service('field_mapping_repository')
        );

        $this->services['sync_orchestrator'] = new SyncOrchestrator(
            $this->get_service('customer_service'),
            $this->get_service('invoice_service'),
            $this->get_service('order_meta_repository'),
            $this->get_service('logger'),
            $this->get_service('payment_service'),
            $this->get_service('refund_service')
        );

        $this->services['bulk_sync_service'] = new BulkSyncService(
            $this->get_service('sync_orchestrator'),
            $this->get_service('order_meta_repository'),
            $this->get_service('logger')
        );

        // Admin - create tab pages first (they register AJAX handlers).
        $this->services['product_mapping_page'] = new ProductMappingPage(
            $this->get_service('zoho_client'),
            $this->get_service('item_mapping_repository'),
            $this->get_service('logger')
        );

        $this->services['payment_mapping_page'] = new PaymentMappingPage(
            $this->get_service('zoho_client'),
            $this->get_service('payment_method_mapping_repository'),
            $this->get_service('logger')
        );

        $this->services['field_mapping_page'] = new FieldMappingPage(
            $this->get_service('zoho_client'),
            $this->get_service('field_mapping_repository'),
            $this->get_service('logger')
        );

        // Settings page includes Products, Payments, and Custom Fields tabs.
        $this->services['settings_page'] = new SettingsPage(
            $this->get_service('zoho_client'),
            $this->get_service('token_manager'),
            $this->get_service('product_mapping_page'),
            $this->get_service('payment_mapping_page'),
            $this->get_service('field_mapping_page')
        );

        $this->services['order_meta_box'] = new OrderMetaBox(
            $this->get_service('order_meta_repository')
        );

        $this->services['admin_notices'] = new AdminNotices();

        $this->services['bulk_sync_page'] = new BulkSyncPage(
            $this->get_service('bulk_sync_service')
        );

        $this->services['setup_wizard'] = new SetupWizard(
            $this->get_service('zoho_client'),
            $this->get_service('token_manager'),
            $this->get_service('item_mapping_repository')
        );

        $this->services['log_viewer'] = new LogViewer(
            $this->get_service('logger')
        );

        $this->services['product_meta_box'] = new ProductMetaBox(
            $this->get_service('zoho_client'),
            $this->get_service('item_mapping_repository')
        );

        // Hooks.
        $this->services['order_status_hooks'] = new OrderStatusHooks(
            $this->get_service('sync_orchestrator')
        );

        $this->services['order_bulk_actions'] = new OrderBulkActions(
            $this->get_service('bulk_sync_service')
        );

        $this->services['ajax_handlers'] = new AjaxHandlers(
            $this->get_service('sync_orchestrator')
        );

        // Cron.
        $this->services['retry_failed_syncs'] = new RetryFailedSyncs(
            $this->get_service('zoho_client'),
            $this->get_service('sync_orchestrator'),
            $this->get_service('order_meta_repository'),
            $this->get_service('logger')
        );
    }

    /**
     * Initialize WordPress hooks.
     */
    private function init_hooks(): void {
        // Enqueue admin assets.
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Register settings link on plugins page.
        add_filter(
            'plugin_action_links_' . ZBOOKS_PLUGIN_BASENAME,
            [$this, 'add_settings_link']
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     */
    public function enqueue_admin_assets(string $hook_suffix): void {
        $screen = get_current_screen();

        // Only load on WooCommerce order pages, product pages, and plugin settings.
        $allowed_screens = [
            'woocommerce_page_wc-orders',
            'shop_order',
            'product',
            'edit-shop_order',
            'toplevel_page_zbooks',
            'zbooks_page_zbooks-bulk-sync',
            'zbooks_page_zbooks-logs',
            'zbooks_page_zbooks-mapping',
            'zbooks_page_zbooks-field-mapping',
            'zbooks_page_zbooks-payment-mapping',
        ];

        if (!$screen || !in_array($screen->id, $allowed_screens, true)) {
            return;
        }

        wp_enqueue_style(
            'zbooks-admin',
            ZBOOKS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ZBOOKS_VERSION
        );

        // Load RTL styles if needed.
        if ( is_rtl() ) {
            wp_enqueue_style(
                'zbooks-admin-rtl',
                ZBOOKS_PLUGIN_URL . 'assets/css/admin-rtl.css',
                [ 'zbooks-admin' ],
                ZBOOKS_VERSION
            );
        }

        wp_enqueue_script(
            'zbooks-admin',
            ZBOOKS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            ZBOOKS_VERSION,
            true
        );

        wp_localize_script('zbooks-admin', 'zbooks', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zbooks_ajax_nonce'),
            'i18n' => [
                'syncing' => __('Syncing...', 'zbooks-for-woocommerce'),
                'sync_success' => __('Sync successful!', 'zbooks-for-woocommerce'),
                'sync_error' => __('Sync failed. Please try again.', 'zbooks-for-woocommerce'),
                'confirm_bulk_sync' => __('Are you sure you want to sync the selected orders?', 'zbooks-for-woocommerce'),
            ],
        ]);
    }

    /**
     * Add settings link to plugins page.
     *
     * @param array $links Existing plugin links.
     * @return array Modified plugin links.
     */
    public function add_settings_link(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=zbooks'),
            __('Settings', 'zbooks-for-woocommerce')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Get a service from the container.
     *
     * @param string $name Service name.
     * @return object
     * @throws \InvalidArgumentException If service not found.
     */
    public function get_service(string $name): object {
        if (!isset($this->services[$name])) {
            throw new \InvalidArgumentException(
                sprintf(
                    /* translators: %s: Service name */
                    esc_html__( 'Service "%s" not found in container.', 'zbooks-for-woocommerce' ),
                    esc_html( $name )
                )
            );
        }
        return $this->services[$name];
    }
}
