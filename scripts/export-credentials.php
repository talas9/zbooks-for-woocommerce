<?php
/**
 * Export Zoho Books credentials from WordPress to environment variables format.
 *
 * This script reads the Zoho API credentials from WordPress and outputs them
 * in a format that can be copied to a .env.local file.
 *
 * Usage:
 *   wp eval-file scripts/export-credentials.php
 *
 * Note: This exposes sensitive credentials! Only use for local development.
 *
 * @package Zbooks
 */

// Exit if not running in WP-CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script must be run via WP-CLI.\n";
	exit( 1 );
}

// Ensure plugin is loaded.
if ( ! class_exists( 'Zbooks\\Api\\TokenManager' ) ) {
	WP_CLI::error( 'ZBooks for WooCommerce plugin not found or not activated.' );
}

try {
	// Get the TokenManager instance.
	$plugin = \Zbooks\Plugin::get_instance();
	$token_manager = $plugin->get_service( 'token_manager' );

	$credentials = $token_manager->get_credentials();

	if ( ! $credentials || empty( $credentials['client_id'] ) ) {
		WP_CLI::error( 'No Zoho credentials found. Please configure the plugin first.' );
	}

	$organization_id = get_option( 'zbooks_organization_id', '' );
	$datacenter = get_option( 'zbooks_datacenter', 'us' );

	// Output in .env format
	echo "\n# Copy the following to your .env.local file:\n\n";
	echo "ZOHO_CLIENT_ID=" . $credentials['client_id'] . "\n";
	echo "ZOHO_CLIENT_SECRET=" . $credentials['client_secret'] . "\n";
	echo "ZOHO_REFRESH_TOKEN=" . $credentials['refresh_token'] . "\n";
	echo "ZOHO_ORGANIZATION_ID=" . $organization_id . "\n";
	echo "ZOHO_DATACENTER=" . $datacenter . "\n";
	echo "\n";

	WP_CLI::success( 'Credentials exported. Copy the output above to .env.local' );

} catch ( Exception $e ) {
	WP_CLI::error( 'Error: ' . $e->getMessage() );
}
