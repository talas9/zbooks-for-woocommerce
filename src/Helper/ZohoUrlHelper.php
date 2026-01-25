<?php
/**
 * Zoho Books URL helper.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Helper for building Zoho Books URLs.
 */
class ZohoUrlHelper {

	/**
	 * Entity type to URL path mapping.
	 *
	 * @var array<string, string>
	 */
	private const PATHS = [
		'invoice'    => 'invoices',
		'payment'    => 'customerpayments',
		'creditnote' => 'creditnotes',
		'contact'    => 'contacts',
		'item'       => 'inventory/items',
	];

	/**
	 * Datacenter to domain mapping.
	 *
	 * @var array<string, string>
	 */
	private const DOMAINS = [
		'us' => 'books.zoho.com',
		'eu' => 'books.zoho.eu',
		'in' => 'books.zoho.in',
		'au' => 'books.zoho.com.au',
		'jp' => 'books.zoho.jp',
	];

	/**
	 * Get Zoho Books URL for an entity.
	 *
	 * @param string $type Entity type (invoice, payment, creditnote, contact, item).
	 * @param string $id   Entity ID.
	 * @return string Full URL to the entity in Zoho Books.
	 */
	public static function get_url( string $type, string $id ): string {
		$datacenter = get_option( 'zbooks_datacenter', 'us' );
		$domain     = self::DOMAINS[ $datacenter ] ?? self::DOMAINS['us'];
		$path       = self::PATHS[ $type ] ?? $type;

		return sprintf(
			'https://%s/app#/%s/%s',
			$domain,
			$path,
			$id
		);
	}

	/**
	 * Get invoice URL.
	 *
	 * @param string $invoice_id Zoho invoice ID.
	 * @return string Invoice URL.
	 */
	public static function invoice( string $invoice_id ): string {
		return self::get_url( 'invoice', $invoice_id );
	}

	/**
	 * Get payment URL.
	 *
	 * @param string $payment_id Zoho payment ID.
	 * @return string Payment URL.
	 */
	public static function payment( string $payment_id ): string {
		return self::get_url( 'payment', $payment_id );
	}

	/**
	 * Get credit note URL.
	 *
	 * @param string $credit_note_id Zoho credit note ID.
	 * @return string Credit note URL.
	 */
	public static function credit_note( string $credit_note_id ): string {
		return self::get_url( 'creditnote', $credit_note_id );
	}

	/**
	 * Get contact URL.
	 *
	 * @param string $contact_id Zoho contact ID.
	 * @return string Contact URL.
	 */
	public static function contact( string $contact_id ): string {
		return self::get_url( 'contact', $contact_id );
	}

	/**
	 * Get item URL.
	 *
	 * @param string $item_id Zoho item ID.
	 * @return string Item URL.
	 */
	public static function item( string $item_id ): string {
		return self::get_url( 'item', $item_id );
	}

	/**
	 * Build an HTML link to a Zoho entity.
	 *
	 * @param string $type  Entity type.
	 * @param string $id    Entity ID.
	 * @param string $label Link label (if empty, uses the ID).
	 * @return string HTML anchor tag.
	 */
	public static function link( string $type, string $id, string $label = '' ): string {
		$url   = self::get_url( $type, $id );
		$label = ! empty( $label ) ? $label : $id;

		return sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
}
