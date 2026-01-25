<?php
/**
 * ZBooks for WooCommerce Uninstall.
 *
 * Fired when the plugin is uninstalled.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up all plugin data on uninstall.
 *
 * This includes:
 * - All zbooks_ options
 * - All _zbooks_ order meta
 * - Scheduled cron events
 * - Log files
 */

// Delete all plugin options.
$zbooks_options_to_delete = [
	'zbooks_oauth_credentials',
	'zbooks_datacenter',
	'zbooks_organization_id',
	'zbooks_sync_triggers',
	'zbooks_retry_settings',
	'zbooks_access_token',
	'zbooks_token_expires',
	'zbooks_rate_limit_remaining',
	'zbooks_rate_limit_reset',
];

foreach ( $zbooks_options_to_delete as $zbooks_option ) {
	delete_option( $zbooks_option );
}

// Delete any additional zbooks_ options that may have been added.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'zbooks_%'
	)
);

// Delete order meta with _zbooks_ prefix.
// Uses direct query for performance with potentially large datasets.
$zbooks_meta_keys = [
	'_zbooks_zoho_invoice_id',
	'_zbooks_zoho_contact_id',
	'_zbooks_sync_status',
	'_zbooks_last_sync_attempt',
	'_zbooks_sync_error',
	'_zbooks_retry_count',
];

// For HPOS (High-Performance Order Storage) compatibility.
if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
	&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
) {
	// Delete from HPOS meta table.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE %s",
			'_zbooks_%'
		)
	);
} else {
	// Delete from traditional post meta table.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			'_zbooks_%'
		)
	);
}

// Clear scheduled cron events.
$zbooks_cron_hooks = [
	'zbooks_retry_failed_syncs',
];

foreach ( $zbooks_cron_hooks as $zbooks_hook ) {
	$zbooks_timestamp = wp_next_scheduled( $zbooks_hook );
	if ( $zbooks_timestamp ) {
		wp_unschedule_event( $zbooks_timestamp, $zbooks_hook );
	}

	// Also clear any other scheduled instances.
	wp_clear_scheduled_hook( $zbooks_hook );
}

// Delete log files.
$zbooks_upload_dir = wp_upload_dir();
$zbooks_log_dir    = $zbooks_upload_dir['basedir'] . '/zbooks-logs';

if ( is_dir( $zbooks_log_dir ) ) {
	// Get all files in the log directory.
	$zbooks_files = glob( $zbooks_log_dir . '/*' );

	if ( $zbooks_files !== false ) {
		foreach ( $zbooks_files as $zbooks_file ) {
			if ( is_file( $zbooks_file ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $zbooks_file );
			}
		}
	}

	// Remove the directory itself.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	rmdir( $zbooks_log_dir );
}

// Clear any transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_zbooks_%',
		'_transient_timeout_zbooks_%'
	)
);

// Flush rewrite rules on next page load.
delete_option( 'rewrite_rules' );
