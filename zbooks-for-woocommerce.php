<?php
/**
 * Plugin Name: ZBooks for WooCommerce
 * Plugin URI: https://github.com/talas9/zbooks-for-woocommerce
 * Description: Sync WooCommerce orders to Zoho Books automatically or manually.
 * Version: 1.0.6
 * Author: talas9
 * Author URI: https://github.com/talas9
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zbooks-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * WC requires at least: 10.4
 * WC tested up to: 10.4.3
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks;

defined( 'ABSPATH' ) || exit;

define( 'ZBOOKS_VERSION', '1.0.6' );
define( 'ZBOOKS_PLUGIN_FILE', __FILE__ );
define( 'ZBOOKS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZBOOKS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZBOOKS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload dependencies.
 */
if ( file_exists( ZBOOKS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once ZBOOKS_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function zbooks_is_woocommerce_active(): bool {
	return class_exists( 'WooCommerce' );
}

/**
 * Display admin notice if WooCommerce is not active.
 */
function zbooks_woocommerce_missing_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'ZBooks for WooCommerce requires WooCommerce to be installed and activated.',
				'zbooks-for-woocommerce'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Load plugin textdomain for translations.
 */
function zbooks_load_textdomain(): void {
	load_plugin_textdomain(
		'zbooks-for-woocommerce',
		false,
		dirname( ZBOOKS_PLUGIN_BASENAME ) . '/languages'
	);
}

add_action( 'init', 'Zbooks\zbooks_load_textdomain' );

/**
 * Initialize the plugin.
 */
function zbooks_init(): void {
	if ( ! zbooks_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'Zbooks\zbooks_woocommerce_missing_notice' );
		return;
	}

	// Check for plugin upgrade.
	zbooks_maybe_upgrade();

	Plugin::get_instance();
}

/**
 * Check if plugin was upgraded and run upgrade routines.
 */
function zbooks_maybe_upgrade(): void {
	$installed_version = get_option( 'zbooks_version', '0' );

	if ( version_compare( $installed_version, ZBOOKS_VERSION, '<' ) ) {
		// Run upgrade routines.
		zbooks_upgrade( $installed_version );

		// Update stored version.
		update_option( 'zbooks_version', ZBOOKS_VERSION );
	}
}

/**
 * Run upgrade routines based on version.
 *
 * @param string $from_version Version upgrading from.
 */
function zbooks_upgrade( string $from_version ): void {
	// Ensure cron job is scheduled.
	if ( ! wp_next_scheduled( 'zbooks_retry_failed_syncs' ) ) {
		wp_schedule_event( time(), 'fifteen_minutes', 'zbooks_retry_failed_syncs' );
	}

	// Ensure database tables exist.
	$reconciliation_repo = new Repository\ReconciliationRepository();
	$reconciliation_repo->create_table();

	// Clear any cached admin menu.
	delete_transient( 'zbooks_admin_menu_cache' );

	// Flush rewrite rules on upgrade.
	flush_rewrite_rules();
}

add_action( 'plugins_loaded', 'Zbooks\zbooks_init' );

/**
 * Activation hook.
 */
function zbooks_activate(): void {
	if ( ! zbooks_is_woocommerce_active() ) {
		deactivate_plugins( ZBOOKS_PLUGIN_BASENAME );
		wp_die(
			esc_html__(
				'ZBooks for WooCommerce requires WooCommerce to be installed and activated.',
				'zbooks-for-woocommerce'
			),
			'Plugin Activation Error',
			[ 'back_link' => true ]
		);
	}

	// Schedule cron job for retrying failed syncs.
	if ( ! wp_next_scheduled( 'zbooks_retry_failed_syncs' ) ) {
		wp_schedule_event( time(), 'fifteen_minutes', 'zbooks_retry_failed_syncs' );
	}

	// Set transient to trigger setup wizard redirect.
	set_transient( 'zbooks_activation_redirect', true, 30 );

	// Create reconciliation reports table.
	$reconciliation_repo = new Repository\ReconciliationRepository();
	$reconciliation_repo->create_table();

	// Set default options.
	$default_options = [
		'zbooks_sync_triggers'           => [
			'sync_draft'        => 'processing',
			'sync_submit'       => 'completed',
			'create_creditnote' => 'refunded',
		],
		'zbooks_refund_settings'         => [
			'create_cash_refund' => true,
		],
		'zbooks_retry_settings'          => [
			'mode'            => 'max_retries',
			'max_count'       => 5,
			'backoff_minutes' => 15,
		],
		'zbooks_reconciliation_settings' => [
			'enabled'                   => false, // Disabled by default.
			'frequency'                 => 'weekly',
			'day_of_week'               => 1,
			'day_of_month'              => 1,
			'amount_tolerance'          => 0.05,
			'email_enabled'             => false,
			'email_on_discrepancy_only' => true,
			'email_address'             => get_option( 'admin_email' ),
		],
	];

	foreach ( $default_options as $option_name => $default_value ) {
		if ( get_option( $option_name ) === false ) {
			add_option( $option_name, $default_value );
		}
	}

	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'Zbooks\zbooks_activate' );

/**
 * Deactivation hook.
 */
function zbooks_deactivate(): void {
	// Clear scheduled cron job.
	$timestamp = wp_next_scheduled( 'zbooks_retry_failed_syncs' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'zbooks_retry_failed_syncs' );
	}

	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'Zbooks\zbooks_deactivate' );

/**
 * Add custom cron schedule for 15 minutes.
 *
 * @param array $schedules Existing cron schedules.
 * @return array Modified cron schedules.
 */
function zbooks_add_cron_interval( array $schedules ): array {
	$schedules['fifteen_minutes'] = [
		'interval' => 900,
		'display'  => esc_html__( 'Every 15 Minutes', 'zbooks-for-woocommerce' ),
	];
	return $schedules;
}

add_filter( 'cron_schedules', 'Zbooks\zbooks_add_cron_interval' );

/**
 * Declare HPOS compatibility.
 */
add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);
