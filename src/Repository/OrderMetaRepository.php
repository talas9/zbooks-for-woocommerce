<?php
/**
 * Order meta repository.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Repository;

use WC_Order;
use Zbooks\Model\SyncStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for managing WooCommerce order meta related to Zoho sync.
 */
class OrderMetaRepository {

	/**
	 * Meta key for Zoho invoice ID.
	 */
	public const META_INVOICE_ID = '_zbooks_zoho_invoice_id';

	/**
	 * Meta key for Zoho contact ID.
	 */
	public const META_CONTACT_ID = '_zbooks_zoho_contact_id';

	/**
	 * Meta key for sync status.
	 */
	public const META_SYNC_STATUS = '_zbooks_sync_status';

	/**
	 * Meta key for last sync attempt.
	 */
	public const META_LAST_SYNC_ATTEMPT = '_zbooks_last_sync_attempt';

	/**
	 * Meta key for sync error.
	 */
	public const META_SYNC_ERROR = '_zbooks_sync_error';

	/**
	 * Meta key for retry count.
	 */
	public const META_RETRY_COUNT = '_zbooks_retry_count';

	/**
	 * Meta key for Zoho payment ID.
	 */
	public const META_PAYMENT_ID = '_zbooks_zoho_payment_id';

	/**
	 * Meta key for Zoho refund IDs (array).
	 */
	public const META_REFUND_IDS = '_zbooks_zoho_refund_ids';

	/**
	 * Meta key for Zoho credit note IDs (array).
	 */
	public const META_CREDIT_NOTE_IDS = '_zbooks_zoho_credit_note_ids';

	/**
	 * Meta key for Zoho invoice number (human-readable).
	 */
	public const META_INVOICE_NUMBER = '_zbooks_zoho_invoice_number';

	/**
	 * Meta key for Zoho payment number (human-readable).
	 */
	public const META_PAYMENT_NUMBER = '_zbooks_zoho_payment_number';

	/**
	 * Meta key for Zoho contact display name.
	 */
	public const META_CONTACT_NAME = '_zbooks_zoho_contact_name';

	/**
	 * Meta key for Zoho invoice status (actual Zoho status like draft/sent/paid).
	 */
	public const META_INVOICE_STATUS = '_zbooks_zoho_invoice_status';

	/**
	 * Meta key for payment error.
	 */
	public const META_PAYMENT_ERROR = '_zbooks_payment_error';

	/**
	 * Meta key for unapplied credit note.
	 */
	public const META_UNAPPLIED_CREDIT = '_zbooks_unapplied_credit_note';

	/**
	 * Get Zoho invoice ID for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null
	 */
	public function get_invoice_id( WC_Order $order ): ?string {
		$value = $order->get_meta( self::META_INVOICE_ID );
		return $value !== '' ? $value : null;
	}

	/**
	 * Set Zoho invoice ID for an order.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $invoice_id Zoho invoice ID.
	 */
	public function set_invoice_id( WC_Order $order, string $invoice_id ): void {
		$order->update_meta_data( self::META_INVOICE_ID, $invoice_id );
		$order->save();
	}

	/**
	 * Get Zoho contact ID for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null
	 */
	public function get_contact_id( WC_Order $order ): ?string {
		$value = $order->get_meta( self::META_CONTACT_ID );
		return $value !== '' ? $value : null;
	}

	/**
	 * Set Zoho contact ID for an order.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $contact_id Zoho contact ID.
	 */
	public function set_contact_id( WC_Order $order, string $contact_id ): void {
		$order->update_meta_data( self::META_CONTACT_ID, $contact_id );
		$order->save();
	}

	/**
	 * Clear Zoho contact ID for an order.
	 *
	 * Used when a contact is found to be deleted in Zoho and needs to be recreated.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public function clear_contact_id( WC_Order $order ): void {
		$order->delete_meta_data( self::META_CONTACT_ID );
		$order->delete_meta_data( self::META_CONTACT_NAME );
		$order->save();
	}

	/**
	 * Clear stale invoice ID from order meta.
	 *
	 * Used when an invoice is found to be deleted in Zoho and needs to be recreated.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public function clear_invoice_id( WC_Order $order ): void {
		$order->delete_meta_data( self::META_INVOICE_ID );
		$order->delete_meta_data( self::META_INVOICE_NUMBER );
		$order->delete_meta_data( self::META_INVOICE_STATUS );
		$order->save();
	}

	/**
	 * Get sync status for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return SyncStatus|null
	 */
	public function get_sync_status( WC_Order $order ): ?SyncStatus {
		$value = $order->get_meta( self::META_SYNC_STATUS );

		if ( $value === '' ) {
			return null;
		}

		return SyncStatus::tryFrom( $value );
	}

	/**
	 * Set sync status for an order.
	 *
	 * @param WC_Order   $order  WooCommerce order.
	 * @param SyncStatus $status Sync status.
	 */
	public function set_sync_status( WC_Order $order, SyncStatus $status ): void {
		$order->update_meta_data( self::META_SYNC_STATUS, $status->value );
		$order->save();
	}

	/**
	 * Get last sync attempt time.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return \DateTimeImmutable|null
	 */
	public function get_last_sync_attempt( WC_Order $order ): ?\DateTimeImmutable {
		$value = $order->get_meta( self::META_LAST_SYNC_ATTEMPT );

		if ( $value === '' ) {
			return null;
		}

		return new \DateTimeImmutable( $value );
	}

	/**
	 * Set last sync attempt time.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public function set_last_sync_attempt( WC_Order $order ): void {
		$order->update_meta_data(
			self::META_LAST_SYNC_ATTEMPT,
			gmdate( 'Y-m-d H:i:s' )
		);
		$order->save();
	}

	/**
	 * Get sync error message.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null
	 */
	public function get_sync_error( WC_Order $order ): ?string {
		$value = $order->get_meta( self::META_SYNC_ERROR );
		return $value !== '' ? $value : null;
	}

	/**
	 * Set sync error message.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $error Error message.
	 */
	public function set_sync_error( WC_Order $order, string $error ): void {
		$order->update_meta_data( self::META_SYNC_ERROR, $error );
		$order->save();
	}

	/**
	 * Clear sync error.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public function clear_sync_error( WC_Order $order ): void {
		$order->delete_meta_data( self::META_SYNC_ERROR );
		$order->save();
	}

	/**
	 * Get retry count.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int
	 */
	public function get_retry_count( WC_Order $order ): int {
		$value = $order->get_meta( self::META_RETRY_COUNT );
		return $value !== '' ? (int) $value : 0;
	}

	/**
	 * Increment retry count.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int New retry count.
	 */
	public function increment_retry_count( WC_Order $order ): int {
		$count = $this->get_retry_count( $order ) + 1;
		$order->update_meta_data( self::META_RETRY_COUNT, $count );
		$order->save();
		return $count;
	}

	/**
	 * Reset retry count.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public function reset_retry_count( WC_Order $order ): void {
		$order->delete_meta_data( self::META_RETRY_COUNT );
		$order->save();
	}

	/**
	 * Check if order has been synced.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	public function is_synced( WC_Order $order ): bool {
		$status = $this->get_sync_status( $order );
		return $status === SyncStatus::SYNCED || $status === SyncStatus::DRAFT;
	}

	/**
	 * Get orders with failed sync status.
	 *
	 * @param int $limit Maximum number of orders to return.
	 * @return WC_Order[]
	 */
	public function get_failed_orders( int $limit = 10 ): array {
		$query = new \WC_Order_Query(
			[
				'limit'      => $limit,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => [
					[
						'key'     => self::META_SYNC_STATUS,
						'value'   => SyncStatus::FAILED->value,
						'compare' => '=',
					],
				],
			]
		);

		return $query->get_orders();
	}

	/**
	 * Get orders for bulk sync (all orders, not just unsynced).
	 *
	 * @param string|null $date_from    Start date (Y-m-d).
	 * @param string|null $date_to      End date (Y-m-d).
	 * @param int         $limit        Maximum number of orders.
	 * @param array       $order_status Order statuses to filter by.
	 * @return WC_Order[]
	 */
	public function get_orders_for_bulk_sync(
		?string $date_from = null,
		?string $date_to = null,
		int $limit = 100,
		array $order_status = [ 'all' ]
	): array {
		$args = [
			'limit'   => $limit,
			'type'    => 'shop_order', // Only regular orders, not refunds.
			'orderby' => 'date',
			'order'   => 'DESC', // Most recent first.
		];

		if ( $date_from ) {
			$args['date_created'] = '>=' . $date_from;
		}

		if ( $date_to ) {
			$args['date_created'] = '<=' . $date_to . ' 23:59:59';
		}

		// Filter by order status if not "all".
		if ( ! in_array( 'all', $order_status, true ) && ! empty( $order_status ) ) {
			$args['status'] = $order_status;
		}

		$query = new \WC_Order_Query( $args );
		return $query->get_orders();
	}

	/**
	 * Get orders pending sync in date range.
	 *
	 * @param string|null $date_from Start date (Y-m-d).
	 * @param string|null $date_to   End date (Y-m-d).
	 * @param int         $limit     Maximum number of orders.
	 * @return WC_Order[]
	 */
	public function get_unsynced_orders(
		?string $date_from = null,
		?string $date_to = null,
		int $limit = 100
	): array {
		$args = [
			'limit'      => $limit,
			'type'       => 'shop_order', // Only regular orders, not refunds.
			'orderby'    => 'date',
			'order'      => 'ASC',
			'meta_query' => [
				'relation' => 'OR',
				[
					'key'     => self::META_SYNC_STATUS,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => self::META_SYNC_STATUS,
					'value'   => SyncStatus::PENDING->value,
					'compare' => '=',
				],
			],
		];

		// Build date_created query parameter.
		// WooCommerce WC_Order_Query expects:
		// - ">=YYYY-MM-DD" for on-or-after
		// - "<=YYYY-MM-DD" for on-or-before
		// - "YYYY-MM-DD...YYYY-MM-DD" for date range (no prefix!)
		if ( $date_from && $date_to ) {
			// Date range: use the range syntax without any prefix.
			// Bug fix: Previously used ">=$date_from...$date_to" which is malformed.
			$args['date_created'] = $date_from . '...' . $date_to;
		} elseif ( $date_from ) {
			$args['date_created'] = '>=' . $date_from;
		} elseif ( $date_to ) {
			$args['date_created'] = '<=' . $date_to;
		}

		$query = new \WC_Order_Query( $args );
		return $query->get_orders();
	}

	/**
	 * Update all sync meta at once.
	 *
	 * @param WC_Order    $order          WooCommerce order.
	 * @param SyncStatus  $status         Sync status.
	 * @param string|null $invoice_id     Zoho invoice ID.
	 * @param string|null $contact_id     Zoho contact ID.
	 * @param string|null $error          Error message.
	 * @param string|null $invoice_number Zoho invoice number (human-readable).
	 * @param string|null $contact_name   Zoho contact display name.
	 */
	public function update_sync_meta(
		WC_Order $order,
		SyncStatus $status,
		?string $invoice_id = null,
		?string $contact_id = null,
		?string $error = null,
		?string $invoice_number = null,
		?string $contact_name = null
	): void {
		$order->update_meta_data( self::META_SYNC_STATUS, $status->value );
		$order->update_meta_data( self::META_LAST_SYNC_ATTEMPT, gmdate( 'Y-m-d H:i:s' ) );

		if ( $invoice_id !== null ) {
			$order->update_meta_data( self::META_INVOICE_ID, $invoice_id );
		}

		if ( $contact_id !== null ) {
			$order->update_meta_data( self::META_CONTACT_ID, $contact_id );
		}

		if ( $invoice_number !== null ) {
			$order->update_meta_data( self::META_INVOICE_NUMBER, $invoice_number );
		}

		if ( $contact_name !== null ) {
			$order->update_meta_data( self::META_CONTACT_NAME, $contact_name );
		}

		if ( $error !== null ) {
			$order->update_meta_data( self::META_SYNC_ERROR, $error );
		} else {
			$order->delete_meta_data( self::META_SYNC_ERROR );
		}

		if ( $status !== SyncStatus::FAILED ) {
			$order->delete_meta_data( self::META_RETRY_COUNT );
		}

		$order->save();
	}

	/**
	 * Get Zoho payment ID for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null
	 */
	public function get_payment_id( WC_Order $order ): ?string {
		$value = $order->get_meta( self::META_PAYMENT_ID );
		return $value !== '' ? $value : null;
	}

	/**
	 * Set Zoho payment ID for an order.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $payment_id Zoho payment ID.
	 */
	public function set_payment_id( WC_Order $order, string $payment_id ): void {
		$order->update_meta_data( self::META_PAYMENT_ID, $payment_id );
		$order->save();
	}

	/**
	 * Get Zoho invoice number for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null
	 */
	public function get_invoice_number( WC_Order $order ): ?string {
		$value = $order->get_meta( self::META_INVOICE_NUMBER );
		return $value !== '' ? $value : null;
	}

	/**
	 * Set Zoho invoice number for an order.
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param string   $invoice_number Zoho invoice number.
	 */
	public function set_invoice_number( WC_Order $order, string $invoice_number ): void {
		$order->update_meta_data( self::META_INVOICE_NUMBER, $invoice_number );
		$order->save();
	}

	/**
	 * Get Zoho payment number for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null
	 */
	public function get_payment_number( WC_Order $order ): ?string {
		$value = $order->get_meta( self::META_PAYMENT_NUMBER );
		return $value !== '' ? $value : null;
	}

	/**
	 * Set Zoho payment number for an order.
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param string   $payment_number Zoho payment number.
	 */
	public function set_payment_number( WC_Order $order, string $payment_number ): void {
		$order->update_meta_data( self::META_PAYMENT_NUMBER, $payment_number );
		$order->save();
	}

	/**
	 * Get Zoho contact display name for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null
	 */
	public function get_contact_name( WC_Order $order ): ?string {
		$value = $order->get_meta( self::META_CONTACT_NAME );
		return $value !== '' ? $value : null;
	}

	/**
	 * Set Zoho contact display name for an order.
	 *
	 * @param WC_Order $order        WooCommerce order.
	 * @param string   $contact_name Zoho contact display name.
	 */
	public function set_contact_name( WC_Order $order, string $contact_name ): void {
		$order->update_meta_data( self::META_CONTACT_NAME, $contact_name );
		$order->save();
	}

	/**
	 * Get Zoho invoice status for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null Invoice status (draft/sent/paid/partially_paid/overdue/void).
	 */
	public function get_invoice_status( WC_Order $order ): ?string {
		$value = $order->get_meta( self::META_INVOICE_STATUS );
		return $value !== '' ? $value : null;
	}

	/**
	 * Set Zoho invoice status for an order.
	 *
	 * @param WC_Order $order  WooCommerce order.
	 * @param string   $status Zoho invoice status.
	 */
	public function set_invoice_status( WC_Order $order, string $status ): void {
		$order->update_meta_data( self::META_INVOICE_STATUS, $status );
		$order->save();
	}

	/**
	 * Get payment error for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null Payment error message.
	 */
	public function get_payment_error( WC_Order $order ): ?string {
		$value = $order->get_meta( self::META_PAYMENT_ERROR );
		return $value !== '' ? $value : null;
	}

	/**
	 * Set payment error for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $error Payment error message.
	 */
	public function set_payment_error( WC_Order $order, string $error ): void {
		$order->update_meta_data( self::META_PAYMENT_ERROR, $error );
		$order->save();
	}

	/**
	 * Clear payment error for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public function clear_payment_error( WC_Order $order ): void {
		$order->delete_meta_data( self::META_PAYMENT_ERROR );
		$order->save();
	}

	/**
	 * Get unapplied credit note data for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array|null Unapplied credit data or null.
	 */
	public function get_unapplied_credit( WC_Order $order ): ?array {
		$value = $order->get_meta( self::META_UNAPPLIED_CREDIT );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Set unapplied credit note for an order.
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param string   $credit_note_id Zoho credit note ID.
	 * @param string   $reason         Reason for failure.
	 */
	public function set_unapplied_credit( WC_Order $order, string $credit_note_id, string $reason ): void {
		$order->update_meta_data(
			self::META_UNAPPLIED_CREDIT,
			[
				'credit_note_id' => $credit_note_id,
				'reason'         => $reason,
				'timestamp'      => current_time( 'mysql' ),
			]
		);
		$order->save();
	}

	/**
	 * Clear unapplied credit note for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public function clear_unapplied_credit( WC_Order $order ): void {
		$order->delete_meta_data( self::META_UNAPPLIED_CREDIT );
		$order->save();
	}

	/**
	 * Get Zoho refund IDs for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<int, array{refund_id: int, zoho_refund_id: string, zoho_credit_note_id: string}>
	 */
	public function get_refund_ids( WC_Order $order ): array {
		$value = $order->get_meta( self::META_REFUND_IDS );
		return is_array( $value ) ? $value : [];
	}

	/**
	 * Add a refund ID mapping.
	 *
	 * @param WC_Order $order                  WooCommerce order.
	 * @param int      $wc_refund_id           WooCommerce refund ID.
	 * @param string   $zoho_refund_id         Zoho refund ID.
	 * @param string   $zoho_credit_note_id    Zoho credit note ID.
	 * @param string   $zoho_credit_note_number Zoho credit note number (human-readable).
	 */
	public function add_refund_id(
		WC_Order $order,
		int $wc_refund_id,
		string $zoho_refund_id,
		string $zoho_credit_note_id = '',
		string $zoho_credit_note_number = ''
	): void {
		$refunds   = $this->get_refund_ids( $order );
		$refunds[] = [
			'refund_id'               => $wc_refund_id,
			'zoho_refund_id'          => $zoho_refund_id,
			'zoho_credit_note_id'     => $zoho_credit_note_id,
			'zoho_credit_note_number' => $zoho_credit_note_number,
			'created_at'              => gmdate( 'Y-m-d H:i:s' ),
		];
		$order->update_meta_data( self::META_REFUND_IDS, $refunds );
		$order->save();
	}

	/**
	 * Get Zoho IDs for a specific WooCommerce refund.
	 *
	 * @param WC_Order $order        WooCommerce order.
	 * @param int      $wc_refund_id WooCommerce refund ID.
	 * @return array|null Refund data or null.
	 */
	public function get_zoho_refund_for_wc_refund( WC_Order $order, int $wc_refund_id ): ?array {
		$refunds = $this->get_refund_ids( $order );

		foreach ( $refunds as $refund ) {
			if ( (int) $refund['refund_id'] === $wc_refund_id ) {
				return $refund;
			}
		}

		return null;
	}

	/**
	 * Check if order has payment recorded.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	public function has_payment( WC_Order $order ): bool {
		return $this->get_payment_id( $order ) !== null;
	}

	/**
	 * Get complete Zoho sync data for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	public function get_all_zoho_data( WC_Order $order ): array {
		return [
			'invoice_id'        => $this->get_invoice_id( $order ),
			'invoice_number'    => $this->get_invoice_number( $order ),
			'contact_id'        => $this->get_contact_id( $order ),
			'contact_name'      => $this->get_contact_name( $order ),
			'payment_id'        => $this->get_payment_id( $order ),
			'payment_number'    => $this->get_payment_number( $order ),
			'invoice_status'    => $this->get_invoice_status( $order ),
			'payment_error'     => $this->get_payment_error( $order ),
			'unapplied_credit'  => $this->get_unapplied_credit( $order ),
			'refunds'           => $this->get_refund_ids( $order ),
			'sync_status'       => $this->get_sync_status( $order )?->value,
			'last_sync_attempt' => $this->get_last_sync_attempt( $order )?->format( 'Y-m-d H:i:s' ),
			'sync_error'        => $this->get_sync_error( $order ),
			'retry_count'       => $this->get_retry_count( $order ),
		];
	}
}
