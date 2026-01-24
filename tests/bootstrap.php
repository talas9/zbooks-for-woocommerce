<?php
/**
 * PHPUnit bootstrap file for ZBooks for WooCommerce plugin tests.
 *
 * This file sets up the WordPress test environment, loads WooCommerce,
 * and initializes the ZBooks for WooCommerce plugin for testing.
 *
 * @package Zbooks
 * @subpackage Tests
 */

declare(strict_types=1);

// Define test constants.
define( 'ZBOOKS_TESTS_DIR', __DIR__ );
define( 'ZBOOKS_PLUGIN_DIR', dirname( __DIR__ ) );

// Composer autoloader.
$autoloader = ZBOOKS_PLUGIN_DIR . '/vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
}

/**
 * Determine the WordPress tests directory.
 *
 * Priority:
 * 1. WP_TESTS_DIR environment variable
 * 2. WP_DEVELOP_DIR environment variable + /tests/phpunit/
 * 3. Default system path: /tmp/wordpress-tests-lib
 *
 * @return string Path to WordPress tests directory.
 */
function zbooks_get_wp_tests_dir(): string {
	$tests_dir = getenv( 'WP_TESTS_DIR' );

	if ( ! $tests_dir ) {
		$wp_develop_dir = getenv( 'WP_DEVELOP_DIR' );
		if ( $wp_develop_dir ) {
			$tests_dir = $wp_develop_dir . '/tests/phpunit';
		}
	}

	if ( ! $tests_dir ) {
		$tests_dir = '/tmp/wordpress-tests-lib';
	}

	return rtrim( $tests_dir, '/' );
}

/**
 * Determine WooCommerce plugin path.
 *
 * Priority:
 * 1. WC_ABSPATH environment variable
 * 2. WordPress plugins directory
 *
 * @return string|null Path to WooCommerce or null if not found.
 */
function zbooks_get_woocommerce_dir(): ?string {
	$wc_dir = getenv( 'WC_ABSPATH' );

	if ( $wc_dir && file_exists( $wc_dir ) ) {
		return rtrim( $wc_dir, '/' );
	}

	// Try to find WooCommerce in the plugins directory.
	$wp_plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : null;
	if ( $wp_plugins_dir && file_exists( $wp_plugins_dir . '/woocommerce/woocommerce.php' ) ) {
		return $wp_plugins_dir . '/woocommerce';
	}

	return null;
}

// Get the WordPress tests directory.
$wp_tests_dir = zbooks_get_wp_tests_dir();

// Check if WordPress test suite exists.
if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	echo "Error: Could not find WordPress test suite at {$wp_tests_dir}" . PHP_EOL;
	echo 'Please set the WP_TESTS_DIR or WP_DEVELOP_DIR environment variable.' . PHP_EOL;
	echo PHP_EOL;
	echo 'To install the WordPress test suite, run:' . PHP_EOL;
	echo '  bash bin/install-wp-tests.sh <db-name> <db-user> <db-password> [db-host] [wp-version]' . PHP_EOL;
	exit( 1 );
}

// Load WordPress test functions.
require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce and ZBooks for WooCommerce before WordPress loads.
 *
 * This function is hooked to 'muplugins_loaded' to ensure plugins
 * are loaded before WordPress runs its setup.
 */
function zbooks_manually_load_plugins(): void {
	// Load WooCommerce if available.
	$wc_dir = zbooks_get_woocommerce_dir();
	if ( $wc_dir && file_exists( $wc_dir . '/woocommerce.php' ) ) {
		define( 'WC_ABSPATH', $wc_dir . '/' );
		require_once $wc_dir . '/woocommerce.php';

		// Initialize WooCommerce.
		WC()->init();
	} else {
		echo 'Warning: WooCommerce not found. Some tests may be skipped.' . PHP_EOL;
	}

	// Load ZBooks for WooCommerce plugin.
	$plugin_file = ZBOOKS_PLUGIN_DIR . '/zbooks-for-woocommerce.php';
	if ( file_exists( $plugin_file ) ) {
		require_once $plugin_file;
	} else {
		echo 'Warning: ZBooks for WooCommerce plugin file not found at ' . $plugin_file . PHP_EOL;
	}
}

tests_add_filter( 'muplugins_loaded', 'zbooks_manually_load_plugins' );

/**
 * Setup WooCommerce test environment.
 *
 * Configures WooCommerce for testing after WordPress has loaded.
 */
function zbooks_setup_woocommerce_environment(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	// Set up WooCommerce pages.
	update_option( 'woocommerce_shop_page_id', 1 );
	update_option( 'woocommerce_cart_page_id', 2 );
	update_option( 'woocommerce_checkout_page_id', 3 );
	update_option( 'woocommerce_myaccount_page_id', 4 );

	// Set base location.
	update_option( 'woocommerce_default_country', 'US:CA' );

	// Enable taxes for testing.
	update_option( 'woocommerce_calc_taxes', 'yes' );

	// Set currency.
	update_option( 'woocommerce_currency', 'USD' );

	// Disable WooCommerce setup wizard.
	update_option( 'woocommerce_admin_notices', array() );

	// Load WooCommerce test helpers if available.
	$wc_tests_dir = zbooks_get_woocommerce_dir() . '/tests';
	if ( file_exists( $wc_tests_dir . '/legacy/framework/class-wc-unit-test-case.php' ) ) {
		require_once $wc_tests_dir . '/legacy/framework/class-wc-unit-test-case.php';
	}
}

tests_add_filter( 'setup_theme', 'zbooks_setup_woocommerce_environment' );

/**
 * Install ZBooks for WooCommerce test fixtures.
 *
 * Creates database tables and initial data needed for testing.
 */
function zbooks_install_test_fixtures(): void {
	// Trigger plugin activation hook if needed.
	if ( function_exists( 'zbooks_activate' ) ) {
		zbooks_activate();
	}

	// Flush rewrite rules.
	flush_rewrite_rules();
}

tests_add_filter( 'init', 'zbooks_install_test_fixtures', 999 );

// Start up the WP testing environment.
require $wp_tests_dir . '/includes/bootstrap.php';

// Load test case base classes.
require_once ZBOOKS_TESTS_DIR . '/TestCase.php';

// Load test utilities and helpers.
$test_helpers = ZBOOKS_TESTS_DIR . '/Helpers';
if ( is_dir( $test_helpers ) ) {
	foreach ( glob( $test_helpers . '/*.php' ) as $helper_file ) {
		require_once $helper_file;
	}
}
