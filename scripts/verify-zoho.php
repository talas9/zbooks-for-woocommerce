<?php
/**
 * Verify data in Zoho Books for E2E testing.
 *
 * This script queries Zoho Books API to verify that invoices, contacts,
 * and payments were created correctly.
 *
 * Usage:
 *   wp eval-file scripts/verify-zoho.php invoice <invoice_id>
 *   wp eval-file scripts/verify-zoho.php contact <contact_id>
 *   wp eval-file scripts/verify-zoho.php payment <payment_id>
 *   wp eval-file scripts/verify-zoho.php invoice-by-order <order_id>
 *
 * @package Zbooks
 */

// Exit if not running in WP-CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo json_encode( [ 'error' => 'Must run via WP-CLI' ] );
	exit( 1 );
}

// Get command arguments.
// WP-CLI eval-file: $argv = [wp, eval-file, script.php, arg1, arg2, ...]
// So our arguments start at index 3.
global $argv;
$action = $argv[3] ?? null;
$id     = $argv[4] ?? null;

if ( ! $action ) {
	echo json_encode( [ 'error' => 'Usage: verify-zoho.php <action> <id>' ] );
	exit( 1 );
}

// Ensure plugin is loaded - with retry logic for CI environments
if ( ! class_exists( 'Zbooks\\Plugin' ) ) {
	// Try to manually load the plugin if it's not loaded yet
	$plugin_file = dirname( __DIR__ ) . '/zbooks-for-woocommerce.php';
	
	if ( file_exists( $plugin_file ) ) {
		// Attempt to load the plugin
		require_once $plugin_file;
		
		// Wait for plugin initialization with retry logic (max 5 attempts, 1 second each)
		$max_attempts = 5;
		$attempt = 0;
		
		while ( $attempt < $max_attempts && ! class_exists( 'Zbooks\\Plugin' ) ) {
			sleep( 1 );
			$attempt++;
		}
		
		if ( ! class_exists( 'Zbooks\\Plugin' ) ) {
			echo json_encode( [
				'error' => 'ZBooks plugin class not available after loading plugin file',
				'success' => false,
				'plugin_file' => $plugin_file,
				'attempts' => $attempt
			] );
			exit( 1 );
		}
	} else {
		echo json_encode( [
			'error' => 'ZBooks plugin file not found',
			'success' => false,
			'expected_path' => $plugin_file
		] );
		exit( 1 );
	}
}

// Ensure the service container is initialized
$max_init_attempts = 3;
$init_attempt = 0;
$plugin = null;

while ( $init_attempt < $max_init_attempts ) {
	try {
		$plugin = \Zbooks\Plugin::get_instance();
		if ( $plugin ) {
			break;
		}
	} catch ( Exception $e ) {
		// Plugin not initialized yet, wait and retry
		sleep( 1 );
	}
	$init_attempt++;
}

if ( ! $plugin ) {
	echo json_encode( [
		'error' => 'Failed to get plugin instance after ' . $max_init_attempts . ' attempts',
		'success' => false
	] );
	exit( 1 );
}

try {
	// Plugin instance already obtained above with retry logic
	$zoho_client = $plugin->get_service( 'zoho_client' );
	
	if ( ! $zoho_client ) {
		echo json_encode( [
			'error' => 'Zoho client service not available',
			'success' => false
		] );
		exit( 1 );
	}

	switch ( $action ) {
		case 'invoice':
			$result = verify_invoice( $zoho_client, $id );
			break;

		case 'contact':
			$result = verify_contact( $zoho_client, $id );
			break;

		case 'payment':
			$result = verify_payment( $zoho_client, $id );
			break;

		case 'invoice-by-order':
			$result = verify_invoice_by_order( $zoho_client, $id );
			break;

		case 'connection':
			$result = verify_connection( $zoho_client );
			break;

		default:
			$result = [ 'error' => "Unknown action: {$action}" ];
	}

	echo json_encode( $result, JSON_PRETTY_PRINT );

} catch ( Exception $e ) {
	echo json_encode( [
		'error'   => $e->getMessage(),
		'success' => false,
	] );
	exit( 1 );
}

/**
 * Verify an invoice exists in Zoho and return its details.
 */
function verify_invoice( $zoho_client, string $invoice_id ): array {
	if ( empty( $invoice_id ) ) {
		return [ 'error' => 'Invoice ID required', 'success' => false ];
	}

	try {
		$invoice = $zoho_client->request(
			fn( $client ) => $client->invoices->get( $invoice_id ),
			[ 'endpoint' => 'invoices.get', 'invoice_id' => $invoice_id ]
		);

		if ( ! $invoice ) {
			return [
				'success' => false,
				'exists'  => false,
				'error'   => 'Invoice not found',
			];
		}

		// Convert to array if it's an object.
		$data = is_array( $invoice ) ? $invoice : ( method_exists( $invoice, 'toArray' ) ? $invoice->toArray() : (array) $invoice );

		return [
			'success'        => true,
			'exists'         => true,
			'invoice_id'     => $data['invoice_id'] ?? $invoice_id,
			'invoice_number' => $data['invoice_number'] ?? null,
			'status'         => $data['status'] ?? null,
			'total'          => (float) ( $data['total'] ?? 0 ),
			'balance'        => (float) ( $data['balance'] ?? 0 ),
			'customer_id'    => $data['customer_id'] ?? null,
			'customer_name'  => $data['customer_name'] ?? null,
			'date'           => $data['date'] ?? null,
			'due_date'       => $data['due_date'] ?? null,
			'line_items'     => count( $data['line_items'] ?? [] ),
		];

	} catch ( Exception $e ) {
		return [
			'success' => false,
			'exists'  => false,
			'error'   => $e->getMessage(),
		];
	}
}

/**
 * Verify a contact exists in Zoho and return its details.
 */
function verify_contact( $zoho_client, string $contact_id ): array {
	if ( empty( $contact_id ) ) {
		return [ 'error' => 'Contact ID required', 'success' => false ];
	}

	try {
		$contact = $zoho_client->request(
			fn( $client ) => $client->contacts->get( $contact_id ),
			[ 'endpoint' => 'contacts.get', 'contact_id' => $contact_id ]
		);

		if ( ! $contact ) {
			return [
				'success' => false,
				'exists'  => false,
				'error'   => 'Contact not found',
			];
		}

		$data = is_array( $contact ) ? $contact : ( method_exists( $contact, 'toArray' ) ? $contact->toArray() : (array) $contact );

		// Extract primary contact person email/phone if available.
		$contact_persons = $data['contact_persons'] ?? [];
		$primary_person  = null;
		foreach ( $contact_persons as $person ) {
			if ( ! empty( $person['is_primary_contact'] ) ) {
				$primary_person = $person;
				break;
			}
		}
		// Fallback to first contact person.
		if ( ! $primary_person && ! empty( $contact_persons ) ) {
			$primary_person = $contact_persons[0];
		}

		return [
			'success'        => true,
			'exists'         => true,
			'contact_id'     => $data['contact_id'] ?? $contact_id,
			'contact_name'   => $data['contact_name'] ?? null,
			'email'          => $primary_person['email'] ?? $data['email'] ?? null,
			'phone'          => $primary_person['phone'] ?? $data['phone'] ?? null,
			'status'         => $data['status'] ?? null,
			'contact_persons' => count( $contact_persons ),
		];

	} catch ( Exception $e ) {
		return [
			'success' => false,
			'exists'  => false,
			'error'   => $e->getMessage(),
		];
	}
}

/**
 * Verify a payment exists in Zoho and return its details.
 */
function verify_payment( $zoho_client, string $payment_id ): array {
	if ( empty( $payment_id ) ) {
		return [ 'error' => 'Payment ID required', 'success' => false ];
	}

	try {
		$payment = $zoho_client->request(
			fn( $client ) => $client->customerpayments->get( $payment_id ),
			[ 'endpoint' => 'customerpayments.get', 'payment_id' => $payment_id ]
		);

		if ( ! $payment ) {
			return [
				'success' => false,
				'exists'  => false,
				'error'   => 'Payment not found',
			];
		}

		$data = is_array( $payment ) ? $payment : ( method_exists( $payment, 'toArray' ) ? $payment->toArray() : (array) $payment );

		return [
			'success'       => true,
			'exists'        => true,
			'payment_id'    => $data['payment_id'] ?? $payment_id,
			'payment_number'=> $data['payment_number'] ?? null,
			'amount'        => (float) ( $data['amount'] ?? 0 ),
			'bank_charges'  => (float) ( $data['bank_charges'] ?? 0 ),
			'date'          => $data['date'] ?? null,
			'payment_mode'  => $data['payment_mode'] ?? null,
			'customer_id'   => $data['customer_id'] ?? null,
			'customer_name' => $data['customer_name'] ?? null,
			'invoices'      => array_map(
				fn( $inv ) => [
					'invoice_id'     => $inv['invoice_id'] ?? null,
					'amount_applied' => (float) ( $inv['amount_applied'] ?? 0 ),
				],
				$data['invoices'] ?? []
			),
		];

	} catch ( Exception $e ) {
		return [
			'success' => false,
			'exists'  => false,
			'error'   => $e->getMessage(),
		];
	}
}

/**
 * Get invoice details by WooCommerce order ID.
 */
function verify_invoice_by_order( $zoho_client, string $order_id ): array {
	if ( empty( $order_id ) ) {
		return [ 'error' => 'Order ID required', 'success' => false ];
	}

	$order = wc_get_order( (int) $order_id );
	if ( ! $order ) {
		return [ 'error' => 'Order not found', 'success' => false ];
	}

	$invoice_id = $order->get_meta( '_zbooks_zoho_invoice_id' );
	if ( empty( $invoice_id ) ) {
		return [
			'success'    => false,
			'synced'     => false,
			'error'      => 'Order not synced to Zoho',
			'order_id'   => $order_id,
			'wc_total'   => (float) $order->get_total(),
			'wc_status'  => $order->get_status(),
		];
	}

	// Get invoice from Zoho.
	$invoice_result = verify_invoice( $zoho_client, $invoice_id );

	// Add WooCommerce comparison data.
	$invoice_result['order_id']       = $order_id;
	$invoice_result['wc_total']       = (float) $order->get_total();
	$invoice_result['wc_status']      = $order->get_status();
	$invoice_result['synced']         = true;
	$invoice_result['totals_match']   = abs( $invoice_result['total'] - (float) $order->get_total() ) < 0.01;

	// Check payment sync.
	$payment_id = $order->get_meta( '_zbooks_zoho_payment_id' );
	if ( $payment_id ) {
		$invoice_result['payment_synced'] = true;
		$invoice_result['payment_id']     = $payment_id;
	} else {
		$invoice_result['payment_synced'] = false;
	}

	return $invoice_result;
}

/**
 * Verify Zoho connection is working.
 */
function verify_connection( $zoho_client ): array {
	try {
		$connected = $zoho_client->test_connection();
		
		// If connection failed, try to get more details about why
		if ( ! $connected ) {
			// Check if credentials are configured
			$org_id = get_option( 'zbooks_organization_id' );
			$client_id = get_option( 'zbooks_client_id' );
			$has_creds = ! empty( $org_id ) && ! empty( $client_id );
			
			return [
				'success'   => false,
				'connected' => false,
				'error'     => 'Connection test returned false. Credentials configured: ' . ( $has_creds ? 'yes' : 'no' ),
				'debug'     => [
					'has_organization_id' => ! empty( $org_id ),
					'has_client_id'       => ! empty( $client_id ),
				],
			];
		}
		
		return [
			'success'   => true,
			'connected' => true,
		];
	} catch ( Exception $e ) {
		return [
			'success'   => false,
			'connected' => false,
			'error'     => $e->getMessage(),
		];
	}
}
