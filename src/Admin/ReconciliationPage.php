<?php
/**
 * Reconciliation admin page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Service\ReconciliationService;
use Zbooks\Repository\ReconciliationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page for viewing and managing reconciliation reports.
 */
class ReconciliationPage {

	/**
	 * Reconciliation service.
	 *
	 * @var ReconciliationService
	 */
	private ReconciliationService $service;

	/**
	 * Repository.
	 *
	 * @var ReconciliationRepository
	 */
	private ReconciliationRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param ReconciliationService    $service    Reconciliation service.
	 * @param ReconciliationRepository $repository Repository.
	 */
	public function __construct( ReconciliationService $service, ReconciliationRepository $repository ) {
		$this->service    = $service;
		$this->repository = $repository;

		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks(): void {
		// Priority 20 ensures this runs after SettingsPage (priority 10) creates the parent menu.
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 20 );
		add_action( 'wp_ajax_zbooks_run_reconciliation', [ $this, 'ajax_run_reconciliation' ] );
		add_action( 'wp_ajax_zbooks_delete_report', [ $this, 'ajax_delete_report' ] );
		add_action( 'wp_ajax_zbooks_delete_all_reports', [ $this, 'ajax_delete_all_reports' ] );
		add_action( 'wp_ajax_zbooks_view_report', [ $this, 'ajax_view_report' ] );
		add_action( 'wp_ajax_zbooks_export_report_csv', [ $this, 'ajax_export_report_csv' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue reconciliation page assets.
	 * WordPress.org requires proper enqueue instead of inline tags.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'zbooks_page_zbooks-reconciliation' ) {
			return;
		}

		// Enqueue CSS.
		wp_enqueue_style(
			'zbooks-reconciliation',
			ZBOOKS_PLUGIN_URL . 'assets/css/modules/reconciliation.css',
			[],
			ZBOOKS_VERSION
		);

		// Enqueue JavaScript module.
		wp_enqueue_script(
			'zbooks-reconciliation',
			ZBOOKS_PLUGIN_URL . 'assets/js/modules/reconciliation.js',
			[ 'jquery', 'zbooks-admin' ],
			ZBOOKS_VERSION,
			true
		);

		// Add frequency toggle inline script (WordPress.org compliant).
		$inline_script = "
		jQuery(document).ready(function($) {
			// Toggle frequency options.
			$('#zbooks-recon-frequency').on('change', function() {
				var frequency = $(this).val();
				$('.zbooks-weekly-option, .zbooks-monthly-option').hide();
				if (frequency === 'weekly') {
					$('.zbooks-weekly-option').show();
				} else if (frequency === 'monthly') {
					$('.zbooks-monthly-option').show();
				}
			});
		});
		";
		wp_add_inline_script( 'zbooks-reconciliation', $inline_script );
	}

	/**
	 * Add submenu page under ZBooks menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'zbooks',
			__( 'Reconciliation', 'zbooks-for-woocommerce' ),
			__( 'Reconciliation', 'zbooks-for-woocommerce' ),
			'manage_woocommerce',
			'zbooks-reconciliation',
			[ $this, 'render_page' ],
			1 // Position: right after Settings.
		);
	}

	/**
	 * Render the standalone reconciliation page.
	 * Shows Run Now section and Report History.
	 */
	public function render_page(): void {
		// Clean up stale "running" reports that crashed or timed out.
		$this->repository->mark_stale_reports_failed();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter.
		$paged        = isset( $_GET['report_page'] ) ? max( 1, absint( wp_unslash( $_GET['report_page'] ) ) ) : 1;
		$reports_data = $this->repository->get_paginated( $paged, 10 );
		$reports      = $reports_data['reports'];
		$total_pages  = $reports_data['pages'];

		$latest_report = $this->repository->get_latest();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reconciliation', 'zbooks-for-woocommerce' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Compare WooCommerce orders with Zoho Books invoices to identify discrepancies.', 'zbooks-for-woocommerce' ); ?>
			</p>

			<div id="zbooks-reconciliation-notices"></div>

			<!-- Run Reconciliation Section -->
			<div class="zbooks-run-reconciliation" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
				<h2><?php esc_html_e( 'Run Reconciliation', 'zbooks-for-woocommerce' ); ?></h2>

				<p class="description">
					<?php esc_html_e( 'Run reconciliation for a custom date range.', 'zbooks-for-woocommerce' ); ?>
				</p>

				<div style="margin: 15px 0;">
					<label style="margin-right: 20px;">
						<?php esc_html_e( 'Start Date:', 'zbooks-for-woocommerce' ); ?>
						<input type="date" id="zbooks-recon-start"
							value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '-7 days' ) ) ); ?>"
							max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
					</label>
					<label style="margin-right: 20px;">
						<?php esc_html_e( 'End Date:', 'zbooks-for-woocommerce' ); ?>
						<input type="date" id="zbooks-recon-end"
							value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ); ?>"
							max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
					</label>
					<button type="button" class="button button-primary" id="zbooks-run-reconciliation">
						<?php esc_html_e( 'Run Now', 'zbooks-for-woocommerce' ); ?>
					</button>
				</div>

				<div id="zbooks-reconciliation-progress" style="display: none; margin-top: 15px;">
					<span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
					<span><?php esc_html_e( 'Running reconciliation...', 'zbooks-for-woocommerce' ); ?></span>
				</div>
			</div>

			<!-- Latest Report Summary -->
			<?php if ( $latest_report && $latest_report->get_status() === 'completed' ) : ?>
				<?php $summary = $latest_report->get_summary(); ?>
				<div class="zbooks-latest-report" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
					<h2><?php esc_html_e( 'Latest Report Summary', 'zbooks-for-woocommerce' ); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: 1: Start date, 2: End date, 3: Generated date */
							esc_html__( 'Period: %1$s to %2$s | Generated: %3$s', 'zbooks-for-woocommerce' ),
							esc_html( $latest_report->get_period_start()->format( 'Y-m-d' ) ),
							esc_html( $latest_report->get_period_end()->format( 'Y-m-d' ) ),
							esc_html( $latest_report->get_generated_at()->format( 'Y-m-d H:i' ) )
						);
						?>
					</p>

					<?php
					$payment_issues = ( $summary['payment_mismatches'] ?? 0 ) + ( $summary['refund_mismatches'] ?? 0 );
					$status_issues  = $summary['status_mismatches'] ?? 0;
					?>
					<div class="zbooks-summary-cards">
						<div class="zbooks-card zbooks-card-neutral">
							<span class="zbooks-card-value"><?php echo esc_html( $summary['total_wc_orders'] ?? 0 ); ?></span>
							<span class="zbooks-card-label"><?php esc_html_e( 'WC Orders', 'zbooks-for-woocommerce' ); ?></span>
							<span class="zbooks-card-desc"><?php esc_html_e( 'Orders in period', 'zbooks-for-woocommerce' ); ?></span>
						</div>
						<div class="zbooks-card zbooks-card-neutral">
							<span class="zbooks-card-value"><?php echo esc_html( $summary['total_zoho_invoices'] ?? 0 ); ?></span>
							<span class="zbooks-card-label"><?php esc_html_e( 'Zoho Invoices', 'zbooks-for-woocommerce' ); ?></span>
							<span class="zbooks-card-desc"><?php esc_html_e( 'Invoices in period', 'zbooks-for-woocommerce' ); ?></span>
						</div>
						<div class="zbooks-card zbooks-card-success">
							<span class="zbooks-card-value"><?php echo esc_html( $summary['matched_count'] ?? 0 ); ?></span>
							<span class="zbooks-card-label"><?php esc_html_e( 'Matched', 'zbooks-for-woocommerce' ); ?></span>
							<span class="zbooks-card-desc"><?php esc_html_e( 'Orders synced correctly', 'zbooks-for-woocommerce' ); ?></span>
						</div>
						<div class="zbooks-card <?php echo ( $summary['missing_in_zoho'] ?? 0 ) > 0 ? 'zbooks-card-danger' : 'zbooks-card-neutral'; ?>">
							<span class="zbooks-card-value"><?php echo esc_html( $summary['missing_in_zoho'] ?? 0 ); ?></span>
							<span class="zbooks-card-label"><?php esc_html_e( 'Missing in Zoho', 'zbooks-for-woocommerce' ); ?></span>
							<span class="zbooks-card-desc"><?php esc_html_e( 'Orders without invoices', 'zbooks-for-woocommerce' ); ?></span>
						</div>
						<div class="zbooks-card <?php echo ( $summary['amount_mismatches'] ?? 0 ) > 0 ? 'zbooks-card-warning' : 'zbooks-card-neutral'; ?>">
							<span class="zbooks-card-value"><?php echo esc_html( $summary['amount_mismatches'] ?? 0 ); ?></span>
							<span class="zbooks-card-label"><?php esc_html_e( 'Amount Mismatches', 'zbooks-for-woocommerce' ); ?></span>
							<span class="zbooks-card-desc"><?php esc_html_e( 'Totals don\'t match', 'zbooks-for-woocommerce' ); ?></span>
						</div>
						<div class="zbooks-card <?php echo $payment_issues > 0 ? 'zbooks-card-warning' : 'zbooks-card-neutral'; ?>">
							<span class="zbooks-card-value"><?php echo esc_html( $payment_issues ); ?></span>
							<span class="zbooks-card-label"><?php esc_html_e( 'Payment Issues', 'zbooks-for-woocommerce' ); ?></span>
							<span class="zbooks-card-desc"><?php esc_html_e( 'Payment or refund mismatch', 'zbooks-for-woocommerce' ); ?></span>
						</div>
						<div class="zbooks-card <?php echo $status_issues > 0 ? 'zbooks-card-info' : 'zbooks-card-neutral'; ?>">
							<span class="zbooks-card-value"><?php echo esc_html( $status_issues ); ?></span>
							<span class="zbooks-card-label"><?php esc_html_e( 'Status Mismatches', 'zbooks-for-woocommerce' ); ?></span>
							<span class="zbooks-card-desc"><?php esc_html_e( 'Invoice status differs', 'zbooks-for-woocommerce' ); ?></span>
						</div>
						<div class="zbooks-card zbooks-card-neutral">
							<span class="zbooks-card-value"><?php echo wp_kses_post( wc_price( $summary['amount_difference'] ?? 0, [ 'currency' => get_woocommerce_currency() ] ) ); ?></span>
							<span class="zbooks-card-label"><?php esc_html_e( 'Total Difference', 'zbooks-for-woocommerce' ); ?></span>
							<span class="zbooks-card-desc"><?php esc_html_e( 'Sum of all discrepancies', 'zbooks-for-woocommerce' ); ?></span>
						</div>
					</div>

					<?php if ( $latest_report->has_discrepancies() ) : ?>
						<div style="margin-top: 20px;">
							<h3><?php esc_html_e( 'Recent Discrepancies', 'zbooks-for-woocommerce' ); ?></h3>
							<table class="widefat striped" style="margin-top: 10px;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Type', 'zbooks-for-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Order', 'zbooks-for-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Order Status', 'zbooks-for-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Invoice', 'zbooks-for-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Invoice Status', 'zbooks-for-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Payment Status', 'zbooks-for-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Date', 'zbooks-for-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Details', 'zbooks-for-woocommerce' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$zoho_org_id = get_option( 'zbooks_organization_id' );
									foreach ( array_slice( $latest_report->get_discrepancies(), 0, 5 ) as $discrepancy ) :
										?>
										<tr>
											<td>
												<span class="zbooks-badge zbooks-badge-<?php echo esc_attr( $discrepancy['type'] ); ?>">
													<?php echo esc_html( ucwords( str_replace( '_', ' ', $discrepancy['type'] ) ) ); ?>
												</span>
											</td>
											<td>
												<?php if ( ! empty( $discrepancy['order_id'] ) ) : ?>
													<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $discrepancy['order_id'] . '&action=edit' ) ); ?>">
														#<?php echo esc_html( $discrepancy['order_number'] ?? $discrepancy['order_id'] ); ?>
													</a>
												<?php elseif ( ! empty( $discrepancy['invoice_number'] ) ) : ?>
													<?php echo esc_html( $discrepancy['invoice_number'] ); ?>
												<?php else : ?>
													—
												<?php endif; ?>
											</td>
											<td>
												<?php if ( ! empty( $discrepancy['order_status'] ) ) : ?>
													<span class="zbooks-status zbooks-status-<?php echo esc_attr( $discrepancy['order_status'] ); ?>">
														<?php echo esc_html( ucwords( str_replace( '-', ' ', $discrepancy['order_status'] ) ) ); ?>
													</span>
												<?php else : ?>
													—
												<?php endif; ?>
											</td>
											<td>
												<?php if ( ! empty( $discrepancy['invoice_id'] ) && ! empty( $zoho_org_id ) ) : ?>
													<a href="<?php echo esc_url( 'https://books.zoho.com/app/' . $zoho_org_id . '#/invoices/' . $discrepancy['invoice_id'] ); ?>" target="_blank" rel="noopener noreferrer">
														<?php echo esc_html( $discrepancy['invoice_number'] ?? $discrepancy['invoice_id'] ); ?>
													</a>
												<?php elseif ( ! empty( $discrepancy['invoice_number'] ) ) : ?>
													<?php echo esc_html( $discrepancy['invoice_number'] ); ?>
												<?php else : ?>
													—
												<?php endif; ?>
											</td>
											<td>
												<?php if ( ! empty( $discrepancy['invoice_status'] ) ) : ?>
													<span class="zbooks-status zbooks-status-<?php echo esc_attr( $discrepancy['invoice_status'] ); ?>">
														<?php echo esc_html( ucwords( str_replace( '_', ' ', $discrepancy['invoice_status'] ) ) ); ?>
													</span>
												<?php else : ?>
													—
												<?php endif; ?>
											</td>
											<td>
												<?php if ( ! empty( $discrepancy['payment_status'] ) ) : ?>
													<span class="zbooks-status zbooks-status-<?php echo esc_attr( $discrepancy['payment_status'] ); ?>">
														<?php echo esc_html( ucwords( str_replace( '_', ' ', $discrepancy['payment_status'] ) ) ); ?>
													</span>
												<?php else : ?>
													—
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( $discrepancy['order_date'] ?? $discrepancy['invoice_date'] ?? '—' ); ?></td>
											<td><?php echo wp_kses_post( $discrepancy['message'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<?php if ( count( $latest_report->get_discrepancies() ) > 5 ) : ?>
								<p>
									<a href="#" class="zbooks-view-report" data-report-id="<?php echo esc_attr( $latest_report->get_id() ); ?>" style="text-decoration: none;">
										<?php
										printf(
											/* translators: %d: Number of additional discrepancies */
											esc_html__( '+ %d more discrepancies — View All →', 'zbooks-for-woocommerce' ),
											count( $latest_report->get_discrepancies() ) - 5
										);
										?>
									</a>
								</p>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<!-- Report History -->
			<div class="zbooks-report-history" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
				<h2 style="display: inline-block; margin-right: 20px;">
					<?php esc_html_e( 'Report History', 'zbooks-for-woocommerce' ); ?>
				</h2>
				<?php if ( ! empty( $reports ) ) : ?>
					<button type="button" class="button zbooks-delete-all-reports" style="vertical-align: middle;">
						<?php esc_html_e( 'Delete All Reports', 'zbooks-for-woocommerce' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( empty( $reports ) ) : ?>
					<p class="description"><?php esc_html_e( 'No reconciliation reports yet.', 'zbooks-for-woocommerce' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="margin-top: 15px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'zbooks-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Period', 'zbooks-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Status', 'zbooks-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Matched', 'zbooks-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Discrepancies', 'zbooks-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Difference', 'zbooks-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'zbooks-for-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $reports as $report ) : ?>
								<?php $rpt_summary = $report->get_summary(); ?>
								<tr data-report-id="<?php echo esc_attr( $report->get_id() ); ?>">
									<td><?php echo esc_html( $report->get_generated_at()->format( 'Y-m-d H:i' ) ); ?></td>
									<td>
										<?php
										echo esc_html(
											$report->get_period_start()->format( 'M j' ) . ' - ' .
											$report->get_period_end()->format( 'M j, Y' )
										);
										?>
									</td>
									<td>
										<span class="zbooks-status zbooks-status-<?php echo esc_attr( $report->get_status() ); ?>">
											<?php echo esc_html( ucfirst( $report->get_status() ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $rpt_summary['matched_count'] ?? 0 ); ?></td>
									<td>
										<?php
										$total_disc = ( $rpt_summary['missing_in_zoho'] ?? 0 ) + ( $rpt_summary['amount_mismatches'] ?? 0 );
										echo esc_html( $total_disc );
										?>
									</td>
									<td><?php echo wp_kses_post( wc_price( $rpt_summary['amount_difference'] ?? 0, [ 'currency' => get_woocommerce_currency() ] ) ); ?></td>
									<td>
										<button type="button" class="button button-small zbooks-view-report"
											data-report-id="<?php echo esc_attr( $report->get_id() ); ?>">
											<?php esc_html_e( 'View', 'zbooks-for-woocommerce' ); ?>
										</button>
										<button type="button" class="button button-small zbooks-export-csv"
											data-report-id="<?php echo esc_attr( $report->get_id() ); ?>">
											<?php esc_html_e( 'CSV', 'zbooks-for-woocommerce' ); ?>
										</button>
										<button type="button" class="button button-small zbooks-delete-report"
											data-report-id="<?php echo esc_attr( $report->get_id() ); ?>">
											<?php esc_html_e( 'Delete', 'zbooks-for-woocommerce' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav" style="margin-top: 15px;">
							<div class="tablenav-pages">
								<?php
								$base_url = admin_url( 'admin.php?page=zbooks-reconciliation' );
								echo wp_kses_post(
									paginate_links(
										[
											'base'      => add_query_arg( 'report_page', '%#%', $base_url ),
											'format'    => '',
											'prev_text' => '&laquo;',
											'next_text' => '&raquo;',
											'total'     => $total_pages,
											'current'   => $paged,
										]
									)
								);
								?>
							</div>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Render the reconciliation settings content.
	 * Called by SettingsPage for the Reconciliation tab.
	 */
	public function render_content(): void {
		$settings = $this->service->get_settings();

		// Handle form submission for settings.
		if ( isset( $_POST['zbooks_reconciliation_settings_nonce'] ) ) {
			$this->handle_settings_save();
			$settings = $this->service->get_settings(); // Reload settings.
		}
		?>
		<div class="zbooks-reconciliation-tab">
			<h2><?php esc_html_e( 'Reconciliation Settings', 'zbooks-for-woocommerce' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure automatic reconciliation schedule and notifications.', 'zbooks-for-woocommerce' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=zbooks-reconciliation' ) ); ?>">
					<?php esc_html_e( 'Go to Reconciliation Reports →', 'zbooks-for-woocommerce' ); ?>
				</a>
			</p>

			<div id="zbooks-reconciliation-notices"></div>

			<!-- Settings Section -->
			<div class="zbooks-reconciliation-settings" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
				<form method="post" action="">
					<?php wp_nonce_field( 'zbooks_save_reconciliation_settings', 'zbooks_reconciliation_settings_nonce' ); ?>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable Automatic Reconciliation', 'zbooks-for-woocommerce' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="reconciliation[enabled]" value="1"
										<?php checked( ! empty( $settings['enabled'] ) ); ?>>
									<?php esc_html_e( 'Run reconciliation automatically on schedule', 'zbooks-for-woocommerce' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When disabled, you can still run reconciliation manually from the Reconciliation page.', 'zbooks-for-woocommerce' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Schedule Frequency', 'zbooks-for-woocommerce' ); ?></th>
							<td>
								<select name="reconciliation[frequency]" id="zbooks-recon-frequency">
									<option value="daily" <?php selected( $settings['frequency'] ?? 'weekly', 'daily' ); ?>>
										<?php esc_html_e( 'Daily', 'zbooks-for-woocommerce' ); ?>
									</option>
									<option value="weekly" <?php selected( $settings['frequency'] ?? 'weekly', 'weekly' ); ?>>
										<?php esc_html_e( 'Weekly', 'zbooks-for-woocommerce' ); ?>
									</option>
									<option value="monthly" <?php selected( $settings['frequency'] ?? 'weekly', 'monthly' ); ?>>
										<?php esc_html_e( 'Monthly', 'zbooks-for-woocommerce' ); ?>
									</option>
								</select>
							</td>
						</tr>
						<tr class="zbooks-weekly-option" <?php echo ( $settings['frequency'] ?? 'weekly' ) !== 'weekly' ? 'style="display:none;"' : ''; ?>>
							<th scope="row"><?php esc_html_e( 'Day of Week', 'zbooks-for-woocommerce' ); ?></th>
							<td>
								<select name="reconciliation[day_of_week]">
									<?php
									$days = [
										0 => __( 'Sunday', 'zbooks-for-woocommerce' ),
										1 => __( 'Monday', 'zbooks-for-woocommerce' ),
										2 => __( 'Tuesday', 'zbooks-for-woocommerce' ),
										3 => __( 'Wednesday', 'zbooks-for-woocommerce' ),
										4 => __( 'Thursday', 'zbooks-for-woocommerce' ),
										5 => __( 'Friday', 'zbooks-for-woocommerce' ),
										6 => __( 'Saturday', 'zbooks-for-woocommerce' ),
									];
									foreach ( $days as $value => $label ) :
										?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['day_of_week'] ?? 1, $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr class="zbooks-monthly-option" <?php echo ( $settings['frequency'] ?? 'weekly' ) !== 'monthly' ? 'style="display:none;"' : ''; ?>>
							<th scope="row"><?php esc_html_e( 'Day of Month', 'zbooks-for-woocommerce' ); ?></th>
							<td>
								<select name="reconciliation[day_of_month]">
									<?php for ( $i = 1; $i <= 28; $i++ ) : ?>
										<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $settings['day_of_month'] ?? 1, $i ); ?>>
											<?php echo esc_html( $i ); ?>
										</option>
									<?php endfor; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Max 28 to ensure compatibility with all months.', 'zbooks-for-woocommerce' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Amount Tolerance', 'zbooks-for-woocommerce' ); ?></th>
							<td>
								<input type="number" name="reconciliation[amount_tolerance]" step="0.01" min="0" max="10"
									value="<?php echo esc_attr( $settings['amount_tolerance'] ?? 0.05 ); ?>"
									style="width: 80px;">
								<p class="description">
									<?php esc_html_e( 'Ignore amount differences smaller than this value (for rounding differences).', 'zbooks-for-woocommerce' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Email Notifications', 'zbooks-for-woocommerce' ); ?></th>
							<td>
								<label style="display: block; margin-bottom: 10px;">
									<input type="checkbox" name="reconciliation[email_enabled]" value="1"
										<?php checked( ! empty( $settings['email_enabled'] ) ); ?>>
									<?php esc_html_e( 'Send email report after reconciliation', 'zbooks-for-woocommerce' ); ?>
								</label>
								<label style="display: block; margin-bottom: 10px;">
									<input type="checkbox" name="reconciliation[email_on_discrepancy_only]" value="1"
										<?php checked( $settings['email_on_discrepancy_only'] ?? true ); ?>>
									<?php esc_html_e( 'Only send email when discrepancies are found', 'zbooks-for-woocommerce' ); ?>
								</label>
								<input type="email" name="reconciliation[email_address]"
									value="<?php echo esc_attr( $settings['email_address'] ?? get_option( 'admin_email' ) ); ?>"
									style="width: 300px;"
									placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Settings', 'zbooks-for-woocommerce' ), 'primary', 'save_reconciliation_settings' ); ?>
				</form>
			</div>
	</div><!-- .zbooks-reconciliation-tab -->
	<!-- JavaScript now output via wp_add_inline_script() in enqueue_assets() (WordPress.org requirement) -->
	<?php
	}

	/**
	 * Handle settings form submission.
	 */
	private function handle_settings_save(): void {
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['zbooks_reconciliation_settings_nonce'] ?? '' ) ),
			'zbooks_save_reconciliation_settings'
		) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		$input = isset( $_POST['reconciliation'] ) ? wp_unslash( $_POST['reconciliation'] ) : [];

		$settings = [
			'enabled'                   => ! empty( $input['enabled'] ),
			'frequency'                 => in_array( $input['frequency'] ?? '', [ 'daily', 'weekly', 'monthly' ], true )
				? $input['frequency']
				: 'weekly',
			'day_of_week'               => min( 6, max( 0, absint( $input['day_of_week'] ?? 1 ) ) ),
			'day_of_month'              => min( 28, max( 1, absint( $input['day_of_month'] ?? 1 ) ) ),
			'amount_tolerance'          => min( 10, max( 0, (float) ( $input['amount_tolerance'] ?? 0.05 ) ) ),
			'email_enabled'             => ! empty( $input['email_enabled'] ),
			'email_on_discrepancy_only' => ! empty( $input['email_on_discrepancy_only'] ),
			'email_address'             => sanitize_email( $input['email_address'] ?? '' ) ?: get_option( 'admin_email' ),
		];

		update_option( 'zbooks_reconciliation_settings', $settings );

		add_action(
			'admin_notices',
			function () {
				?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Reconciliation settings saved.', 'zbooks-for-woocommerce' ); ?></p>
			</div>
				<?php
			}
		);
	}

	/**
	 * AJAX handler for running reconciliation.
	 */
	public function ajax_run_reconciliation(): void {
		check_ajax_referer( 'zbooks_reconciliation', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$start_date = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) );
		$end_date   = sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) );

		if ( empty( $start_date ) || empty( $end_date ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid date range.', 'zbooks-for-woocommerce' ) ] );
		}

		try {
			$start = new \DateTimeImmutable( $start_date . ' 00:00:00' );
			$end   = new \DateTimeImmutable( $end_date . ' 23:59:59' );

			$report = $this->service->run( $start, $end );

			// Send email if enabled.
			$this->service->send_email_notification( $report );

			wp_send_json_success(
				[
					'report_id' => $report->get_id(),
					'status'    => $report->get_status(),
					'summary'   => $report->get_summary(),
				]
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * AJAX handler for deleting a report.
	 */
	public function ajax_delete_report(): void {
		check_ajax_referer( 'zbooks_reconciliation', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$report_id = absint( $_POST['report_id'] ?? 0 );

		if ( ! $report_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid report ID.', 'zbooks-for-woocommerce' ) ] );
		}

		if ( $this->repository->delete( $report_id ) ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to delete report.', 'zbooks-for-woocommerce' ) ] );
		}
	}

	/**
	 * AJAX handler for deleting all reports.
	 */
	public function ajax_delete_all_reports(): void {
		check_ajax_referer( 'zbooks_reconciliation', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		if ( $this->repository->delete_all() ) {
			wp_send_json_success( [ 'message' => __( 'All reports deleted successfully.', 'zbooks-for-woocommerce' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to delete all reports.', 'zbooks-for-woocommerce' ) ] );
		}
	}

	/**
	 * AJAX handler for viewing a report.
	 */
	public function ajax_view_report(): void {
		check_ajax_referer( 'zbooks_reconciliation', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$report_id = absint( $_POST['report_id'] ?? 0 );

		if ( ! $report_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid report ID.', 'zbooks-for-woocommerce' ) ] );
		}

		$report = $this->repository->get( $report_id );

		if ( ! $report ) {
			wp_send_json_error( [ 'message' => __( 'Report not found.', 'zbooks-for-woocommerce' ) ] );
		}

		$summary       = $report->get_summary();
		$discrepancies = $report->get_discrepancies();

		// Build HTML for discrepancies.
		$discrepancies_html = '';
		if ( ! empty( $discrepancies ) ) {
			$zoho_org_id = get_option( 'zbooks_organization_id' );

			$discrepancies_html  = '<table class="widefat striped">';
			$discrepancies_html .= '<thead><tr>';
			$discrepancies_html .= '<th>' . esc_html__( 'Type', 'zbooks-for-woocommerce' ) . '</th>';
			$discrepancies_html .= '<th>' . esc_html__( 'Order', 'zbooks-for-woocommerce' ) . '</th>';
			$discrepancies_html .= '<th>' . esc_html__( 'Order Status', 'zbooks-for-woocommerce' ) . '</th>';
			$discrepancies_html .= '<th>' . esc_html__( 'Invoice', 'zbooks-for-woocommerce' ) . '</th>';
			$discrepancies_html .= '<th>' . esc_html__( 'Invoice Status', 'zbooks-for-woocommerce' ) . '</th>';
			$discrepancies_html .= '<th>' . esc_html__( 'Payment Status', 'zbooks-for-woocommerce' ) . '</th>';
			$discrepancies_html .= '<th>' . esc_html__( 'Date', 'zbooks-for-woocommerce' ) . '</th>';
			$discrepancies_html .= '<th>' . esc_html__( 'Details', 'zbooks-for-woocommerce' ) . '</th>';
			$discrepancies_html .= '</tr></thead><tbody>';

			foreach ( $discrepancies as $discrepancy ) {
				$type_class = 'zbooks-badge zbooks-badge-' . esc_attr( $discrepancy['type'] );
				$type_label = ucwords( str_replace( '_', ' ', $discrepancy['type'] ) );

				// Order reference.
				$order_ref = '—';
				if ( ! empty( $discrepancy['order_id'] ) ) {
					$order_url = admin_url( 'post.php?post=' . $discrepancy['order_id'] . '&action=edit' );
					$order_ref = '<a href="' . esc_url( $order_url ) . '" target="_blank">#' .
						esc_html( $discrepancy['order_number'] ?? $discrepancy['order_id'] ) . '</a>';
				}

				// Invoice reference.
				$invoice_ref = '—';
				if ( ! empty( $discrepancy['invoice_id'] ) && ! empty( $zoho_org_id ) ) {
					$invoice_url = 'https://books.zoho.com/app/' . $zoho_org_id . '#/invoices/' . $discrepancy['invoice_id'];
					$invoice_ref = '<a href="' . esc_url( $invoice_url ) . '" target="_blank" rel="noopener noreferrer">' .
						esc_html( $discrepancy['invoice_number'] ?? $discrepancy['invoice_id'] ) . '</a>';
				} elseif ( ! empty( $discrepancy['invoice_number'] ) ) {
					$invoice_ref = esc_html( $discrepancy['invoice_number'] );
				}

				// Order status.
				$order_status_html = '—';
				if ( ! empty( $discrepancy['order_status'] ) ) {
					$order_status_html = '<span class="zbooks-status zbooks-status-' . esc_attr( $discrepancy['order_status'] ) . '">' .
						esc_html( ucwords( str_replace( '-', ' ', $discrepancy['order_status'] ) ) ) . '</span>';
				}

				// Invoice status.
				$invoice_status_html = '—';
				if ( ! empty( $discrepancy['invoice_status'] ) ) {
					$invoice_status_html = '<span class="zbooks-status zbooks-status-' . esc_attr( $discrepancy['invoice_status'] ) . '">' .
						esc_html( ucwords( str_replace( '_', ' ', $discrepancy['invoice_status'] ) ) ) . '</span>';
				}

				// Payment status.
				$payment_status_html = '—';
				if ( ! empty( $discrepancy['payment_status'] ) ) {
					$payment_status_html = '<span class="zbooks-status zbooks-status-' . esc_attr( $discrepancy['payment_status'] ) . '">' .
						esc_html( ucwords( str_replace( '_', ' ', $discrepancy['payment_status'] ) ) ) . '</span>';
				}

				$date = $discrepancy['order_date'] ?? $discrepancy['invoice_date'] ?? '—';

				$discrepancies_html .= '<tr>';
				$discrepancies_html .= '<td><span class="' . esc_attr( $type_class ) . '">' . esc_html( $type_label ) . '</span></td>';
				$discrepancies_html .= '<td>' . $order_ref . '</td>';
				$discrepancies_html .= '<td>' . $order_status_html . '</td>';
				$discrepancies_html .= '<td>' . $invoice_ref . '</td>';
				$discrepancies_html .= '<td>' . $invoice_status_html . '</td>';
				$discrepancies_html .= '<td>' . $payment_status_html . '</td>';
				$discrepancies_html .= '<td>' . esc_html( $date ) . '</td>';
				$discrepancies_html .= '<td>' . wp_kses_post( $discrepancy['message'] ) . '</td>';
				$discrepancies_html .= '</tr>';
			}

			$discrepancies_html .= '</tbody></table>';
		} else {
			$discrepancies_html = '<p>' . esc_html__( 'No discrepancies found. All orders match their invoices.', 'zbooks-for-woocommerce' ) . '</p>';
		}

		wp_send_json_success(
			[
				'id'                 => $report->get_id(),
				'status'             => $report->get_status(),
				'error'              => $report->get_error(),
				'period_start'       => $report->get_period_start()->format( 'Y-m-d' ),
				'period_end'         => $report->get_period_end()->format( 'Y-m-d' ),
				'generated_at'       => $report->get_generated_at()->format( 'Y-m-d H:i:s' ),
				'summary'            => $summary,
				'discrepancy_count'  => count( $discrepancies ),
				'discrepancies_html' => $discrepancies_html,
			]
		);
	}

	/**
	 * AJAX handler for exporting a report as CSV.
	 */
	public function ajax_export_report_csv(): void {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce check.
		check_ajax_referer( 'zbooks_reconciliation', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'Permission denied.', 'zbooks-for-woocommerce' ) );
		}

		$report_id = absint( $_GET['report_id'] ?? 0 );

		if ( ! $report_id ) {
			wp_die( __( 'Invalid report ID.', 'zbooks-for-woocommerce' ) );
		}

		$report = $this->repository->get( $report_id );

		if ( ! $report ) {
			wp_die( __( 'Report not found.', 'zbooks-for-woocommerce' ) );
		}

		$summary       = $report->get_summary();
		$discrepancies = $report->get_discrepancies();

		// Set headers for CSV download.
		$filename = sprintf(
			'reconciliation-report-%s-to-%s.csv',
			$report->get_period_start()->format( 'Y-m-d' ),
			$report->get_period_end()->format( 'Y-m-d' )
		);

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// Add BOM for Excel UTF-8 compatibility.
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		// Report header info.
		fputcsv( $output, [ 'Reconciliation Report' ] );
		fputcsv( $output, [ 'Period', $report->get_period_start()->format( 'Y-m-d' ) . ' to ' . $report->get_period_end()->format( 'Y-m-d' ) ] );
		fputcsv( $output, [ 'Generated', $report->get_generated_at()->format( 'Y-m-d H:i:s' ) ] );
		fputcsv( $output, [ 'Status', ucfirst( $report->get_status() ) ] );
		fputcsv( $output, [] );

		// Summary section.
		fputcsv( $output, [ 'Summary' ] );
		fputcsv( $output, [ 'WooCommerce Orders', $summary['total_wc_orders'] ?? 0 ] );
		fputcsv( $output, [ 'Zoho Invoices', $summary['total_zoho_invoices'] ?? 0 ] );
		fputcsv( $output, [ 'Matched', $summary['matched_count'] ?? 0 ] );
		fputcsv( $output, [ 'Missing in Zoho', $summary['missing_in_zoho'] ?? 0 ] );
		fputcsv( $output, [ 'Amount Mismatches', $summary['amount_mismatches'] ?? 0 ] );
		fputcsv( $output, [ 'Payment Mismatches', $summary['payment_mismatches'] ?? 0 ] );
		fputcsv( $output, [ 'Refund Mismatches', $summary['refund_mismatches'] ?? 0 ] );
		fputcsv( $output, [ 'Status Mismatches', $summary['status_mismatches'] ?? 0 ] );
		fputcsv( $output, [ 'Total Difference', $summary['amount_difference'] ?? 0 ] );
		fputcsv( $output, [] );

		// Discrepancies section.
		if ( ! empty( $discrepancies ) ) {
			fputcsv( $output, [ 'Discrepancies (' . count( $discrepancies ) . ')' ] );
			fputcsv( $output, [ 'Type', 'Order/Invoice', 'Date', 'WC Amount', 'Zoho Amount', 'Difference', 'Details' ] );

			foreach ( $discrepancies as $discrepancy ) {
				$type        = ucwords( str_replace( '_', ' ', $discrepancy['type'] ) );
				$reference   = $discrepancy['order_number'] ?? $discrepancy['invoice_number'] ?? $discrepancy['order_id'] ?? '';
				$date        = $discrepancy['order_date'] ?? $discrepancy['invoice_date'] ?? '';
				$wc_amount   = $discrepancy['order_total'] ?? $discrepancy['order_paid'] ?? $discrepancy['wc_refund_total'] ?? '';
				$zoho_amount = $discrepancy['invoice_total'] ?? $discrepancy['invoice_paid'] ?? $discrepancy['zoho_credits'] ?? '';
				$difference  = $discrepancy['difference'] ?? '';

				// Strip HTML from message.
				$message = wp_strip_all_tags( $discrepancy['message'] ?? '' );

				fputcsv(
					$output,
					[
						$type,
						$reference,
						$date,
						$wc_amount,
						$zoho_amount,
						$difference,
						$message,
					]
				);
			}
		} else {
			fputcsv( $output, [ 'No discrepancies found' ] );
		}

		fclose( $output );
		exit;
	}
}
