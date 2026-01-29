<?php
/**
 * Bulk sync orders via WP-CLI.
 *
 * Usage: wp eval-file scripts/bulk-sync-orders.php 148,149,150,151,152
 */

// Get order IDs from command line args.
// WP-CLI puts positional arguments in $args variable.
$order_ids_str = isset( $args[0] ) ? $args[0] : '';

if ( empty( $order_ids_str ) || $order_ids_str === '0' ) {
    echo "Usage: wp eval-file scripts/bulk-sync-orders.php <order_ids>\n";
    echo "Example: wp eval-file scripts/bulk-sync-orders.php 148,149,150,151,152\n";
    exit( 1 );
}

$order_ids = array_map( 'intval', explode( ',', $order_ids_str ) );
$order_ids = array_filter( $order_ids ); // Remove zeros.

echo "Starting bulk sync for orders: " . implode( ', ', $order_ids ) . "\n\n";

// Make sure WooCommerce and zBooks are loaded.
if ( ! function_exists( 'wc' ) ) {
    echo "WooCommerce is not active\n";
    exit( 1 );
}

// Get the bulk sync service from the plugin.
try {
    $plugin = \Zbooks\Plugin::get_instance();
    $service = $plugin->get_service( 'bulk_sync_service' );
    
    // Run the sync.
    $results = $service->sync_orders( $order_ids );
    
    echo "Sync completed!\n";
    echo "Success: {$results['success']}\n";
    echo "Failed: {$results['failed']}\n\n";
    
    if ( ! empty( $results['results'] ) ) {
        echo "Details:\n";
        foreach ( $results['results'] as $order_id => $result ) {
            $status = $result['success'] ? '✓' : '✗';
            $message = $result['message'] ?? 'No message';
            $invoice = isset( $result['invoice_id'] ) ? " (Invoice: {$result['invoice_id']})" : '';
            echo "  Order #{$order_id}: {$status} {$message}{$invoice}\n";
        }
    }
    
} catch ( Exception $e ) {
    echo "Error: " . $e->getMessage() . "\n";
    exit( 1 );
}
