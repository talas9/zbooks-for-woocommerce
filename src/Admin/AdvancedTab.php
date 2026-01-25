<?php
/**
 * Advanced settings tab for settings page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Advanced settings tab - handles retry settings and log configuration.
 */
class AdvancedTab {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// No dependencies needed for advanced settings.
	}

	/**
	 * Register settings for this tab.
	 */
	public function register_settings(): void {
		register_setting(
			'zbooks_settings_advanced',
			'zbooks_retry_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_retry' ],
			]
		);
		register_setting(
			'zbooks_settings_advanced',
			'zbooks_log_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_log_settings' ],
			]
		);

		// Retry settings section.
		add_settings_section(
			'zbooks_retry_section',
			__( 'Retry Settings', 'zbooks-for-woocommerce' ),
			[ $this, 'render_retry_section' ],
			'zbooks-settings-advanced'
		);

		add_settings_field(
			'zbooks_retry_settings',
			__( 'Retry Mode', 'zbooks-for-woocommerce' ),
			[ $this, 'render_retry_field' ],
			'zbooks-settings-advanced',
			'zbooks_retry_section'
		);

		// Log settings section.
		add_settings_section(
			'zbooks_log_section',
			__( 'Log Settings', 'zbooks-for-woocommerce' ),
			[ $this, 'render_log_section' ],
			'zbooks-settings-advanced'
		);

		add_settings_field(
			'zbooks_log_settings',
			__( 'Log Configuration', 'zbooks-for-woocommerce' ),
			[ $this, 'render_log_settings_field' ],
			'zbooks-settings-advanced',
			'zbooks_log_section'
		);
	}

	/**
	 * Render the tab content.
	 */
	public function render_content(): void {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'zbooks_settings_advanced' );
			do_settings_sections( 'zbooks-settings-advanced' );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Render retry section description.
	 */
	public function render_retry_section(): void {
		?>
		<p><?php esc_html_e( 'Configure how failed syncs are retried.', 'zbooks-for-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Render retry settings field.
	 */
	public function render_retry_field(): void {
		$settings = get_option(
			'zbooks_retry_settings',
			[
				'mode'            => 'max_retries',
				'max_count'       => 5,
				'backoff_minutes' => 15,
			]
		);
		?>
		<fieldset>
			<label>
				<input type="radio" name="zbooks_retry_settings[mode]" value="max_retries"
					<?php checked( $settings['mode'], 'max_retries' ); ?>>
				<?php esc_html_e( 'Retry up to', 'zbooks-for-woocommerce' ); ?>
				<input type="number" name="zbooks_retry_settings[max_count]"
					value="<?php echo esc_attr( $settings['max_count'] ); ?>"
					min="1" max="20" style="width: 60px;">
				<?php esc_html_e( 'times', 'zbooks-for-woocommerce' ); ?>
			</label>
			<br><br>
			<label>
				<input type="radio" name="zbooks_retry_settings[mode]" value="indefinite"
					<?php checked( $settings['mode'], 'indefinite' ); ?>>
				<?php esc_html_e( 'Retry indefinitely', 'zbooks-for-woocommerce' ); ?>
			</label>
			<br><br>
			<label>
				<input type="radio" name="zbooks_retry_settings[mode]" value="manual"
					<?php checked( $settings['mode'], 'manual' ); ?>>
				<?php esc_html_e( 'Manual retry only', 'zbooks-for-woocommerce' ); ?>
			</label>
			<br><br>
			<label>
				<?php esc_html_e( 'Backoff interval:', 'zbooks-for-woocommerce' ); ?>
				<input type="number" name="zbooks_retry_settings[backoff_minutes]"
					value="<?php echo esc_attr( $settings['backoff_minutes'] ); ?>"
					min="5" max="60" style="width: 60px;">
				<?php esc_html_e( 'minutes (doubles each retry)', 'zbooks-for-woocommerce' ); ?>
			</label>
		</fieldset>
		<?php
	}

	/**
	 * Render log section description.
	 */
	public function render_log_section(): void {
		?>
		<p><?php esc_html_e( 'Configure logging behavior and error notifications.', 'zbooks-for-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Render log settings field.
	 */
	public function render_log_settings_field(): void {
		$settings = get_option(
			'zbooks_log_settings',
			[
				'retention_days'   => 30,
				'max_file_size_mb' => 10,
				'email_on_error'   => false,
				'error_email'      => get_option( 'admin_email' ),
			]
		);
		?>
		<fieldset>
			<label style="display: block; margin-bottom: 10px;">
				<?php esc_html_e( 'Keep logs for:', 'zbooks-for-woocommerce' ); ?>
				<input type="number" name="zbooks_log_settings[retention_days]"
					value="<?php echo esc_attr( $settings['retention_days'] ); ?>"
					min="1" max="365" style="width: 60px;">
				<?php esc_html_e( 'days', 'zbooks-for-woocommerce' ); ?>
			</label>

			<label style="display: block; margin-bottom: 10px;">
				<?php esc_html_e( 'Maximum log file size:', 'zbooks-for-woocommerce' ); ?>
				<input type="number" name="zbooks_log_settings[max_file_size_mb]"
					value="<?php echo esc_attr( $settings['max_file_size_mb'] ); ?>"
					min="1" max="100" style="width: 60px;">
				<?php esc_html_e( 'MB (older entries will be rotated)', 'zbooks-for-woocommerce' ); ?>
			</label>

			<label style="display: block; margin-bottom: 10px;">
				<input type="checkbox" name="zbooks_log_settings[email_on_error]" value="1"
					<?php checked( ! empty( $settings['email_on_error'] ) ); ?>>
				<?php esc_html_e( 'Send email notification on sync errors', 'zbooks-for-woocommerce' ); ?>
			</label>

			<label style="display: block; margin-left: 24px;">
				<?php esc_html_e( 'Error notification email:', 'zbooks-for-woocommerce' ); ?>
				<?php $error_email = ! empty( $settings['error_email'] ) ? $settings['error_email'] : get_option( 'admin_email' ); ?>
				<input type="email" name="zbooks_log_settings[error_email]"
					value="<?php echo esc_attr( $error_email ); ?>"
					class="regular-text">
			</label>
		</fieldset>
		<?php
	}

	/**
	 * Sanitize retry settings.
	 *
	 * @param array $input Input data.
	 * @return array
	 */
	public function sanitize_retry( array $input ): array {
		$valid_modes = [ 'max_retries', 'indefinite', 'manual' ];

		return [
			'mode'            => in_array( $input['mode'] ?? '', $valid_modes, true )
				? $input['mode']
				: 'max_retries',
			'max_count'       => min( 20, max( 1, absint( $input['max_count'] ?? 5 ) ) ),
			'backoff_minutes' => min( 60, max( 5, absint( $input['backoff_minutes'] ?? 15 ) ) ),
		];
	}

	/**
	 * Sanitize log settings.
	 *
	 * @param array $input Input data.
	 * @return array
	 */
	public function sanitize_log_settings( array $input ): array {
		$error_email = ! empty( $input['error_email'] )
			? sanitize_email( $input['error_email'] )
			: get_option( 'admin_email' );

		return [
			'retention_days'   => min( 365, max( 1, absint( $input['retention_days'] ?? 30 ) ) ),
			'max_file_size_mb' => min( 100, max( 1, absint( $input['max_file_size_mb'] ?? 10 ) ) ),
			'email_on_error'   => ! empty( $input['email_on_error'] ),
			'error_email'      => $error_email,
		];
	}
}
