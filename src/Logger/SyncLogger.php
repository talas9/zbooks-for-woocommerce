<?php
/**
 * Sync logger.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Logger;

use Zbooks\Service\EmailTemplateService;
use Zbooks\Service\NotificationQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Logger for sync operations.
 */
class SyncLogger {

	/**
	 * Log file path.
	 *
	 * @var string
	 */
	private string $log_file;

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private string $log_dir_path;

	/**
	 * Log settings.
	 *
	 * @var array
	 */
	private array $settings;

	/**
	 * Email template service.
	 *
	 * @var EmailTemplateService|null
	 */
	private ?EmailTemplateService $email_service = null;

	/**
	 * Notification queue service.
	 *
	 * @var NotificationQueue|null
	 */
	private ?NotificationQueue $notification_queue = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'] ?? '';

		// Fallback if basedir is empty or null.
		if ( empty( $basedir ) ) {
			$basedir = WP_CONTENT_DIR . '/uploads';
		}

		$this->log_dir_path = $basedir . '/zbooks-logs';

		if ( ! file_exists( $this->log_dir_path ) ) {
			wp_mkdir_p( $this->log_dir_path );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $this->log_dir_path . '/.htaccess', 'deny from all' );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $this->log_dir_path . '/index.php', '<?php // Silence is golden' );
		}

		$this->log_file = $this->log_dir_path . '/sync-' . gmdate( 'Y-m-d' ) . '.log';
		$this->settings = $this->get_settings();
	}

	/**
	 * Get log settings with defaults.
	 *
	 * @return array
	 */
	private function get_settings(): array {
		$defaults = [
			'retention_days'   => 30,
			'max_file_size_mb' => 10,
		];

		$saved = get_option( 'zbooks_log_settings', [] );
		return array_merge( $defaults, $saved );
	}

	/**
	 * Get notification settings with defaults.
	 *
	 * @return array
	 */
	private function get_notification_settings(): array {
		$defaults = [
			'email' => get_option( 'admin_email' ),
			'types' => [
				'sync_errors' => false,
			],
		];

		// Check for old settings and migrate.
		$old_settings = get_option( 'zbooks_log_settings', [] );
		if ( ! empty( $old_settings['email_on_error'] ) ) {
			$defaults['types']['sync_errors'] = true;
		}
		if ( ! empty( $old_settings['error_email'] ) ) {
			$defaults['email'] = $old_settings['error_email'];
		}

		$saved = get_option( 'zbooks_notification_settings', [] );
		return array_replace_recursive( $defaults, $saved );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function info( string $message, array $context = [] ): void {
		$this->log( 'INFO', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function error( string $message, array $context = [] ): void {
		$this->log( 'ERROR', $message, $context );
		$this->maybe_send_error_email( $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function warning( string $message, array $context = [] ): void {
		$this->log( 'WARNING', $message, $context );
		$this->maybe_queue_warning( $message, $context );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function debug( string $message, array $context = [] ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->log( 'DEBUG', $message, $context );
		}
	}

	/**
	 * Write log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	private function log( string $level, string $message, array $context ): void {
		// Check and rotate if needed.
		$this->maybe_rotate_log();

		$timestamp      = gmdate( 'Y-m-d H:i:s' );
		$context_string = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';

		$log_entry = sprintf(
			"[%s] [%s] %s%s\n",
			$timestamp,
			$level,
			$message,
			$context_string
		);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $log_entry, FILE_APPEND | LOCK_EX );

		// Also log to WP debug log if enabled.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[ZBooks for WooCommerce] [%s] %s%s', $level, $message, $context_string ) );
		}
	}

	/**
	 * Rotate log file if it exceeds max size.
	 */
	private function maybe_rotate_log(): void {
		if ( ! file_exists( $this->log_file ) ) {
			return;
		}

		$max_bytes    = $this->settings['max_file_size_mb'] * 1024 * 1024;
		$current_size = filesize( $this->log_file );

		if ( $current_size < $max_bytes ) {
			return;
		}

		// Rename current file to .1 backup
		$backup_file = $this->log_file . '.1';
		if ( file_exists( $backup_file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $backup_file );
		}

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		rename( $this->log_file, $backup_file );
	}

	/**
	 * Queue error notification if enabled.
	 *
	 * @param string $message Error message.
	 * @param array  $context Error context.
	 */
	private function maybe_send_error_email( string $message, array $context ): void {
		// Build a descriptive title from context.
		$title = __( 'Sync Error', 'zbooks-for-woocommerce' );
		if ( ! empty( $context['order_id'] ) ) {
			$title = sprintf(
				/* translators: %d: Order ID */
				__( 'Sync Error - Order #%d', 'zbooks-for-woocommerce' ),
				$context['order_id']
			);
		}

		// Add to notification queue (queue handles enabled check and batching).
		$context['notification_key'] = 'sync_errors';
		$this->get_notification_queue()->queue( 'error', $title, $message, $context );
	}

	/**
	 * Queue warning notification if enabled.
	 *
	 * @param string $message Warning message.
	 * @param array  $context Warning context.
	 */
	private function maybe_queue_warning( string $message, array $context ): void {
		// Build a descriptive title from context.
		$title = __( 'Sync Warning', 'zbooks-for-woocommerce' );
		if ( ! empty( $context['order_id'] ) ) {
			$title = sprintf(
				/* translators: %d: Order ID */
				__( 'Warning - Order #%d', 'zbooks-for-woocommerce' ),
				$context['order_id']
			);
		}

		// Determine notification key based on context.
		$notification_key = 'warnings';
		if ( ! empty( $context['currency_mismatch'] ) ) {
			$notification_key = 'currency_mismatches';
		}

		// Add to notification queue (queue handles enabled check and batching).
		$context['notification_key'] = $notification_key;
		$this->get_notification_queue()->queue( 'warning', $title, $message, $context );
	}

	/**
	 * Get the email template service instance.
	 *
	 * @return EmailTemplateService
	 */
	private function get_email_service(): EmailTemplateService {
		if ( $this->email_service === null ) {
			$this->email_service = new EmailTemplateService();
		}
		return $this->email_service;
	}

	/**
	 * Get the notification queue instance.
	 *
	 * @return NotificationQueue
	 */
	private function get_notification_queue(): NotificationQueue {
		if ( $this->notification_queue === null ) {
			$this->notification_queue = new NotificationQueue( $this->get_email_service() );
		}
		return $this->notification_queue;
	}

	/**
	 * Get log file path.
	 *
	 * @return string
	 */
	public function get_log_file(): string {
		return $this->log_file;
	}

	/**
	 * Get log directory path.
	 *
	 * @return string
	 */
	public function get_log_dir(): string {
		return $this->log_dir_path;
	}

	/**
	 * Get configured retention days.
	 *
	 * @return int
	 */
	public function get_retention_days(): int {
		return (int) $this->settings['retention_days'];
	}

	/**
	 * Get list of available log files.
	 *
	 * @return array List of log file info with name and date.
	 */
	public function get_log_files(): array {
		$log_dir = $this->get_log_dir();
		$files   = [];

		if ( ! is_dir( $log_dir ) ) {
			return $files;
		}

		$glob = glob( $log_dir . '/sync-*.log' );
		if ( $glob === false ) {
			return $files;
		}

		foreach ( $glob as $file ) {
			$filename = basename( $file );
			if ( preg_match( '/sync-(\d{4}-\d{2}-\d{2})\.log/', $filename, $matches ) ) {
				$files[] = [
					'path'     => $file,
					'filename' => $filename,
					'date'     => $matches[1],
					'size'     => filesize( $file ),
				];
			}
		}

		// Sort by date descending.
		usort(
			$files,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		return $files;
	}

	/**
	 * Read log entries from a file.
	 *
	 * @param string $date    Date in YYYY-MM-DD format.
	 * @param int    $limit   Max entries to return.
	 * @param string $level   Filter by log level (optional).
	 * @return array Parsed log entries.
	 */
	public function read_log( string $date, int $limit = 100, string $level = '' ): array {
		$log_dir = $this->get_log_dir();
		$file    = $log_dir . '/sync-' . $date . '.log';

		if ( ! file_exists( $file ) ) {
			return [];
		}

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file );
		if ( $content === false ) {
			return [];
		}

		$lines   = explode( "\n", $content );
		$entries = [];

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$entry = $this->parse_log_line( $line );
			if ( $entry === null ) {
				continue;
			}

			if ( ! empty( $level ) && $entry['level'] !== $level ) {
				continue;
			}

			$entries[] = $entry;
		}

		// Return most recent first.
		$entries = array_reverse( $entries );

		if ( $limit > 0 && count( $entries ) > $limit ) {
			$entries = array_slice( $entries, 0, $limit );
		}

		return $entries;
	}

	/**
	 * Parse a single log line.
	 *
	 * @param string $line Log line.
	 * @return array|null Parsed entry or null.
	 */
	private function parse_log_line( string $line ): ?array {
		// Format: [2024-01-15 14:30:00] [INFO] Message {"context":"data"}
		if ( ! preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(\w+)\] (.+)$/', $line, $matches ) ) {
			return null;
		}

		$message = $matches[3];
		$context = [];

		// Extract JSON context if present.
		if ( preg_match( '/^(.+?) (\{.+\})$/', $message, $msg_matches ) ) {
			$message = $msg_matches[1];
			$decoded = json_decode( $msg_matches[2], true );
			if ( is_array( $decoded ) ) {
				$context = $decoded;
			}
		}

		return [
			'timestamp' => $matches[1],
			'level'     => $matches[2],
			'message'   => $message,
			'context'   => $context,
		];
	}

	/**
	 * Clear old log files.
	 *
	 * @param int $days_to_keep Number of days to keep logs.
	 * @return int Number of files deleted.
	 */
	public function clear_old_logs( int $days_to_keep = 30 ): int {
		$files   = $this->get_log_files();
		$cutoff  = gmdate( 'Y-m-d', strtotime( "-{$days_to_keep} days" ) );
		$deleted = 0;

		foreach ( $files as $file ) {
			if ( $file['date'] < $cutoff ) {
				if ( wp_delete_file( $file['path'] ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Clear all log files.
	 *
	 * @return int Number of files deleted.
	 */
	public function clear_all_logs(): int {
		$files   = $this->get_log_files();
		$deleted = 0;

		foreach ( $files as $file ) {
			if ( wp_delete_file( $file['path'] ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Get log statistics.
	 *
	 * @param string $date Date in YYYY-MM-DD format.
	 * @return array Stats with counts by level.
	 */
	public function get_stats( string $date ): array {
		$entries = $this->read_log( $date, 0 );
		$stats   = [
			'total'   => count( $entries ),
			'INFO'    => 0,
			'ERROR'   => 0,
			'WARNING' => 0,
			'DEBUG'   => 0,
		];

		foreach ( $entries as $entry ) {
			$level = $entry['level'];
			if ( isset( $stats[ $level ] ) ) {
				++$stats[ $level ];
			}
		}

		return $stats;
	}
}
