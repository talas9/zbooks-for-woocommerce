<?php
/**
 * Bulk sync admin page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Service\BulkSyncService;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page for bulk syncing orders.
 */
class BulkSyncPage {

	/**
	 * Bulk sync service.
	 *
	 * @var BulkSyncService
	 */
	private BulkSyncService $bulk_sync_service;

	/**
	 * Constructor.
	 *
	 * @param BulkSyncService $bulk_sync_service Bulk sync service.
	 */
	public function __construct( BulkSyncService $bulk_sync_service ) {
		$this->bulk_sync_service = $bulk_sync_service;
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
	}

	/**
	 * Add submenu page under ZBooks menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'zbooks',
			__( 'Bulk Sync', 'zbooks-for-woocommerce' ),
			__( 'Bulk Sync', 'zbooks-for-woocommerce' ),
			'manage_woocommerce',
			'zbooks-bulk-sync',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the page.
	 */
	public function render_page(): void {
		$stats     = $this->bulk_sync_service->get_statistics();
		$date_to   = gmdate( 'Y-m-d' );
		$date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Sync Orders to Zoho Books', 'zbooks-for-woocommerce' ); ?></h1>

			<div class="zbooks-stats">
				<div class="zbooks-stat-box">
					<span class="zbooks-stat-number"><?php echo esc_html( $stats['total'] ); ?></span>
					<span class="zbooks-stat-label"><?php esc_html_e( 'Total Orders', 'zbooks-for-woocommerce' ); ?></span>
				</div>
				<div class="zbooks-stat-box zbooks-stat-synced">
					<span class="zbooks-stat-number"><?php echo esc_html( $stats['synced'] ); ?></span>
					<span class="zbooks-stat-label"><?php esc_html_e( 'Synced', 'zbooks-for-woocommerce' ); ?></span>
				</div>
				<div class="zbooks-stat-box zbooks-stat-pending">
					<span class="zbooks-stat-number"><?php echo esc_html( $stats['pending'] ); ?></span>
					<span class="zbooks-stat-label"><?php esc_html_e( 'Pending', 'zbooks-for-woocommerce' ); ?></span>
				</div>
				<div class="zbooks-stat-box zbooks-stat-failed">
					<span class="zbooks-stat-number"><?php echo esc_html( $stats['failed'] ); ?></span>
					<span class="zbooks-stat-label"><?php esc_html_e( 'Failed', 'zbooks-for-woocommerce' ); ?></span>
				</div>
			</div>

			<hr>

			<h2><?php esc_html_e( 'Sync by Date Range', 'zbooks-for-woocommerce' ); ?></h2>
			<form id="zbooks-bulk-sync-form" method="post">
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="zbooks_date_from"><?php esc_html_e( 'From Date', 'zbooks-for-woocommerce' ); ?></label>
						</th>
						<td>
							<input type="date" id="zbooks_date_from" name="date_from" class="regular-text"
								value="<?php echo esc_attr( $date_from ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="zbooks_date_to"><?php esc_html_e( 'To Date', 'zbooks-for-woocommerce' ); ?></label>
						</th>
						<td>
							<input type="date" id="zbooks_date_to" name="date_to" class="regular-text"
								value="<?php echo esc_attr( $date_to ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Invoice Type', 'zbooks-for-woocommerce' ); ?>
						</th>
						<td>
							<label>
								<input type="radio" name="as_draft" value="false" checked>
								<?php esc_html_e( 'Submit invoices', 'zbooks-for-woocommerce' ); ?>
							</label>
							<br>
							<label>
								<input type="radio" name="as_draft" value="true">
								<?php esc_html_e( 'Create as draft', 'zbooks-for-woocommerce' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<div class="zbooks-bulk-actions">
					<button type="button" class="button button-primary" id="zbooks-start-bulk-sync">
						<?php esc_html_e( 'Start Bulk Sync', 'zbooks-for-woocommerce' ); ?>
					</button>
				</div>
			</form>

			<div id="zbooks-bulk-sync-progress" style="display: none;">
				<h3><?php esc_html_e( 'Sync Progress', 'zbooks-for-woocommerce' ); ?></h3>
				<div class="zbooks-progress-bar">
					<div class="zbooks-progress-fill" style="width: 0%;"></div>
				</div>
				<p class="zbooks-progress-text"></p>
			</div>

			<hr>

			<h2><?php esc_html_e( 'Pending Orders', 'zbooks-for-woocommerce' ); ?></h2>
			<?php $this->render_orders_table(); ?>
		</div>
		<?php
	}

	/**
	 * Render orders table.
	 */
	private function render_orders_table(): void {
		$orders = $this->bulk_sync_service->get_syncable_orders( null, null, 50 );

		if ( empty( $orders ) ) {
			?>
			<p><?php esc_html_e( 'No pending orders to sync.', 'zbooks-for-woocommerce' ); ?></p>
			<?php
			return;
		}
		?>
		<form id="zbooks-orders-form">
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" id="zbooks-select-all" class="zbooks-select-all">
						</td>
						<th><?php esc_html_e( 'Order', 'zbooks-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Date', 'zbooks-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'zbooks-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Total', 'zbooks-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Order Status', 'zbooks-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Sync Status', 'zbooks-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orders as $order ) : ?>
						<tr>
							<th scope="row" class="check-column">
								<input type="checkbox" name="order_ids[]" class="zbooks-item-checkbox"
									value="<?php echo esc_attr( $order->get_id() ); ?>">
							</th>
							<td>
								<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
									#<?php echo esc_html( $order->get_order_number() ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $order->get_date_created()->format( 'Y-m-d H:i' ) ); ?></td>
							<td><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></td>
							<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
							<td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
							<td><span class="zbooks-status zbooks-status-pending"><?php esc_html_e( 'Pending', 'zbooks-for-woocommerce' ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="zbooks-bulk-actions">
				<span class="zbooks-selected-count">0 item(s) selected</span>
				<button type="button" class="button button-primary zbooks-bulk-sync-btn" id="zbooks-sync-selected" disabled>
					<?php esc_html_e( 'Sync Selected Orders', 'zbooks-for-woocommerce' ); ?>
				</button>
			</div>
		</form>
		<?php
	}
}
