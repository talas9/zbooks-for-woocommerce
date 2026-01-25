<?php
/**
 * Reconciliation settings tab for settings page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Service\ReconciliationService;

defined( 'ABSPATH' ) || exit;

/**
 * Reconciliation settings tab within the main Settings page.
 */
class ReconciliationTab {

	/**
	 * Reconciliation service.
	 *
	 * @var ReconciliationService
	 */
	private ReconciliationService $service;

	/**
	 * Constructor.
	 *
	 * @param ReconciliationService $service Reconciliation service.
	 */
	public function __construct( ReconciliationService $service ) {
		$this->service = $service;
	}

	/**
	 * Render the reconciliation settings content.
	 * Called by SettingsPage for the Reconciliation tab.
	 */
	public function render_content(): void {
		$settings = $this->service->get_settings();

		// Handle form submission for settings.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_settings_save().
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

		<script>
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
		</script>
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
			'email_address'             => ! empty( sanitize_email( $input['email_address'] ?? '' ) )
				? sanitize_email( $input['email_address'] ?? '' )
				: get_option( 'admin_email' ),
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
}
