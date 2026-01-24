<?php
/**
 * Base test case class for ZBooks for WooCommerce tests.
 *
 * Provides common setup, teardown, and utility methods
 * for all test classes.
 *
 * @package Zbooks
 * @subpackage Tests
 */

declare(strict_types=1);

namespace Zbooks\Tests;

use WP_UnitTestCase;

/**
 * Base test case for ZBooks for WooCommerce tests.
 *
 * Extends WP_UnitTestCase to provide WordPress testing functionality
 * with additional helpers specific to ZBooks for WooCommerce.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 *
	 * Called before each test method.
	 */
	public function set_up(): void {
		parent::set_up();

		// Reset any plugin state.
		$this->reset_plugin_state();
	}

	/**
	 * Tear down test fixtures.
	 *
	 * Called after each test method.
	 */
	public function tear_down(): void {
		// Clean up any test data.
		$this->cleanup_test_data();

		parent::tear_down();
	}

	/**
	 * Reset plugin state between tests.
	 *
	 * Clears caches, resets singletons, etc.
	 */
	protected function reset_plugin_state(): void {
		// Override in child classes if needed.
	}

	/**
	 * Clean up test data.
	 *
	 * Removes any data created during tests.
	 */
	protected function cleanup_test_data(): void {
		// Override in child classes if needed.
	}

	/**
	 * Assert that a WooCommerce order has a specific status.
	 *
	 * @param string   $expected_status Expected order status.
	 * @param \WC_Order $order          WooCommerce order object.
	 * @param string   $message         Optional assertion message.
	 */
	protected function assertOrderStatus( string $expected_status, $order, string $message = '' ): void {
		$this->assertSame(
			$expected_status,
			$order->get_status(),
			$message ?: "Expected order status to be '{$expected_status}', got '{$order->get_status()}'"
		);
	}

	/**
	 * Create a test WooCommerce product.
	 *
	 * @param array $args Product arguments.
	 * @return \WC_Product
	 */
	protected function create_test_product( array $args = [] ): \WC_Product {
		$defaults = [
			'name'          => 'Test Product',
			'regular_price' => '10.00',
			'status'        => 'publish',
		];

		$args    = wp_parse_args( $args, $defaults );
		$product = new \WC_Product_Simple();

		$product->set_name( $args['name'] );
		$product->set_regular_price( $args['regular_price'] );
		$product->set_status( $args['status'] );
		$product->save();

		return $product;
	}

	/**
	 * Create a test WooCommerce order.
	 *
	 * @param array $args Order arguments.
	 * @return \WC_Order
	 */
	protected function create_test_order( array $args = [] ): \WC_Order {
		$defaults = [
			'status'         => 'pending',
			'customer_id'    => 0,
			'payment_method' => 'bacs',
		];

		$args  = wp_parse_args( $args, $defaults );
		$order = wc_create_order( $args );

		return $order;
	}

	/**
	 * Skip test if WooCommerce is not available.
	 */
	protected function skip_if_no_woocommerce(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available.' );
		}
	}
}
