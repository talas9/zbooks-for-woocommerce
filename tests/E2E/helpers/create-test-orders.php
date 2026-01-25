<?php
/**
 * WP-CLI helper to create test orders with various discount/shipping/fee configurations.
 *
 * Usage:
 *   wp eval-file tests/E2E/helpers/create-test-orders.php
 *
 * Or via wp-env:
 *   npx wp-env run cli wp eval-file /var/www/html/wp-content/plugins/zbooks-for-woocommerce/tests/E2E/helpers/create-test-orders.php
 *
 * @package Zbooks
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Allow running via WP-CLI.
    define( 'ABSPATH', dirname( __FILE__, 7 ) . '/' );
}

// Ensure WooCommerce is active.
if ( ! class_exists( 'WooCommerce' ) ) {
    WP_CLI::error( 'WooCommerce is not active.' );
    exit;
}

/**
 * Create a test order with specific configurations.
 *
 * @param array $config Order configuration.
 * @return WC_Order|WP_Error
 */
function zbooks_create_test_order( array $config ) {
    $defaults = [
        'subtotal'      => 100.00,
        'discount'      => 0,
        'discount_type' => 'fixed', // 'fixed' or 'percent'
        'shipping'      => 0,
        'fee_name'      => '',
        'fee_amount'    => 0,
        'status'        => 'completed',
        'customer'      => [
            'email'      => 'test-' . wp_rand() . '@example.com',
            'first_name' => 'Test',
            'last_name'  => 'Customer',
        ],
    ];

    $config = wp_parse_args( $config, $defaults );

    // Create order.
    $order = wc_create_order( [ 'status' => 'pending' ] );

    if ( is_wp_error( $order ) ) {
        return $order;
    }

    // Set billing details.
    $order->set_billing_first_name( $config['customer']['first_name'] );
    $order->set_billing_last_name( $config['customer']['last_name'] );
    $order->set_billing_email( $config['customer']['email'] );
    $order->set_billing_address_1( '123 Test Street' );
    $order->set_billing_city( 'Test City' );
    $order->set_billing_state( 'TS' );
    $order->set_billing_postcode( '12345' );
    $order->set_billing_country( 'US' );

    // Add a product line item.
    // First, check if we have a product to use, or create one.
    $products = wc_get_products( [ 'limit' => 1, 'status' => 'publish' ] );
    if ( empty( $products ) ) {
        // Create a simple product.
        $product = new WC_Product_Simple();
        $product->set_name( 'Test Product for Discount Test' );
        $product->set_regular_price( $config['subtotal'] );
        $product->set_status( 'publish' );
        $product->set_tax_status( 'none' );
        $product->save();
    } else {
        $product = $products[0];
    }

    // Refresh the product to ensure it's fully loaded.
    $product = wc_get_product( $product->get_id() );

    // Add product with the configured subtotal price using WooCommerce's method.
    $order->add_product( $product, 1, [
        'subtotal' => $config['subtotal'],
        'total'    => $config['subtotal'],
    ] );

    // Save order first before applying coupon.
    $order->save();

    // Add coupon/discount manually (avoid apply_coupon which can fail programmatically).
    if ( $config['discount'] > 0 ) {
        $coupon_code = 'test-discount-' . wp_rand();

        // Create the coupon.
        $coupon = new WC_Coupon();
        $coupon->set_code( $coupon_code );

        if ( $config['discount_type'] === 'percent' ) {
            $coupon->set_discount_type( 'percent' );
            $coupon->set_amount( $config['discount'] );
            $actual_discount = $config['subtotal'] * ( $config['discount'] / 100 );
        } else {
            $coupon->set_discount_type( 'fixed_cart' );
            $coupon->set_amount( $config['discount'] );
            $actual_discount = $config['discount'];
        }
        $coupon->save();

        // Add coupon item directly to order.
        $coupon_item = new WC_Order_Item_Coupon();
        $coupon_item->set_code( $coupon_code );
        $coupon_item->set_discount( $actual_discount );
        $coupon_item->set_discount_tax( 0 );
        $order->add_item( $coupon_item );

        // Update the product line item total to reflect discount.
        foreach ( $order->get_items() as $item ) {
            if ( $item instanceof WC_Order_Item_Product ) {
                $new_total = $item->get_subtotal() - $actual_discount;
                $item->set_total( max( 0, $new_total ) );
                $item->save();
            }
        }
    }

    // Add shipping.
    if ( $config['shipping'] > 0 ) {
        $shipping_item = new WC_Order_Item_Shipping();
        $shipping_item->set_method_title( 'Flat Rate' );
        $shipping_item->set_method_id( 'flat_rate:1' );
        $shipping_item->set_total( $config['shipping'] );
        $order->add_item( $shipping_item );
    }

    // Add fee (e.g., bank fee).
    if ( ! empty( $config['fee_name'] ) && $config['fee_amount'] != 0 ) {
        $fee = new WC_Order_Item_Fee();
        $fee->set_name( $config['fee_name'] );
        $fee->set_total( $config['fee_amount'] );
        $order->add_item( $fee );
    }

    // Calculate totals.
    $order->calculate_totals( false ); // false = don't recalculate taxes.

    // Set status.
    $order->set_status( $config['status'] );

    // Save order.
    $order->save();

    return $order;
}

/**
 * Create all test order scenarios.
 */
function zbooks_create_all_test_orders() {
    $scenarios = [
        // Basic scenarios.
        [
            'name'     => 'Simple order (no discount, no shipping, no fee)',
            'config'   => [
                'subtotal' => 100.00,
            ],
        ],
        [
            'name'     => 'Order with fixed discount only',
            'config'   => [
                'subtotal'      => 100.00,
                'discount'      => 15.00,
                'discount_type' => 'fixed',
            ],
        ],
        [
            'name'     => 'Order with percentage discount only',
            'config'   => [
                'subtotal'      => 100.00,
                'discount'      => 10, // 10%
                'discount_type' => 'percent',
            ],
        ],
        [
            'name'     => 'Order with shipping only',
            'config'   => [
                'subtotal' => 100.00,
                'shipping' => 12.50,
            ],
        ],
        [
            'name'     => 'Order with bank fee only',
            'config'   => [
                'subtotal'   => 100.00,
                'fee_name'   => 'Bank Processing Fee',
                'fee_amount' => 3.50,
            ],
        ],

        // Combined scenarios.
        [
            'name'     => 'Order with fixed discount + shipping',
            'config'   => [
                'subtotal'      => 150.00,
                'discount'      => 20.00,
                'discount_type' => 'fixed',
                'shipping'      => 10.00,
            ],
        ],
        [
            'name'     => 'Order with percentage discount + shipping',
            'config'   => [
                'subtotal'      => 200.00,
                'discount'      => 15, // 15%
                'discount_type' => 'percent',
                'shipping'      => 15.00,
            ],
        ],
        [
            'name'     => 'Order with fixed discount + bank fee',
            'config'   => [
                'subtotal'      => 100.00,
                'discount'      => 10.00,
                'discount_type' => 'fixed',
                'fee_name'      => 'Payment Processing Fee',
                'fee_amount'    => 2.50,
            ],
        ],
        [
            'name'     => 'Order with percentage discount + bank fee',
            'config'   => [
                'subtotal'      => 100.00,
                'discount'      => 20, // 20%
                'discount_type' => 'percent',
                'fee_name'      => 'Bank Fee',
                'fee_amount'    => 1.95,
            ],
        ],
        [
            'name'     => 'Order with shipping + bank fee (no discount)',
            'config'   => [
                'subtotal'   => 75.00,
                'shipping'   => 8.00,
                'fee_name'   => 'Transaction Fee',
                'fee_amount' => 2.25,
            ],
        ],

        // Full combinations.
        [
            'name'     => 'Order with fixed discount + shipping + bank fee',
            'config'   => [
                'subtotal'      => 200.00,
                'discount'      => 25.00,
                'discount_type' => 'fixed',
                'shipping'      => 12.00,
                'fee_name'      => 'Payment Gateway Fee',
                'fee_amount'    => 4.00,
            ],
        ],
        [
            'name'     => 'Order with percentage discount + shipping + bank fee',
            'config'   => [
                'subtotal'      => 250.00,
                'discount'      => 10, // 10%
                'discount_type' => 'percent',
                'shipping'      => 20.00,
                'fee_name'      => 'Processing Fee',
                'fee_amount'    => 5.00,
            ],
        ],

        // Edge cases.
        [
            'name'     => 'Order with large percentage discount (50%)',
            'config'   => [
                'subtotal'      => 100.00,
                'discount'      => 50, // 50%
                'discount_type' => 'percent',
                'shipping'      => 5.00,
            ],
        ],
        [
            'name'     => 'Order with negative fee (credit)',
            'config'   => [
                'subtotal'   => 100.00,
                'fee_name'   => 'Credit Applied',
                'fee_amount' => -10.00,
            ],
        ],
        [
            'name'     => 'Order with small values (precision test)',
            'config'   => [
                'subtotal'      => 9.99,
                'discount'      => 0.99,
                'discount_type' => 'fixed',
                'shipping'      => 1.99,
                'fee_name'      => 'Small Fee',
                'fee_amount'    => 0.49,
            ],
        ],
    ];

    $created_orders = [];

    foreach ( $scenarios as $scenario ) {
        $order = zbooks_create_test_order( $scenario['config'] );

        if ( is_wp_error( $order ) ) {
            WP_CLI::warning( sprintf( 'Failed to create: %s - %s', $scenario['name'], $order->get_error_message() ) );
            continue;
        }

        $created_orders[] = [
            'name'     => $scenario['name'],
            'order_id' => $order->get_id(),
            'total'    => $order->get_total(),
            'discount' => $order->get_discount_total(),
            'shipping' => $order->get_shipping_total(),
            'fees'     => $order->get_total_fees(),
        ];

        WP_CLI::log( sprintf(
            'Created order #%d: %s (Total: $%.2f, Discount: $%.2f, Shipping: $%.2f, Fees: $%.2f)',
            $order->get_id(),
            $scenario['name'],
            $order->get_total(),
            $order->get_discount_total(),
            $order->get_shipping_total(),
            $order->get_total_fees()
        ) );
    }

    return $created_orders;
}

// Run if executed directly.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::log( 'Creating test orders for discount scenarios...' );
    WP_CLI::log( '================================================' );

    $orders = zbooks_create_all_test_orders();

    WP_CLI::log( '' );
    WP_CLI::log( '================================================' );
    WP_CLI::success( sprintf( 'Created %d test orders.', count( $orders ) ) );
    WP_CLI::log( '' );
    WP_CLI::log( 'To sync these orders, go to the Bulk Sync page:' );
    WP_CLI::log( admin_url( 'admin.php?page=zbooks-bulk-sync' ) );
}
