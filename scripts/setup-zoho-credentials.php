<?php
/**
 * Setup Zoho Books credentials from environment variables.
 *
 * This script reads Zoho API credentials from environment variables
 * and stores them in WordPress options using the plugin's TokenManager.
 *
 * Usage:
 *   wp eval-file scripts/setup-zoho-credentials.php
 *
 * Environment variables:
 *   ZOHO_CLIENT_ID       - Zoho API client ID
 *   ZOHO_CLIENT_SECRET   - Zoho API client secret
 *   ZOHO_REFRESH_TOKEN   - Zoho OAuth refresh token
 *   ZOHO_ORGANIZATION_ID - Zoho Books organization ID
 *   ZOHO_DATACENTER      - Zoho datacenter (us, eu, in, au, jp)
 *
 * @package Zbooks
 */

// Exit if not running in WP-CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script must be run via WP-CLI.\n";
	exit( 1 );
}

// Get environment variables.
$client_id       = getenv( 'ZOHO_CLIENT_ID' );
$client_secret   = getenv( 'ZOHO_CLIENT_SECRET' );
$refresh_token   = getenv( 'ZOHO_REFRESH_TOKEN' );
$organization_id = getenv( 'ZOHO_ORGANIZATION_ID' );
$datacenter      = getenv( 'ZOHO_DATACENTER' ) ?: 'us';

// Validate required variables.
$missing = [];
if ( empty( $client_id ) ) {
	$missing[] = 'ZOHO_CLIENT_ID';
}
if ( empty( $client_secret ) ) {
	$missing[] = 'ZOHO_CLIENT_SECRET';
}
if ( empty( $refresh_token ) ) {
	$missing[] = 'ZOHO_REFRESH_TOKEN';
}
if ( empty( $organization_id ) ) {
	$missing[] = 'ZOHO_ORGANIZATION_ID';
}

if ( ! empty( $missing ) ) {
	WP_CLI::warning( 'Missing environment variables: ' . implode( ', ', $missing ) );
	WP_CLI::log( 'Zoho credentials not configured. Set the environment variables and run this script again.' );
	exit( 0 ); // Exit without error to not break CI.
}

// Ensure plugin is loaded.
if ( ! class_exists( 'Zbooks\\Api\\TokenManager' ) ) {
	// Try to load the plugin.
	$plugin_file = dirname( __DIR__ ) . '/zbooks-for-woocommerce.php';
	if ( file_exists( $plugin_file ) ) {
		require_once $plugin_file;
	} else {
		WP_CLI::error( 'ZBooks for WooCommerce plugin not found.' );
	}
}

// Get the TokenManager instance.
try {
	// Use the plugin's container if available.
	if ( class_exists( 'Zbooks\\Plugin' ) ) {
		$plugin = \Zbooks\Plugin::get_instance();
		$token_manager = $plugin->get_service( 'token_manager' );
	} else {
		// Fallback: create TokenManager directly.
		$token_manager = new \Zbooks\Api\TokenManager();
	}

	// Save credentials.
	$result = $token_manager->save_credentials( $client_id, $client_secret, $refresh_token );

	if ( $result ) {
		WP_CLI::success( 'Zoho API credentials saved successfully.' );
	} else {
		WP_CLI::error( 'Failed to save Zoho API credentials.' );
	}

	// Save organization ID and datacenter.
	update_option( 'zbooks_organization_id', $organization_id );
	update_option( 'zbooks_datacenter', $datacenter );

	WP_CLI::success( "Organization ID: {$organization_id}" );
	WP_CLI::success( "Datacenter: {$datacenter}" );

	// Test the connection.
	WP_CLI::log( 'Testing connection...' );

	try {
		$zoho_client = $plugin->get_service( 'zoho_client' );
		$connected = $zoho_client->test_connection();

		if ( $connected ) {
			WP_CLI::success( 'Connection test successful!' );
		} else {
			WP_CLI::warning( 'Connection test failed. Please verify your credentials.' );
		}
	} catch ( Exception $e ) {
		WP_CLI::warning( 'Connection test error: ' . $e->getMessage() );
	}

} catch ( Exception $e ) {
	WP_CLI::error( 'Error: ' . $e->getMessage() );
}
