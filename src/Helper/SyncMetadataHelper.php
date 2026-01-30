<?php
/**
 * Sync Metadata Helper
 *
 * Generates sync metadata comments for Zoho Books entities.
 *
 * @package    Zbooks
 * @subpackage Helper
 * @since      1.0.0
 */

namespace Zbooks\Helper;

use WC_Order;

/**
 * Helper class for generating sync metadata comments.
 *
 * @since 1.0.0
 */
class SyncMetadataHelper {

	/**
	 * Generate a formatted sync comment with metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order|null $order   Optional. WooCommerce order object.
	 * @param string        $context Optional. Sync context (e.g., 'invoice', 'payment', 'refund'). Default 'sync'.
	 * @return string Formatted sync comment.
	 */
	public static function generate_sync_comment( ?WC_Order $order = null, string $context = 'sync' ): string {
		// Get site name.
		$site_name = get_bloginfo( 'name' );
		if ( empty( $site_name ) ) {
			$site_name = get_site_url();
		}

		// Get user/trigger information.
		$trigger = self::detect_trigger_context();

		// Get plugin version.
		$plugin_version = defined( 'ZBOOKS_VERSION' ) ? ZBOOKS_VERSION : '1.0.0';

		// Get formatted timestamp.
		$timestamp = wp_date( 'Y-m-d H:i:s T' );

		// Format the sync comment.
		$comment = sprintf(
			'Synced from %s by %s via ZBooks for WooCommerce v%s on %s',
			$site_name,
			$trigger,
			$plugin_version,
			$timestamp
		);

		return $comment;
	}

	/**
	 * Detect the trigger context for the sync operation.
	 *
	 * @since 1.0.0
	 *
	 * @return string Trigger context description.
	 */
	private static function detect_trigger_context(): string {
		// Check for bulk sync.
		if ( defined( 'ZBOOKS_BULK_SYNC' ) && ZBOOKS_BULK_SYNC ) {
			return 'Bulk Sync';
		}

		// Check for retry sync.
		if ( defined( 'ZBOOKS_RETRY_SYNC' ) && ZBOOKS_RETRY_SYNC ) {
			return 'Retry Sync';
		}

		// Check for cron.
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'Cron Job';
		}

		// Check for AJAX.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$current_user = wp_get_current_user();
			if ( $current_user && $current_user->ID > 0 ) {
				return sprintf( 'AJAX (%s)', $current_user->display_name );
			}
			return 'AJAX';
		}

		// Check for current user.
		$current_user = wp_get_current_user();
		if ( $current_user && $current_user->ID > 0 ) {
			return sprintf( 'User: %s', $current_user->display_name );
		}

		// Default to automatic trigger.
		return 'Automatic Trigger';
	}

	/**
	 * Append sync metadata to existing notes.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $existing_notes Existing notes content.
	 * @param WC_Order|null $order          Optional. WooCommerce order object.
	 * @param string        $context        Optional. Sync context. Default 'sync'.
	 * @return string Notes with appended sync metadata.
	 */
	public static function append_to_notes( string $existing_notes, ?WC_Order $order = null, string $context = 'sync' ): string {
		$sync_comment = self::generate_sync_comment( $order, $context );

		if ( ! empty( $existing_notes ) ) {
			return $existing_notes . "\n\n" . $sync_comment;
		}

		return $sync_comment;
	}
}
