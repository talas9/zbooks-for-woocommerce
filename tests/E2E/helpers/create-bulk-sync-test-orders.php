<?php
/**
 * Helper script to create test orders for bulk sync testing.
 * 
 * This script creates orders with different statuses BEFORE the plugin
 * automatic triggers would sync them, allowing us to test bulk sync behavior.
 * 
 * Usage: wp eval-file tests/E2E/helpers/create-bulk-sync-test-orders.php
 */

// Ensure WooCommerce is loaded.
if ( ! function_exists( 'wc_create_order' ) ) {
	echo "Error: WooCommerce is not active.\n";
	exit( 1 );
}

/**
 * Create a test order with specific status.
 *
 * @param string $status Order status (without 'wc-' prefix).
 * @param float  $total  Order total.
 * @return int Order ID.
 */
function create_test_order( string $status, float $total = 100.00 ): int {
	$order = wc_create_order();
	
	// Set billing details.
	$order->set_billing_first_name( 'Bulk' );
	$order->set_billing_last_name( 'Test' );
	$order->set_billing_email( 'bulktest@example.com' );
	$order->set_billing_phone( '1234567890' );
	$order->set_billing_address_1( '123 Test Street' );
	$order->set_billing_city( 'Test City' );
	$order->set_billing_state( 'TC' );
	$order->set_billing_postcode( '12345' );
	$order->set_billing_country( 'US' );
	
	// Add a simple product.
	$product = wc_get_product( wc_get_products( [ 'limit' => 1 ] )[0]->get_id() );
	if ( ! $product ) {
		// Create a simple product if none exists.
		$product = new WC_Product_Simple();
		$product->set_name( 'Test Product for Bulk Sync' );
		$product->set_regular_price( $total );
		$product->set_status( 'publish' );
		$product->save();
	}
	
	$order->add_product( $product, 1 );
	
	// Set order status (this will NOT trigger sync if done before plugin hooks are registered).
	$order->set_status( $status );
	
	// Save order.
	$order->calculate_totals();
	$order->save();
	
	return $order->get_id();
}

echo "Creating test orders for bulk sync testing...\n\n";

// Create orders with different statuses.
$orders = [
	'processing' => [],
	'completed'  => [],
	'on-hold'    => [],
];

// Create 2 processing orders.
echo "Creating processing orders...\n";
for ( $i = 0; $i < 2; $i++ ) {
	$order_id = create_test_order( 'processing', 100.00 + ( $i * 10 ) );
	$orders['processing'][] = $order_id;
	echo "  Created order #{$order_id} with status 'processing'\n";
}

// Create 2 completed orders.
echo "\nCreating completed orders...\n";
for ( $i = 0; $i < 2; $i++ ) {
	$order_id = create_test_order( 'completed', 200.00 + ( $i * 10 ) );
	$orders['completed'][] = $order_id;
	echo "  Created order #{$order_id} with status 'completed'\n";
}

// Create 1 on-hold order.
echo "\nCreating on-hold order...\n";
$order_id = create_test_order( 'on-hold', 150.00 );
$orders['on-hold'][] = $order_id;
echo "  Created order #{$order_id} with status 'on-hold'\n";

echo "\n✓ Successfully created " . array_sum( array_map( 'count', $orders ) ) . " test orders.\n";
echo "\nOrder IDs by status:\n";
foreach ( $orders as $status => $order_ids ) {
	echo "  {$status}: " . implode( ', ', $order_ids ) . "\n";
}

echo "\nThese orders are ready for bulk sync testing.\n";
echo "They have NOT been synced to Zoho Books yet.\n";
