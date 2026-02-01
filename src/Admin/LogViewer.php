<?php
/**
 * Log viewer admin page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Logger\SyncLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page for viewing sync logs.
 */
class LogViewer {

	/**
	 * Logger instance.
	 *
	 * @var SyncLogger
	 */
	private SyncLogger $logger;

	/**
	 * Constructor.
	 *
	 * @param SyncLogger $logger Logger instance.
	 */
	public function __construct( SyncLogger $logger ) {
		$this->logger = $logger;
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_zbooks_get_logs', [ $this, 'ajax_get_logs' ] );
		add_action( 'wp_ajax_zbooks_clear_logs', [ $this, 'ajax_clear_logs' ] );
		add_action( 'wp_ajax_zbooks_clear_all_logs', [ $this, 'ajax_clear_all_logs' ] );
	}

	/**
	 * Enqueue log viewer assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'zbooks_page_zbooks-logs' ) {
			return;
		}

		wp_enqueue_style(
			'zbooks-log-viewer',
			ZBOOKS_PLUGIN_URL . 'assets/css/modules/log-viewer.css',
			[],
			ZBOOKS_VERSION
		);

		wp_enqueue_script(
			'zbooks-log-viewer',
			ZBOOKS_PLUGIN_URL . 'assets/js/modules/log-viewer.js',
			[ 'jquery' ],
			ZBOOKS_VERSION,
			true
		);

		wp_localize_script(
			'zbooks-log-viewer',
			'zbooksLogViewer',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => [
					'get_logs'       => wp_create_nonce( 'zbooks_get_logs' ),
					'clear_logs'     => wp_create_nonce( 'zbooks_clear_logs' ),
					'clear_all_logs' => wp_create_nonce( 'zbooks_clear_all_logs' ),
				],
				'i18n'    => [
					'refreshing'      => __( 'Refreshing...', 'zbooks-for-woocommerce' ),
					'clearing'        => __( 'Clearing...', 'zbooks-for-woocommerce' ),
					'confirm_clear'   => __( 'Are you sure you want to clear old logs?', 'zbooks-for-woocommerce' ),
					'confirm_clear_all' => __( 'Are you sure you want to clear ALL logs? This cannot be undone.', 'zbooks-for-woocommerce' ),
					'copied'          => __( 'Copied!', 'zbooks-for-woocommerce' ),
				],
			]
		);
	}

	/**
	 * Add submenu page under ZBooks menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'zbooks',
			__( 'Logs', 'zbooks-for-woocommerce' ),
			__( 'Logs', 'zbooks-for-woocommerce' ),
			'manage_woocommerce',
			'zbooks-logs',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the log viewer page.
	 */
	public function render_page(): void {
		$files          = $this->logger->get_log_files();
		$selected_date  = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$selected_level = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';

		if ( empty( $selected_date ) && ! empty( $files ) ) {
			$selected_date = $files[0]['date'];
		}

		$entries = [];
		$stats   = [];
		if ( ! empty( $selected_date ) ) {
			$entries = $this->logger->read_log( $selected_date, 200, $selected_level );
			$stats   = $this->logger->get_stats( $selected_date );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ZBooks Sync Logs', 'zbooks-for-woocommerce' ); ?></h1>

			<div class="zbooks-log-controls">
				<form method="get" action="">
					<input type="hidden" name="page" value="zbooks-logs">

					<label for="date"><?php esc_html_e( 'Date:', 'zbooks-for-woocommerce' ); ?></label>
					<select name="date" id="date">
						<?php if ( empty( $files ) ) : ?>
							<option value=""><?php esc_html_e( 'No logs available', 'zbooks-for-woocommerce' ); ?></option>
						<?php else : ?>
							<?php foreach ( $files as $file ) : ?>
								<option value="<?php echo esc_attr( $file['date'] ); ?>" <?php selected( $selected_date, $file['date'] ); ?>>
									<?php echo esc_html( $file['date'] ); ?>
									(<?php echo esc_html( size_format( $file['size'] ) ); ?>)
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>

					<label for="level"><?php esc_html_e( 'Level:', 'zbooks-for-woocommerce' ); ?></label>
					<select name="level" id="level">
						<option value=""><?php esc_html_e( 'All', 'zbooks-for-woocommerce' ); ?></option>
						<option value="ERROR" <?php selected( $selected_level, 'ERROR' ); ?>>
							<?php esc_html_e( 'Errors', 'zbooks-for-woocommerce' ); ?>
						</option>
						<option value="WARNING" <?php selected( $selected_level, 'WARNING' ); ?>>
							<?php esc_html_e( 'Warnings', 'zbooks-for-woocommerce' ); ?>
						</option>
						<option value="INFO" <?php selected( $selected_level, 'INFO' ); ?>>
							<?php esc_html_e( 'Info', 'zbooks-for-woocommerce' ); ?>
						</option>
						<option value="DEBUG" <?php selected( $selected_level, 'DEBUG' ); ?>>
							<?php esc_html_e( 'Debug', 'zbooks-for-woocommerce' ); ?>
						</option>
					</select>

					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'zbooks-for-woocommerce' ); ?></button>

					<button type="button" class="button zbooks-refresh-logs">
						<?php esc_html_e( 'Refresh', 'zbooks-for-woocommerce' ); ?>
					</button>
				</form>

				<form method="post" action="">
					<?php wp_nonce_field( 'zbooks_clear_logs', 'zbooks_nonce' ); ?>
					<button type="button" class="button zbooks-clear-old-logs" data-retention-days="<?php echo esc_attr( $this->logger->get_retention_days() ); ?>">
						<?php
						$retention_days = absint( $this->logger->get_retention_days() );
						printf(
							/* translators: %d: number of days */
							esc_html__( 'Clear Old Logs (%d+ days)', 'zbooks-for-woocommerce' ),
							$retention_days
						);
						?>
					</button>
					<button type="button" class="button zbooks-clear-all-logs">
						<?php esc_html_e( 'Clear All Logs', 'zbooks-for-woocommerce' ); ?>
					</button>
				</form>
			</div>

			<?php if ( ! empty( $stats ) ) : ?>
				<div class="zbooks-log-stats" style="margin: 15px 0; padding: 10px; background: #f0f0f1; display: inline-flex; gap: 20px;">
					<span>
						<strong><?php esc_html_e( 'Total:', 'zbooks-for-woocommerce' ); ?></strong>
						<?php echo esc_html( $stats['total'] ); ?>
					</span>
					<span style="color: #d63638;">
						<strong><?php esc_html_e( 'Errors:', 'zbooks-for-woocommerce' ); ?></strong>
						<?php echo esc_html( $stats['ERROR'] ); ?>
					</span>
					<span style="color: #dba617;">
						<strong><?php esc_html_e( 'Warnings:', 'zbooks-for-woocommerce' ); ?></strong>
						<?php echo esc_html( $stats['WARNING'] ); ?>
					</span>
					<span style="color: #00a32a;">
						<strong><?php esc_html_e( 'Info:', 'zbooks-for-woocommerce' ); ?></strong>
						<?php echo esc_html( $stats['INFO'] ); ?>
					</span>
				</div>
			<?php endif; ?>

			<table class="widefat fixed striped" id="zbooks-log-table">
				<thead>
					<tr>
						<th style="width: 160px;"><?php esc_html_e( 'Timestamp', 'zbooks-for-woocommerce' ); ?></th>
						<th style="width: 80px;"><?php esc_html_e( 'Level', 'zbooks-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Message', 'zbooks-for-woocommerce' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Details', 'zbooks-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $entries ) ) : ?>
						<tr>
							<td colspan="4"><?php esc_html_e( 'No log entries found.', 'zbooks-for-woocommerce' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $entries as $index => $entry ) : ?>
							<tr class="zbooks-log-<?php echo esc_attr( strtolower( $entry['level'] ) ); ?> zbooks-log-row"
								data-entry="<?php echo esc_attr( wp_json_encode( $entry ) ); ?>">
								<td><?php echo esc_html( $entry['timestamp'] ); ?></td>
								<td>
									<span class="zbooks-log-level zbooks-level-<?php echo esc_attr( strtolower( $entry['level'] ) ); ?>">
										<?php echo esc_html( $entry['level'] ); ?>
									</span>
								</td>
								<td class="zbooks-log-message"><?php echo esc_html( $entry['message'] ); ?></td>
								<td>
									<button type="button" class="button button-small zbooks-view-details">
										<?php esc_html_e( 'View', 'zbooks-for-woocommerce' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<!-- Log Details Modal -->
		<div id="zbooks-log-modal" class="zbooks-modal" style="display: none;">
			<div class="zbooks-modal-overlay"></div>
			<div class="zbooks-modal-content">
				<div class="zbooks-modal-header">
					<h2><?php esc_html_e( 'Log Entry Details', 'zbooks-for-woocommerce' ); ?></h2>
					<button type="button" class="zbooks-modal-close">&times;</button>
				</div>
				<div class="zbooks-modal-body">
					<div class="zbooks-detail-row">
						<label><?php esc_html_e( 'Timestamp:', 'zbooks-for-woocommerce' ); ?></label>
						<span id="zbooks-modal-timestamp"></span>
					</div>
					<div class="zbooks-detail-row">
						<label><?php esc_html_e( 'Level:', 'zbooks-for-woocommerce' ); ?></label>
						<span id="zbooks-modal-level"></span>
					</div>
					<div class="zbooks-detail-row">
						<label><?php esc_html_e( 'Message:', 'zbooks-for-woocommerce' ); ?></label>
						<div id="zbooks-modal-message"></div>
					</div>
					<div class="zbooks-detail-row" id="zbooks-modal-request-row" style="display: none;">
						<label><?php esc_html_e( 'Request:', 'zbooks-for-woocommerce' ); ?></label>
						<div id="zbooks-modal-request" style="font-family: monospace; background: #f0f0f1; padding: 8px; border-radius: 4px; word-break: break-all;"></div>
					</div>
					<div class="zbooks-detail-row" id="zbooks-modal-context-row">
						<label><?php esc_html_e( 'Context / Details:', 'zbooks-for-woocommerce' ); ?></label>
						<pre id="zbooks-modal-context"></pre>
					</div>
				</div>
				<div class="zbooks-modal-footer">
					<button type="button" class="button zbooks-copy-json">
						<?php esc_html_e( 'Copy JSON', 'zbooks-for-woocommerce' ); ?>
					</button>
					<button type="button" class="button button-primary zbooks-modal-close">
						<?php esc_html_e( 'Close', 'zbooks-for-woocommerce' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for getting logs.
	 */
	public function ajax_get_logs(): void {
		check_ajax_referer( 'zbooks_get_logs', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$date  = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : gmdate( 'Y-m-d' );
		$level = isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '';
		$limit = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 100;

		$entries = $this->logger->read_log( $date, $limit, $level );
		$stats   = $this->logger->get_stats( $date );

		wp_send_json_success(
			[
				'entries' => $entries,
				'stats'   => $stats,
			]
		);
	}

	/**
	 * AJAX handler for clearing old logs.
	 */
	public function ajax_clear_logs(): void {
		check_ajax_referer( 'zbooks_clear_logs', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$deleted = $this->logger->clear_old_logs( $this->logger->get_retention_days() );

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: number of files deleted */
					__( 'Deleted %d old log file(s).', 'zbooks-for-woocommerce' ),
					$deleted
				),
				'deleted' => $deleted,
			]
		);
	}

	/**
	 * AJAX handler for clearing all logs.
	 */
	public function ajax_clear_all_logs(): void {
		check_ajax_referer( 'zbooks_clear_all_logs', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$deleted = $this->logger->clear_all_logs();

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: number of files deleted */
					__( 'Deleted %d log file(s).', 'zbooks-for-woocommerce' ),
					$deleted
				),
				'deleted' => $deleted,
			]
		);
	}
}
