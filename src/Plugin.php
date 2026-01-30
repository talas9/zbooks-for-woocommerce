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
use Zbooks\Admin\OrdersListColumn;
use Zbooks\Admin\AdminNotices;
use Zbooks\Admin\SetupWizard;
use Zbooks\Admin\LogViewer;
use Zbooks\Admin\ProductsTab;
use Zbooks\Admin\ProductMetaBox;
use Zbooks\Admin\CustomFieldsTab;
use Zbooks\Admin\PaymentsTab;
use Zbooks\Admin\ReconciliationPage;
use Zbooks\Admin\ReconciliationTab;
use Zbooks\Admin\ConnectionTab;
use Zbooks\Admin\OrdersTab;
use Zbooks\Admin\NotificationsTab;
use Zbooks\Admin\AdvancedTab;
use Zbooks\Repository\ItemMappingRepository;
use Zbooks\Repository\FieldMappingRepository;
use Zbooks\Repository\PaymentMethodMappingRepository;
use Zbooks\Repository\ReconciliationRepository;
use Zbooks\Api\ZohoClient;
use Zbooks\Api\TokenManager;
use Zbooks\Api\RateLimiter;
use Zbooks\Service\CustomerService;
use Zbooks\Service\InvoiceService;
use Zbooks\Service\PaymentService;
use Zbooks\Service\RefundService;
use Zbooks\Service\SyncOrchestrator;
use Zbooks\Service\BulkSyncService;
use Zbooks\Service\ReconciliationService;
use Zbooks\Hooks\OrderStatusHooks;
use Zbooks\Hooks\OrderBulkActions;
use Zbooks\Hooks\AjaxHandlers;
use Zbooks\Hooks\ReconciliationHooks;
use Zbooks\Repository\OrderMetaRepository;
use Zbooks\Logger\SyncLogger;
use Zbooks\Cron\RetryFailedSyncs;

defined( 'ABSPATH' ) || exit;

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
		if ( null === self::$instance ) {
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
		throw new \Exception( 'Cannot unserialize singleton' );
	}

	/**
	 * Initialize services.
	 */
	private function init_services(): void {
		// Core services.
		$this->services['logger']        = new SyncLogger();
		$this->services['token_manager'] = new TokenManager();
		$this->services['rate_limiter']  = new RateLimiter();

		// API client.
		$this->services['zoho_client'] = new ZohoClient(
			$this->get_service( 'token_manager' ),
			$this->get_service( 'rate_limiter' ),
			$this->get_service( 'logger' )
		);

		// Repositories.
		$this->services['order_meta_repository']             = new OrderMetaRepository();
		$this->services['item_mapping_repository']           = new ItemMappingRepository();
		$this->services['field_mapping_repository']          = new FieldMappingRepository();
		$this->services['payment_method_mapping_repository'] = new PaymentMethodMappingRepository();
		$this->services['reconciliation_repository']         = new ReconciliationRepository();

		// Business services.
		$this->services['customer_service'] = new CustomerService(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'logger' ),
			$this->get_service( 'field_mapping_repository' )
		);

		$this->services['invoice_service'] = new InvoiceService(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'logger' ),
			$this->get_service( 'item_mapping_repository' ),
			$this->get_service( 'field_mapping_repository' )
		);

		$this->services['payment_service'] = new PaymentService(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'logger' ),
			$this->get_service( 'payment_method_mapping_repository' )
		);

		$this->services['refund_service'] = new RefundService(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'logger' ),
			$this->get_service( 'field_mapping_repository' ),
			$this->get_service( 'order_meta_repository' )
		);

		$this->services['sync_orchestrator'] = new SyncOrchestrator(
			$this->get_service( 'customer_service' ),
			$this->get_service( 'invoice_service' ),
			$this->get_service( 'order_meta_repository' ),
			$this->get_service( 'logger' ),
			$this->get_service( 'payment_service' ),
			$this->get_service( 'refund_service' ),
			null,
			$this->get_service( 'item_mapping_repository' )
		);

		$this->services['bulk_sync_service'] = new BulkSyncService(
			$this->get_service( 'sync_orchestrator' ),
			$this->get_service( 'order_meta_repository' ),
			$this->get_service( 'logger' )
		);

		$this->services['reconciliation_service'] = new ReconciliationService(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'logger' ),
			$this->get_service( 'reconciliation_repository' )
		);

		// Admin - create tab classes first (they register AJAX handlers).
		$this->services['products_tab'] = new ProductsTab(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'item_mapping_repository' ),
			$this->get_service( 'logger' )
		);

		$this->services['payments_tab'] = new PaymentsTab(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'payment_method_mapping_repository' ),
			$this->get_service( 'logger' )
		);

		$this->services['custom_fields_tab'] = new CustomFieldsTab(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'field_mapping_repository' ),
			$this->get_service( 'logger' )
		);

		$this->services['reconciliation_page'] = new ReconciliationPage(
			$this->get_service( 'reconciliation_service' ),
			$this->get_service( 'reconciliation_repository' )
		);

		$this->services['reconciliation_tab'] = new ReconciliationTab(
			$this->get_service( 'reconciliation_service' )
		);

		$this->services['connection_tab'] = new ConnectionTab(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'token_manager' )
		);

		$this->services['orders_tab'] = new OrdersTab(
			$this->get_service( 'zoho_client' )
		);

		$this->services['notifications_tab'] = new NotificationsTab();

		$this->services['advanced_tab'] = new AdvancedTab();

		// Settings page includes all tabs.
		$this->services['settings_page'] = new SettingsPage(
			$this->get_service( 'connection_tab' ),
			$this->get_service( 'orders_tab' ),
			$this->get_service( 'products_tab' ),
			$this->get_service( 'payments_tab' ),
			$this->get_service( 'custom_fields_tab' ),
			$this->get_service( 'reconciliation_tab' ),
			$this->get_service( 'notifications_tab' ),
			$this->get_service( 'advanced_tab' )
		);

		$this->services['order_meta_box'] = new OrderMetaBox(
			$this->get_service( 'order_meta_repository' ),
			$this->get_service( 'zoho_client' )
		);

		$this->services['orders_list_column'] = new OrdersListColumn(
			$this->get_service( 'order_meta_repository' )
		);

		$this->services['admin_notices'] = new AdminNotices();

		$this->services['setup_wizard'] = new SetupWizard(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'token_manager' ),
			$this->get_service( 'item_mapping_repository' )
		);

		$this->services['log_viewer'] = new LogViewer(
			$this->get_service( 'logger' )
		);

		$this->services['product_meta_box'] = new ProductMetaBox(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'item_mapping_repository' )
		);

		// Hooks.
		$this->services['order_status_hooks'] = new OrderStatusHooks(
			$this->get_service( 'sync_orchestrator' )
		);

		$this->services['order_bulk_actions'] = new OrderBulkActions(
			$this->get_service( 'bulk_sync_service' )
		);

		$this->services['ajax_handlers'] = new AjaxHandlers(
			$this->get_service( 'sync_orchestrator' )
		);

		$this->services['reconciliation_hooks'] = new ReconciliationHooks(
			$this->get_service( 'reconciliation_service' ),
			$this->get_service( 'reconciliation_repository' )
		);

		// Cron.
		$this->services['retry_failed_syncs'] = new RetryFailedSyncs(
			$this->get_service( 'zoho_client' ),
			$this->get_service( 'sync_orchestrator' ),
			$this->get_service( 'order_meta_repository' ),
			$this->get_service( 'logger' )
		);
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks(): void {
		// Enqueue admin assets.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Register settings link on plugins page.
		add_filter(
			'plugin_action_links_' . ZBOOKS_PLUGIN_BASENAME,
			[ $this, 'add_settings_link' ]
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		$screen = get_current_screen();

		// Only load on WooCommerce order pages, product pages, and plugin settings.
		// Check both screen ID (for compatibility) and hook_suffix (more reliable).
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
			'zbooks_page_zbooks-reconciliation',
		];

		$screen_match = $screen && in_array( $screen->id, $allowed_screens, true );
		$hook_match   = in_array( $hook_suffix, $allowed_screens, true );

		// Also check for zbooks prefix in hook_suffix (catches all ZBooks pages).
		$zbooks_page = strpos( $hook_suffix, 'zbooks' ) !== false;

		if ( ! $screen_match && ! $hook_match && ! $zbooks_page ) {
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

		// Enqueue main admin.js (contains shared utilities and lazy-loading system).
		wp_enqueue_script(
			'zbooks-admin',
			ZBOOKS_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			ZBOOKS_VERSION,
			true
		);

		// Localize admin script with global config and data for lazy loading.
		wp_localize_script(
			'zbooks-admin',
			'zbooks',
			[
				'ajax_url'             => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'zbooks_ajax_nonce' ),
				'reconciliation_nonce' => wp_create_nonce( 'zbooks_reconciliation' ),
				'i18n'                 => [
					'syncing'                  => __( 'Syncing...', 'zbooks-for-woocommerce' ),
					'sync_success'             => __( 'Sync successful!', 'zbooks-for-woocommerce' ),
					'sync_error'               => __( 'Sync failed. Please try again.', 'zbooks-for-woocommerce' ),
					'confirm_bulk_sync'        => __( 'Are you sure you want to sync the selected orders?', 'zbooks-for-woocommerce' ),
					'bulk_sync_leave_warning'  => __( 'Bulk sync is in progress. Leaving this page will cancel the sync. Are you sure you want to leave?', 'zbooks-for-woocommerce' ),
					'session_expired'          => __( 'Session expired. Please refresh the page and try again.', 'zbooks-for-woocommerce' ),
					'permission_denied'        => __( 'Permission denied. You may not have access to this feature.', 'zbooks-for-woocommerce' ),
					'server_error'             => __( 'Server error. Please check your server logs or try again later.', 'zbooks-for-woocommerce' ),
					'network_error'            => __( 'Network error. Please check your internet connection.', 'zbooks-for-woocommerce' ),
					'reconciliation_failed'    => __( 'Reconciliation failed.', 'zbooks-for-woocommerce' ),
					'failed_to_delete_report'  => __( 'Failed to delete report.', 'zbooks-for-woocommerce' ),
					'failed_to_load_report'    => __( 'Failed to load report.', 'zbooks-for-woocommerce' ),
					'confirm_unlink_product'   => __( 'Unlink this product from Zoho?', 'zbooks-for-woocommerce' ),
					'confirm_auto_map'         => __( 'Automatically map products to Zoho items by matching SKU?', 'zbooks-for-woocommerce' ),
					'mapping_leave_warning'    => __( 'Mapping is in progress. Are you sure you want to leave? Unmapped products will not be processed.', 'zbooks-for-woocommerce' ),
					'select_zoho_item'         => __( 'Please select a Zoho item to link.', 'zbooks-for-woocommerce' ),
					'creating'                 => __( 'Creating...', 'zbooks-for-woocommerce' ),
					'created'                  => __( 'Created!', 'zbooks-for-woocommerce' ),
					'create'                   => __( 'Create', 'zbooks-for-woocommerce' ),
					'linking'                  => __( 'Linking...', 'zbooks-for-woocommerce' ),
					'linked'                   => __( 'Linked', 'zbooks-for-woocommerce' ),
					'link'                     => __( 'Link', 'zbooks-for-woocommerce' ),
					'unlinking'                => __( 'Unlinking...', 'zbooks-for-woocommerce' ),
					'unlink'                   => __( 'Unlink', 'zbooks-for-woocommerce' ),
					'mapped'                   => __( 'Mapped', 'zbooks-for-woocommerce' ),
					'selected'                 => __( 'selected', 'zbooks-for-woocommerce' ),
					'items_in_zoho_books'      => __( 'items in Zoho Books?', 'zbooks-for-woocommerce' ),
					'creating_items_in_zoho'   => __( 'Creating items in Zoho...', 'zbooks-for-woocommerce' ),
					'create_selected_in_zoho'  => __( 'Create Selected in Zoho', 'zbooks-for-woocommerce' ),
					'refreshing'               => __( 'Refreshing...', 'zbooks-for-woocommerce' ),
					'fetching_zoho_items'      => __( 'Fetching Zoho items...', 'zbooks-for-woocommerce' ),
					'refresh_zoho_items'       => __( 'Refresh Zoho Items', 'zbooks-for-woocommerce' ),
					'items_refreshed'          => __( 'Items refreshed!', 'zbooks-for-woocommerce' ),
					'refresh_failed'           => __( 'Refresh failed', 'zbooks-for-woocommerce' ),
					'no_unmapped_products'     => __( 'No unmapped products found.', 'zbooks-for-woocommerce' ),
					'mapping'                  => __( 'Mapping...', 'zbooks-for-woocommerce' ),
					'auto_map_by_sku'          => __( 'Auto-Map by SKU', 'zbooks-for-woocommerce' ),
					'mapping_complete'         => __( 'Mapping complete!', 'zbooks-for-woocommerce' ),
					'failed'                   => __( 'Failed', 'zbooks-for-woocommerce' ),
					'mapping_product'          => __( 'Mapping product', 'zbooks-for-woocommerce' ),
					'of'                       => __( 'of', 'zbooks-for-woocommerce' ),
				],
			]
		);

		// Localize data for lazy loading system.
		wp_localize_script(
			'zbooks-admin',
			'zbooksData',
			[
				'pluginUrl' => ZBOOKS_PLUGIN_URL,
				'version'   => ZBOOKS_VERSION,
			]
		);

		// Conditionally load modules based on current page (for backward compatibility during transition).
		// These will be lazy-loaded by admin.js in the future, but we keep them for now.
		// Product mapping module (for products tab and product edit page).
		// Product edit pages can have screen IDs: 'product', 'edit-product', or post type 'product'.
		$is_product_page = $screen && (
			$screen->id === 'toplevel_page_zbooks' ||
			$screen->id === 'zbooks_page_zbooks-mapping' ||
			$screen->id === 'product' ||
			$screen->post_type === 'product'
		);
		
		if ( $is_product_page ) {
			wp_enqueue_script(
				'zbooks-product-mapping',
				ZBOOKS_PLUGIN_URL . 'assets/js/modules/product-mapping.js',
				[ 'jquery', 'zbooks-admin', 'select2' ],
				ZBOOKS_VERSION,
				true
			);
		}

		// Order sync module (for orders tab and order pages).
		// Handle both HPOS and traditional order screens.
		$is_order_page = $screen && (
			in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'shop_order', 'edit-shop_order', 'zbooks_page_zbooks-bulk-sync' ], true ) ||
			$screen->post_type === 'shop_order' ||
			( isset( $screen->base ) && $screen->base === 'woocommerce_page_wc-orders' )
		);
		
		if ( $is_order_page ) {
			wp_enqueue_script(
				'zbooks-order-sync',
				ZBOOKS_PLUGIN_URL . 'assets/js/modules/order-sync.js',
				[ 'jquery', 'zbooks-admin' ],
				ZBOOKS_VERSION,
				true
			);
		}

		// Connection tab module (for connection tab).
		if ( $screen && $screen->id === 'toplevel_page_zbooks' ) {
			wp_enqueue_script(
				'zbooks-connection-tab',
				ZBOOKS_PLUGIN_URL . 'assets/js/modules/connection-tab.js',
				[ 'jquery', 'zbooks-admin' ],
				ZBOOKS_VERSION,
				true
			);
		}

		// Custom fields module (for custom fields tab).
		if ( $screen && $screen->id === 'toplevel_page_zbooks' ) {
			wp_enqueue_script(
				'zbooks-custom-fields',
				ZBOOKS_PLUGIN_URL . 'assets/js/modules/custom-fields.js',
				[ 'jquery', 'zbooks-admin' ],
				ZBOOKS_VERSION,
				true
			);
		}

		// Payments module (for payments tab).
		if ( $screen && $screen->id === 'toplevel_page_zbooks' ) {
			wp_enqueue_script(
				'zbooks-payments',
				ZBOOKS_PLUGIN_URL . 'assets/js/modules/payments.js',
				[ 'jquery', 'zbooks-admin' ],
				ZBOOKS_VERSION,
				true
			);
		}

		// Log viewer module (for log viewer page).
		if ( $screen && $screen->id === 'zbooks_page_zbooks-logs' ) {
			wp_enqueue_script(
				'zbooks-log-viewer',
				ZBOOKS_PLUGIN_URL . 'assets/js/modules/log-viewer.js',
				[ 'jquery', 'zbooks-admin' ],
				ZBOOKS_VERSION,
				true
			);
		}

		// Reconciliation module (for reconciliation page).
		if ( $screen && $screen->id === 'zbooks_page_zbooks-reconciliation' ) {
			wp_enqueue_script(
				'zbooks-reconciliation',
				ZBOOKS_PLUGIN_URL . 'assets/js/modules/reconciliation.js',
				[ 'jquery', 'zbooks-admin' ],
				ZBOOKS_VERSION,
				true
			);
		}

		// Localize product metabox specific data.
		wp_localize_script(
			'zbooks-admin',
			'zbooks_product',
			[
				'nonce' => wp_create_nonce( 'zbooks_product_ajax' ),
				'i18n'  => [
					'creating'                => __( 'Creating...', 'zbooks-for-woocommerce' ),
					'create_in_zoho'          => __( 'Create in Zoho', 'zbooks-for-woocommerce' ),
					'searching'               => __( 'Searching...', 'zbooks-for-woocommerce' ),
					'search_failed'           => __( 'Search failed. Please try again.', 'zbooks-for-woocommerce' ),
					'no_items_found'          => __( 'No items found.', 'zbooks-for-woocommerce' ),
					'select_item_to_link'     => __( 'Select Item to Link', 'zbooks-for-woocommerce' ),
					'link_selected'           => __( 'Link Selected', 'zbooks-for-woocommerce' ),
					'cancel'                  => __( 'Cancel', 'zbooks-for-woocommerce' ),
					'linking'                 => __( 'Linking...', 'zbooks-for-woocommerce' ),
					'item_linked'             => __( 'Item linked successfully!', 'zbooks-for-woocommerce' ),
					'link_failed'             => __( 'Failed to link item.', 'zbooks-for-woocommerce' ),
					'inventory_tracking_error' => __( 'Inventory Tracking Error', 'zbooks-for-woocommerce' ),
					'inventory_feature_note'  => __( 'This feature requires Zoho Inventory integration with your Zoho Books subscription.', 'zbooks-for-woocommerce' ),
					'create_without_inventory' => __( 'Create without inventory tracking', 'zbooks-for-woocommerce' ),
					'item_already_exists'     => __( 'Item Already Exists', 'zbooks-for-woocommerce' ),
					'search_and_link_prompt'  => __( 'Would you like to search for the existing item and link it to this product?', 'zbooks-for-woocommerce' ),
					'search_link_existing'    => __( 'Search & Link Existing', 'zbooks-for-woocommerce' ),
				],
			]
		);

		// Localize product mapping specific data.
		wp_localize_script(
			'zbooks-admin',
			'zbooks_mapping',
			[
				'nonce' => wp_create_nonce( 'zbooks_mapping' ),
			]
		);

		// Localize bank account refresh specific data.
		wp_localize_script(
			'zbooks-admin',
			'zbooks_refresh_accounts',
			[
				'nonce' => wp_create_nonce( 'zbooks_refresh_accounts' ),
			]
		);
	}

	/**
	 * Add settings link to plugins page.
	 *
	 * @param array $links Existing plugin links.
	 * @return array Modified plugin links.
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=zbooks' ),
			__( 'Settings', 'zbooks-for-woocommerce' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Get a service from the container.
	 *
	 * @param string $name Service name.
	 * @return object
	 * @throws \InvalidArgumentException If service not found.
	 */
	public function get_service( string $name ): object {
		if ( ! isset( $this->services[ $name ] ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s: Service name */
					esc_html__( 'Service "%s" not found in container.', 'zbooks-for-woocommerce' ),
					esc_html( $name )
				)
			);
		}
		return $this->services[ $name ];
	}
}
