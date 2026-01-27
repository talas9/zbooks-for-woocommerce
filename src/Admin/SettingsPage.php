<?php
/**
 * Settings page coordinator.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page coordinator - delegates to individual tab classes.
 */
class SettingsPage {

	/**
	 * Connection tab instance.
	 *
	 * @var ConnectionTab
	 */
	private ConnectionTab $connection_tab;

	/**
	 * Orders tab instance.
	 *
	 * @var OrdersTab
	 */
	private OrdersTab $orders_tab;

	/**
	 * Products tab instance.
	 *
	 * @var ProductsTab
	 */
	private ProductsTab $products_tab;

	/**
	 * Payments tab instance.
	 *
	 * @var PaymentsTab
	 */
	private PaymentsTab $payments_tab;

	/**
	 * Custom fields tab instance.
	 *
	 * @var CustomFieldsTab
	 */
	private CustomFieldsTab $custom_fields_tab;

	/**
	 * Reconciliation tab instance.
	 *
	 * @var ReconciliationTab
	 */
	private ReconciliationTab $reconciliation_tab;

	/**
	 * Notifications tab instance.
	 *
	 * @var NotificationsTab
	 */
	private NotificationsTab $notifications_tab;

	/**
	 * Advanced tab instance.
	 *
	 * @var AdvancedTab
	 */
	private AdvancedTab $advanced_tab;

	/**
	 * Available tabs (lazy-loaded).
	 *
	 * @var array
	 */
	private array $tabs = [];

	/**
	 * Constructor.
	 *
	 * @param ConnectionTab     $connection_tab     Connection tab.
	 * @param OrdersTab         $orders_tab         Orders tab.
	 * @param ProductsTab       $products_tab       Products tab.
	 * @param PaymentsTab       $payments_tab       Payments tab.
	 * @param CustomFieldsTab   $custom_fields_tab  Custom fields tab.
	 * @param ReconciliationTab $reconciliation_tab Reconciliation tab.
	 * @param NotificationsTab  $notifications_tab  Notifications tab.
	 * @param AdvancedTab       $advanced_tab       Advanced tab.
	 */
	public function __construct(
		ConnectionTab $connection_tab,
		OrdersTab $orders_tab,
		ProductsTab $products_tab,
		PaymentsTab $payments_tab,
		CustomFieldsTab $custom_fields_tab,
		ReconciliationTab $reconciliation_tab,
		NotificationsTab $notifications_tab,
		AdvancedTab $advanced_tab
	) {
		$this->connection_tab     = $connection_tab;
		$this->orders_tab         = $orders_tab;
		$this->products_tab       = $products_tab;
		$this->payments_tab       = $payments_tab;
		$this->custom_fields_tab  = $custom_fields_tab;
		$this->reconciliation_tab = $reconciliation_tab;
		$this->notifications_tab  = $notifications_tab;
		$this->advanced_tab       = $advanced_tab;

		$this->register_hooks();
	}

	/**
	 * Get tabs configuration (lazy-loaded to avoid early translation).
	 *
	 * @return array
	 */
	private function get_tabs(): array {
		if ( empty( $this->tabs ) ) {
			$this->tabs = [
				'connection'     => __( 'Connection', 'zbooks-for-woocommerce' ),
				'orders'         => __( 'Orders', 'zbooks-for-woocommerce' ),
				'products'       => __( 'Products', 'zbooks-for-woocommerce' ),
				'payments'       => __( 'Payments', 'zbooks-for-woocommerce' ),
				'custom_fields'  => __( 'Custom Fields', 'zbooks-for-woocommerce' ),
				'reconciliation' => __( 'Reconciliation', 'zbooks-for-woocommerce' ),
				'notifications'  => __( 'Notifications', 'zbooks-for-woocommerce' ),
				'advanced'       => __( 'Advanced', 'zbooks-for-woocommerce' ),
			];
		}
		return $this->tabs;
	}

	/**
	 * Get current tab.
	 *
	 * @return string
	 */
	private function get_current_tab(): string {
		$tabs = $this->get_tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab selection doesn't require nonce.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection';
		return isset( $tabs[ $tab ] ) ? $tab : 'connection';
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_zbooks_test_connection', [ $this, 'ajax_test_connection' ] );
	}

	/**
	 * Register settings - delegates to tabs.
	 */
	public function register_settings(): void {
		$this->connection_tab->register_settings();
		$this->orders_tab->register_settings();
		$this->notifications_tab->register_settings();
		$this->advanced_tab->register_settings();
	}

	/**
	 * Add admin menu.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'ZBooks Settings', 'zbooks-for-woocommerce' ),
			__( 'ZBooks', 'zbooks-for-woocommerce' ),
			'manage_woocommerce',
			'zbooks',
			[ $this, 'render_page' ],
			'dashicons-book-alt',
			56
		);

		add_submenu_page(
			'zbooks',
			__( 'Settings', 'zbooks-for-woocommerce' ),
			__( 'Settings', 'zbooks-for-woocommerce' ),
			'manage_woocommerce',
			'zbooks',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_page(): void {
		$current_tab = $this->get_current_tab();
		?>
		<div class="wrap zbooks-settings">
			<h1><?php esc_html_e( 'ZBooks for WooCommerce', 'zbooks-for-woocommerce' ); ?></h1>

			<nav class="nav-tab-wrapper zbooks-tabs">
				<?php foreach ( $this->get_tabs() as $tab_id => $tab_label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=zbooks&tab=' . $tab_id ) ); ?>"
						class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="zbooks-tab-content">
				<?php $this->render_tab_content( $current_tab ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the content for the current tab.
	 *
	 * @param string $tab The current tab ID.
	 */
	private function render_tab_content( string $tab ): void {
		switch ( $tab ) {
			case 'connection':
				$this->connection_tab->render_content();
				break;
			case 'orders':
				$this->orders_tab->render_content();
				break;
			case 'products':
				$this->products_tab->render_content();
				break;
			case 'payments':
				$this->payments_tab->render_content();
				break;
			case 'custom_fields':
				$this->custom_fields_tab->render_content();
				break;
			case 'reconciliation':
				$this->reconciliation_tab->render_content();
				break;
			case 'notifications':
				$this->notifications_tab->render_content();
				break;
			case 'advanced':
				$this->advanced_tab->render_content();
				break;
			default:
				$this->connection_tab->render_content();
				break;
		}
	}

	/**
	 * AJAX: Test Zoho connection.
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'zbooks_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		// Get the connection tab's client through a test.
		try {
			// We need access to ZohoClient. For now, use a simple option check.
			$credentials = get_option( 'zbooks_oauth_credentials', [] );

			if ( empty( $credentials['client_id'] ) || empty( $credentials['refresh_token'] ) ) {
				wp_send_json_error(
					[
						'message' => __( 'API credentials not configured. Please enter your Client ID and Refresh Token.', 'zbooks-for-woocommerce' ),
					]
				);
			}

			$org_id = get_option( 'zbooks_organization_id', '' );
			if ( empty( $org_id ) ) {
				wp_send_json_error(
					[
						'message' => __( 'Organization not selected. Please save credentials and select an organization.', 'zbooks-for-woocommerce' ),
					]
				);
			}

			wp_send_json_success(
				[
					'message' => __( 'Connection configured successfully!', 'zbooks-for-woocommerce' ),
				]
			);
		} catch ( \Exception $e ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: Error message */
						__( 'Connection test failed: %s', 'zbooks-for-woocommerce' ),
						$e->getMessage()
					),
				]
			);
		}
	}
}
