<?php
/**
 * Adds Zoho Status column to WooCommerce orders list.
 *
 * @package Zbooks
 * @author talas9
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Repository\OrderMetaRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Zoho Status column in orders list.
 */
class OrdersListColumn {

	/**
	 * Order meta repository.
	 *
	 * @var OrderMetaRepository
	 */
	private OrderMetaRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param OrderMetaRepository $repository Order meta repository.
	 */
	public function __construct( OrderMetaRepository $repository ) {
		$this->repository = $repository;
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks(): void {
		// Add column to legacy orders screen.
		add_filter( 'manage_shop_order_posts_columns', [ $this, 'add_column' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_column' ], 10, 2 );

		// Add column to HPOS orders screen.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_column' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_hpos_column' ], 10, 2 );

		// Make column sortable (optional - for future enhancement).
		add_filter( 'manage_edit-shop_order_sortable_columns', [ $this, 'make_sortable' ] );
	}

	/**
	 * Add Zoho Status column header.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_column( array $columns ): array {
		// Insert after 'order_status' column.
		$new_columns = [];
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( $key === 'order_status' ) {
				$new_columns['zbooks_status'] = __( 'Zoho Status', 'zbooks-for-woocommerce' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render column content for legacy orders screen.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID (order ID).
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( $column !== 'zbooks_status' ) {
			return;
		}

		$order = wc_get_order( $post_id );
		if ( ! $order ) {
			return;
		}

		$this->render_status_badge( $order );
	}

	/**
	 * Render column content for HPOS orders screen.
	 *
	 * @param string   $column Column name.
	 * @param WC_Order $order  Order object.
	 */
	public function render_hpos_column( string $column, WC_Order $order ): void {
		if ( $column !== 'zbooks_status' ) {
			return;
		}

		$this->render_status_badge( $order );
	}

	/**
	 * Render Zoho status badge.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function render_status_badge( WC_Order $order ): void {
		$status      = $this->get_zoho_status( $order );
		$status_data = $this->get_status_display_data( $status );

		// Add error message to title for tooltip.
		$title = '';
		if ( $status === 'error' ) {
			$error = $this->repository->get_sync_error( $order );
			if ( ! empty( $error ) ) {
				$title = sprintf(
					' title="%s"',
					esc_attr( __( 'Error: ', 'zbooks-for-woocommerce' ) . $error )
				);
			}
		}

		printf(
			'<span class="zbooks-status-badge zbooks-status-%s" data-order-id="%d"%s style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: %s; color: %s; cursor: %s;">%s</span>',
			esc_attr( $status ),
			esc_attr( $order->get_id() ),
			$title, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above.
			esc_attr( $status_data['bg'] ),
			esc_attr( $status_data['color'] ),
			$status === 'error' ? 'help' : 'default',
			esc_html( $status_data['label'] )
		);
	}

	/**
	 * Get Zoho status for an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Status slug.
	 */
	private function get_zoho_status( WC_Order $order ): string {
		// Check for sync error.
		$error = $this->repository->get_sync_error( $order );
		if ( ! empty( $error ) ) {
			return 'error';
		}

		// Get invoice ID.
		$invoice_id = $this->repository->get_invoice_id( $order );
		if ( ! $invoice_id ) {
			return 'unsynced';
		}

		// Check for refund/credit note.
		$refunds = $this->repository->get_refund_ids( $order );
		if ( ! empty( $refunds ) ) {
			return 'refunded';
		}

		// Check for payment.
		$payment_id = $this->repository->get_payment_id( $order );
		if ( $payment_id ) {
			return 'paid';
		}

		// Has invoice but not paid = Draft Invoice (unpaid).
		return 'draft';
	}

	/**
	 * Get display data for status.
	 *
	 * @param string $status Status slug.
	 * @return array Display data with 'label', 'bg', 'color'.
	 */
	private function get_status_display_data( string $status ): array {
		$statuses = [
			'unsynced' => [
				'label' => __( 'Unsynced', 'zbooks-for-woocommerce' ),
				'bg'    => '#f0f0f1',
				'color' => '#646970',
			],
			'draft'    => [
				'label' => __( 'Draft Invoice', 'zbooks-for-woocommerce' ),
				'bg'    => '#dba617',
				'color' => '#ffffff',
			],
			'paid'     => [
				'label' => __( 'Paid Invoice', 'zbooks-for-woocommerce' ),
				'bg'    => '#00a32a',
				'color' => '#ffffff',
			],
			'refunded' => [
				'label' => __( 'Refunded', 'zbooks-for-woocommerce' ),
				'bg'    => '#d63638',
				'color' => '#ffffff',
			],
			'error'    => [
				'label' => __( 'Error', 'zbooks-for-woocommerce' ),
				'bg'    => '#d63638',
				'color' => '#ffffff',
			],
		];

		return $statuses[ $status ] ?? $statuses['unsynced'];
	}

	/**
	 * Make column sortable (optional).
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified columns.
	 */
	public function make_sortable( array $columns ): array {
		// Uncomment to enable sorting by Zoho status.
		// $columns['zbooks_status'] = 'zbooks_status';
		return $columns;
	}
}
