<?php
/**
 * Customer service for Zoho Books.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

use WC_Order;
use Zbooks\Api\ZohoClient;
use Zbooks\Logger\SyncLogger;
use Zbooks\Repository\FieldMappingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Service for managing Zoho Books contacts.
 */
class CustomerService {

	/**
	 * Zoho client.
	 *
	 * @var ZohoClient
	 */
	private ZohoClient $client;

	/**
	 * Logger.
	 *
	 * @var SyncLogger
	 */
	private SyncLogger $logger;

	/**
	 * Field mapping repository.
	 *
	 * @var FieldMappingRepository
	 */
	private FieldMappingRepository $field_mapping;

	/**
	 * Constructor.
	 *
	 * @param ZohoClient             $client        Zoho client instance.
	 * @param SyncLogger             $logger        Logger instance.
	 * @param FieldMappingRepository $field_mapping Field mapping repository.
	 */
	public function __construct(
		ZohoClient $client,
		SyncLogger $logger,
		?FieldMappingRepository $field_mapping = null
	) {
		$this->client        = $client;
		$this->logger        = $logger;
		$this->field_mapping = $field_mapping ?? new FieldMappingRepository();
	}

	/**
	 * Find or create a Zoho contact for a WooCommerce order.
	 *
	 * If the contact exists, updates their details if changed.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string Zoho contact ID.
	 * @throws \RuntimeException If contact creation fails or currency mismatch.
	 */
	public function find_or_create_contact( WC_Order $order ): string {
		$email = $order->get_billing_email();

		if ( empty( $email ) ) {
			throw new \RuntimeException(
				esc_html__( 'Order has no billing email address.', 'zbooks-for-woocommerce' )
			);
		}

		// Try to find existing contact by email (get full data for comparison).
		$existing_contact = $this->get_contact_by_email( $email );

		if ( $existing_contact !== null ) {
			$contact_id = (string) $existing_contact['contact_id'];

			$this->logger->debug(
				'Found existing contact',
				[
					'email'      => $email,
					'contact_id' => $contact_id,
				]
			);

			// Check currency compatibility.
			$currency_check = $this->check_currency_compatibility( $existing_contact, $order );
			if ( ! $currency_check['compatible'] ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: 1: Contact currency, 2: Order currency */
						esc_html__( 'Currency mismatch: Contact is set to %1$s but order uses %2$s. Please update the contact currency in Zoho Books or use a different email.', 'zbooks-for-woocommerce' ),
						esc_html( $currency_check['contact_currency'] ),
						esc_html( $currency_check['order_currency'] )
					)
				);
			}

			// Check if contact needs updating with new order details.
			if ( $this->contact_needs_update( $existing_contact, $order ) ) {
				$this->update_contact( $contact_id, $order );
			}

			return $contact_id;
		}

		// Create new contact.
		return $this->create_contact( $order );
	}

	/**
	 * Find a contact by email address.
	 *
	 * @param string $email Email address.
	 * @return string|null Contact ID or null if not found.
	 */
	public function find_contact_by_email( string $email ): ?string {
		$contact = $this->get_contact_by_email( $email );
		return $contact ? (string) $contact['contact_id'] : null;
	}

	/**
	 * Get contact's currency code.
	 *
	 * @param string $contact_id Zoho contact ID.
	 * @return string|null Currency code or null.
	 */
	public function get_contact_currency( string $contact_id ): ?string {
		$contact = $this->get_contact( $contact_id );
		return $contact['currency_code'] ?? null;
	}

	/**
	 * Check if order currency is compatible with existing contact.
	 *
	 * @param array    $contact Zoho contact data.
	 * @param WC_Order $order   WooCommerce order.
	 * @return array{compatible: bool, contact_currency: ?string, order_currency: string}
	 */
	public function check_currency_compatibility( array $contact, WC_Order $order ): array {
		$order_currency   = $order->get_currency();
		$contact_currency = $contact['currency_code'] ?? null;

		// If contact has no currency set, it's compatible (will use org default).
		if ( empty( $contact_currency ) ) {
			return [
				'compatible'       => true,
				'contact_currency' => null,
				'order_currency'   => $order_currency,
			];
		}

		// Check if currencies match.
		$compatible = strtoupper( $contact_currency ) === strtoupper( $order_currency );

		if ( ! $compatible ) {
			$this->logger->warning(
				'Currency mismatch detected',
				[
					'email'            => $order->get_billing_email(),
					'contact_currency' => $contact_currency,
					'order_currency'   => $order_currency,
				]
			);
		}

		return [
			'compatible'       => $compatible,
			'contact_currency' => $contact_currency,
			'order_currency'   => $order_currency,
		];
	}

	/**
	 * Get full contact data by email address.
	 *
	 * @param string $email Email address.
	 * @return array|null Contact data or null if not found.
	 */
	public function get_contact_by_email( string $email ): ?array {
		try {
			$response = $this->client->request(
				function ( $client ) use ( $email ) {
					return $client->contacts->getList(
						[
							'email' => $email,
						]
					);
				},
				[
					'endpoint' => 'contacts.getList',
					'filter'   => 'email',
					'email'    => $email,
				]
			);

			// Handle different response formats.
			$contacts = [];
			if ( is_object( $response ) ) {
				if ( method_exists( $response, 'toArray' ) ) {
					$contacts = $response->toArray();
				} else {
					$response = json_decode( wp_json_encode( $response ), true );
					$contacts = $response['contacts'] ?? $response;
				}
			} elseif ( is_array( $response ) ) {
				$contacts = $response['contacts'] ?? $response;
			}

			if ( ! empty( $contacts ) && isset( $contacts[0] ) ) {
				// Get full contact details.
				$contact_id = $contacts[0]['contact_id'];
				return $this->get_contact( $contact_id );
			}
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to search for contact',
				[
					'email' => $email,
					'error' => $e->getMessage(),
				]
			);
		}

		return null;
	}

	/**
	 * Get contact details by ID.
	 *
	 * @param string $contact_id Zoho contact ID.
	 * @return array|null Contact data or null.
	 */
	public function get_contact( string $contact_id ): ?array {
		try {
			$response = $this->client->request(
				function ( $client ) use ( $contact_id ) {
					return $client->contacts->get( $contact_id );
				},
				[
					'endpoint'   => 'contacts.get',
					'contact_id' => $contact_id,
				]
			);

			if ( is_object( $response ) ) {
				if ( method_exists( $response, 'toArray' ) ) {
					return $response->toArray();
				}
				$response = json_decode( wp_json_encode( $response ), true );
			}

			if ( is_array( $response ) ) {
				return $response['contact'] ?? $response;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to get contact',
				[
					'contact_id' => $contact_id,
					'error'      => $e->getMessage(),
				]
			);
		}

		return null;
	}

	/**
	 * Verify that a contact exists in Zoho Books.
	 *
	 * @param string $contact_id Zoho contact ID.
	 * @return bool True if contact exists, false otherwise.
	 */
	public function verify_contact_exists( string $contact_id ): bool {
		$contact = $this->get_contact( $contact_id );
		return $contact !== null;
	}

	/**
	 * Create a new Zoho contact from WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string Zoho contact ID.
	 * @throws \RuntimeException If creation fails.
	 */
	public function create_contact( WC_Order $order ): string {
		$contact_data = $this->map_order_to_contact( $order );

		$this->logger->info(
			'Creating new contact',
			[
				'order_id' => $order->get_id(),
				'email'    => $contact_data['email'],
				'name'     => $contact_data['contact_name'],
			]
		);

		try {
			$response = $this->client->request(
				function ( $client ) use ( $contact_data ) {
					return $client->contacts->create( $contact_data );
				},
				[
					'endpoint' => 'contacts.create',
					'order_id' => $order->get_id(),
					'email'    => $contact_data['email'],
				]
			);

			// Convert object to array if needed.
			if ( is_object( $response ) ) {
				$response = json_decode( wp_json_encode( $response ), true );
			}

			$contact_data_response = $response['contact'] ?? $response;
			$contact_id            = (string) ( $contact_data_response['contact_id'] ?? '' );

			$this->logger->info(
				'Contact created successfully',
				[
					'contact_id' => $contact_id,
					'email'      => $contact_data['email'],
				]
			);

			return $contact_id;
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to create contact',
				[
					'order_id' => $order->get_id(),
					'email'    => $contact_data['email'],
					'error'    => $e->getMessage(),
				]
			);
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: Error message */
					esc_html__( 'Failed to create Zoho contact: %s', 'zbooks-for-woocommerce' ),
					esc_html( $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Map WooCommerce order data to Zoho contact format.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array Contact data.
	 */
	private function map_order_to_contact( WC_Order $order ): array {
		$contact = [
			'contact_name' => $this->get_contact_name( $order ),
			'email'        => $order->get_billing_email(),
			'contact_type' => 'customer',
		];

		// Add currency from order.
		$currency = $order->get_currency();
		if ( ! empty( $currency ) ) {
			$contact['currency_code'] = $currency;
		}

		// Add phone if available.
		$phone = $order->get_billing_phone();
		if ( ! empty( $phone ) ) {
			$contact['phone'] = $phone;
		}

		// Add company name if available.
		$company = $order->get_billing_company();
		if ( ! empty( $company ) ) {
			$contact['company_name'] = $company;
		}

		// Add billing address.
		$billing_address = $this->map_billing_address( $order );
		if ( ! empty( $billing_address ) ) {
			$contact['billing_address'] = $billing_address;
		}

		// Add shipping address if different from billing.
		if ( $order->has_shipping_address() ) {
			$shipping_address = $this->map_shipping_address( $order );
			if ( ! empty( $shipping_address ) ) {
				$contact['shipping_address'] = $shipping_address;
			}
		}

		// Add custom field mappings.
		$custom_fields = $this->field_mapping->build_custom_fields( $order, 'customer' );
		if ( ! empty( $custom_fields ) ) {
			$contact['custom_fields'] = $custom_fields;
			$this->logger->debug(
				'Adding custom fields to contact',
				[
					'email'              => $order->get_billing_email(),
					'custom_field_count' => count( $custom_fields ),
				]
			);
		}

		return $contact;
	}

	/**
	 * Get contact name from order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private function get_contact_name( WC_Order $order ): string {
		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();

		$name = trim( $first_name . ' ' . $last_name );

		if ( empty( $name ) ) {
			$name = $order->get_billing_email();
		}

		return $name;
	}

	/**
	 * Map billing address to Zoho format.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function map_billing_address( WC_Order $order ): array {
		return array_filter(
			[
				'address' => $order->get_billing_address_1(),
				'street2' => $order->get_billing_address_2(),
				'city'    => $order->get_billing_city(),
				'state'   => $order->get_billing_state(),
				'zip'     => $order->get_billing_postcode(),
				'country' => $order->get_billing_country(),
			]
		);
	}

	/**
	 * Map shipping address to Zoho format.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function map_shipping_address( WC_Order $order ): array {
		return array_filter(
			[
				'address' => $order->get_shipping_address_1(),
				'street2' => $order->get_shipping_address_2(),
				'city'    => $order->get_shipping_city(),
				'state'   => $order->get_shipping_state(),
				'zip'     => $order->get_shipping_postcode(),
				'country' => $order->get_shipping_country(),
			]
		);
	}

	/**
	 * Update an existing Zoho contact with order data.
	 *
	 * Note: Currency is NOT updated for existing contacts as Zoho doesn't
	 * allow changing currency after transactions exist.
	 *
	 * @param string   $contact_id Zoho contact ID.
	 * @param WC_Order $order      WooCommerce order.
	 * @return bool True on success.
	 */
	public function update_contact( string $contact_id, WC_Order $order ): bool {
		$contact_data = $this->map_order_to_contact( $order );

		// Remove currency_code - cannot change currency for existing contacts with transactions.
		unset( $contact_data['currency_code'] );

		$this->logger->info(
			'Updating contact',
			[
				'contact_id' => $contact_id,
				'email'      => $contact_data['email'],
			]
		);

		try {
			$this->client->request(
				function ( $client ) use ( $contact_id, $contact_data ) {
					return $client->contacts->update( $contact_id, $contact_data );
				},
				[
					'endpoint'   => 'contacts.update',
					'contact_id' => $contact_id,
					'email'      => $contact_data['email'],
				]
			);

			$this->logger->info(
				'Contact updated successfully',
				[
					'contact_id' => $contact_id,
				]
			);

			return true;
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to update contact',
				[
					'contact_id' => $contact_id,
					'error'      => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Check if contact needs to be updated based on order data.
	 *
	 * Compares key fields between existing contact and current order.
	 *
	 * @param array    $contact Existing Zoho contact data.
	 * @param WC_Order $order   WooCommerce order.
	 * @return bool True if contact needs updating.
	 */
	private function contact_needs_update( array $contact, WC_Order $order ): bool {
		// Compare name.
		$order_name   = $this->get_contact_name( $order );
		$contact_name = $contact['contact_name'] ?? '';
		if ( $order_name !== $contact_name ) {
			$this->logger->debug(
				'Contact name changed',
				[
					'old' => $contact_name,
					'new' => $order_name,
				]
			);
			return true;
		}

		// Compare phone.
		$order_phone   = $order->get_billing_phone();
		$contact_phone = $contact['phone'] ?? '';
		if ( ! empty( $order_phone ) && $order_phone !== $contact_phone ) {
			return true;
		}

		// Compare company.
		$order_company   = $order->get_billing_company();
		$contact_company = $contact['company_name'] ?? '';
		if ( ! empty( $order_company ) && $order_company !== $contact_company ) {
			return true;
		}

		// Compare billing address.
		if ( $this->address_differs( $contact, 'billing_address', $order, 'billing' ) ) {
			return true;
		}

		// Compare shipping address.
		if ( $order->has_shipping_address() ) {
			if ( $this->address_differs( $contact, 'shipping_address', $order, 'shipping' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if address differs between Zoho contact and WooCommerce order.
	 *
	 * @param array    $contact      Zoho contact data.
	 * @param string   $address_key  Key for address in contact (billing_address or shipping_address).
	 * @param WC_Order $order        WooCommerce order.
	 * @param string   $address_type Order address type (billing or shipping).
	 * @return bool True if address differs.
	 */
	private function address_differs(
		array $contact,
		string $address_key,
		WC_Order $order,
		string $address_type
	): bool {
		$zoho_address = $contact[ $address_key ] ?? [];

		// Get order address based on type.
		if ( $address_type === 'billing' ) {
			$order_address = $this->map_billing_address( $order );
		} else {
			$order_address = $this->map_shipping_address( $order );
		}

		// Compare key address fields.
		$fields_to_compare = [ 'address', 'city', 'state', 'zip', 'country' ];

		foreach ( $fields_to_compare as $field ) {
			$zoho_value  = $zoho_address[ $field ] ?? '';
			$order_value = $order_address[ $field ] ?? '';

			if ( $order_value !== $zoho_value ) {
				return true;
			}
		}

		return false;
	}
}
