<?php
/**
 * Email template service.
 *
 * Provides professional HTML email templates for notifications.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Service for generating professional HTML email templates.
 */
class EmailTemplateService {

	/**
	 * Brand colors.
	 */
	private const COLORS = [
		'primary'    => '#0073aa',
		'success'    => '#00a32a',
		'warning'    => '#dba617',
		'error'      => '#d63638',
		'background' => '#f0f0f1',
		'surface'    => '#ffffff',
		'text'       => '#1d2327',
		'text_muted' => '#646970',
		'border'     => '#c3c4c7',
	];

	/**
	 * Get the base email template.
	 *
	 * @param string $content Main content HTML.
	 * @param string $type    Email type: 'error', 'warning', 'success', 'info'.
	 * @return string Complete HTML email.
	 */
	public function get_template( string $content, string $type = 'info' ): string {
		$accent_color = $this->get_accent_color( $type );
		$site_name    = get_bloginfo( 'name' );
		$site_url     = home_url();
		$year         = gmdate( 'Y' );

		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>ZBooks for WooCommerce</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif; background-color: #f0f0f1; -webkit-font-smoothing: antialiased;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f0f0f1;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" align="center" style="max-width: 600px; margin: 0 auto;">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: {$accent_color}; padding: 30px 40px; border-radius: 8px 8px 0 0;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td>
                                        <h1 style="margin: 0; font-size: 24px; font-weight: 600; color: #ffffff; letter-spacing: -0.5px;">
                                            ZBooks for WooCommerce
                                        </h1>
                                        <p style="margin: 8px 0 0 0; font-size: 14px; color: rgba(255,255,255,0.85);">
                                            Zoho Books Integration
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="background-color: #ffffff; padding: 40px; border-left: 1px solid #c3c4c7; border-right: 1px solid #c3c4c7;">
                            {$content}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #ffffff; padding: 24px 40px; border-top: 1px solid #e0e0e0; border-radius: 0 0 8px 8px; border-left: 1px solid #c3c4c7; border-right: 1px solid #c3c4c7; border-bottom: 1px solid #c3c4c7;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="font-size: 13px; color: #646970; line-height: 1.5;">
                                        <p style="margin: 0 0 8px 0;">
                                            This notification was sent from <a href="{$site_url}" style="color: #0073aa; text-decoration: none;">{$site_name}</a>
                                        </p>
                                        <p style="margin: 0; color: #8c8f94; font-size: 12px;">
                                            &copy; {$year} ZBooks for WooCommerce
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
	}

	/**
	 * Build an error notification email.
	 *
	 * @param string $error_message The main error message.
	 * @param array  $context       Additional context data.
	 * @param string $logs_url      URL to view logs.
	 * @return string Complete HTML email.
	 */
	public function build_error_email( string $error_message, array $context, string $logs_url ): string {
		$time = current_time( 'F j, Y \a\t g:i A' );

		// Build context details.
		$details_html = $this->build_details_table( $context );

		$content = <<<HTML
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
    <!-- Alert Badge -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: #fcf0f1; border: 1px solid #f0b8b8; border-radius: 4px; padding: 8px 16px;">
                        <span style="color: #d63638; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                            &#9888; Sync Error
                        </span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Main Message -->
    <tr>
        <td style="padding-bottom: 24px;">
            <h2 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 600; color: #1d2327; line-height: 1.3;">
                A sync error has occurred
            </h2>
            <p style="margin: 0; font-size: 15px; color: #50575e; line-height: 1.6;">
                The following error was encountered while syncing data between WooCommerce and Zoho Books:
            </p>
        </td>
    </tr>

    <!-- Error Box -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                <tr>
                    <td style="background-color: #fcf0f1; border-left: 4px solid #d63638; padding: 20px; border-radius: 0 4px 4px 0;">
                        <p style="margin: 0; font-size: 15px; color: #1d2327; line-height: 1.5; font-family: 'SF Mono', Monaco, Consolas, monospace;">
                            {$error_message}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {$details_html}

    <!-- Timestamp -->
    <tr>
        <td style="padding-bottom: 32px;">
            <p style="margin: 0; font-size: 13px; color: #646970;">
                <strong>Occurred:</strong> {$time}
            </p>
        </td>
    </tr>

    <!-- CTA Button -->
    <tr>
        <td>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: #0073aa; border-radius: 4px;">
                        <a href="{$logs_url}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 4px;">
                            View Sync Logs &rarr;
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;

		return $this->get_template( $content, 'error' );
	}

	/**
	 * Build a warning notification email.
	 *
	 * @param string $warning_message The main warning message.
	 * @param array  $context         Additional context data.
	 * @param string $action_url      URL for action button.
	 * @param string $action_label    Label for action button.
	 * @return string Complete HTML email.
	 */
	public function build_warning_email( string $warning_message, array $context, string $action_url = '', string $action_label = '' ): string {
		$time = current_time( 'F j, Y \a\t g:i A' );

		$details_html = $this->build_details_table( $context );

		$button_html = '';
		if ( ! empty( $action_url ) && ! empty( $action_label ) ) {
			$button_html = <<<HTML
    <!-- CTA Button -->
    <tr>
        <td style="padding-top: 8px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: #0073aa; border-radius: 4px;">
                        <a href="{$action_url}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 4px;">
                            {$action_label} &rarr;
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
HTML;
		}

		$content = <<<HTML
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
    <!-- Alert Badge -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: #fcf6e5; border: 1px solid #dba617; border-radius: 4px; padding: 8px 16px;">
                        <span style="color: #996800; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                            &#9888; Warning
                        </span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Main Message -->
    <tr>
        <td style="padding-bottom: 24px;">
            <h2 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 600; color: #1d2327; line-height: 1.3;">
                Attention Required
            </h2>
            <p style="margin: 0; font-size: 15px; color: #50575e; line-height: 1.6;">
                The following issue was detected and may require your attention:
            </p>
        </td>
    </tr>

    <!-- Warning Box -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                <tr>
                    <td style="background-color: #fcf6e5; border-left: 4px solid #dba617; padding: 20px; border-radius: 0 4px 4px 0;">
                        <p style="margin: 0; font-size: 15px; color: #1d2327; line-height: 1.5;">
                            {$warning_message}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {$details_html}

    <!-- Timestamp -->
    <tr>
        <td style="padding-bottom: 32px;">
            <p style="margin: 0; font-size: 13px; color: #646970;">
                <strong>Detected:</strong> {$time}
            </p>
        </td>
    </tr>

    {$button_html}
</table>
HTML;

		return $this->get_template( $content, 'warning' );
	}

	/**
	 * Build a success notification email.
	 *
	 * @param string $message     The main message.
	 * @param array  $summary     Summary data to display.
	 * @param string $action_url  Optional action URL.
	 * @param string $action_text Optional action button text.
	 * @return string Complete HTML email.
	 */
	public function build_success_email( string $message, array $summary = [], string $action_url = '', string $action_text = '' ): string {
		$time = current_time( 'F j, Y \a\t g:i A' );

		$summary_html = '';
		if ( ! empty( $summary ) ) {
			$summary_html = $this->build_summary_grid( $summary );
		}

		$button_html = '';
		if ( ! empty( $action_url ) && ! empty( $action_text ) ) {
			$button_html = <<<HTML
    <tr>
        <td style="padding-top: 8px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: #00a32a; border-radius: 4px;">
                        <a href="{$action_url}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 4px;">
                            {$action_text} &rarr;
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
HTML;
		}

		$content = <<<HTML
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
    <!-- Success Badge -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: #edfaef; border: 1px solid #00a32a; border-radius: 4px; padding: 8px 16px;">
                        <span style="color: #00a32a; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                            &#10003; Success
                        </span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Main Message -->
    <tr>
        <td style="padding-bottom: 24px;">
            <h2 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 600; color: #1d2327; line-height: 1.3;">
                {$message}
            </h2>
        </td>
    </tr>

    {$summary_html}

    <!-- Timestamp -->
    <tr>
        <td style="padding-bottom: 32px;">
            <p style="margin: 0; font-size: 13px; color: #646970;">
                <strong>Completed:</strong> {$time}
            </p>
        </td>
    </tr>

    {$button_html}
</table>
HTML;

		return $this->get_template( $content, 'success' );
	}

	/**
	 * Build details table from context array.
	 *
	 * @param array $context Context data.
	 * @return string HTML for details section.
	 */
	private function build_details_table( array $context ): string {
		if ( empty( $context ) ) {
			return '';
		}

		// Filter and format context for display.
		$display_items = $this->format_context_for_display( $context );

		if ( empty( $display_items ) ) {
			return '';
		}

		$rows = '';
		foreach ( $display_items as $label => $value ) {
			$escaped_label = esc_html( $label );
			$escaped_value = esc_html( $value );
			$rows         .= <<<HTML
                        <tr>
                            <td style="padding: 10px 12px; font-size: 13px; color: #646970; border-bottom: 1px solid #e0e0e0; width: 140px; vertical-align: top;">
                                {$escaped_label}
                            </td>
                            <td style="padding: 10px 12px; font-size: 13px; color: #1d2327; border-bottom: 1px solid #e0e0e0; font-family: 'SF Mono', Monaco, Consolas, monospace; word-break: break-all;">
                                {$escaped_value}
                            </td>
                        </tr>
HTML;
		}

		return <<<HTML
    <!-- Details Table -->
    <tr>
        <td style="padding-bottom: 24px;">
            <p style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #1d2327;">
                Details
            </p>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f6f7f7; border-radius: 4px; border: 1px solid #e0e0e0;">
                {$rows}
            </table>
        </td>
    </tr>
HTML;
	}

	/**
	 * Build summary grid for success emails.
	 *
	 * @param array $summary Summary data.
	 * @return string HTML for summary grid.
	 */
	private function build_summary_grid( array $summary ): string {
		$cells = '';
		$count = 0;

		foreach ( $summary as $label => $value ) {
			$escaped_label = esc_html( $label );
			$escaped_value = esc_html( $value );
			$cells        .= <<<HTML
                    <td style="padding: 16px; text-align: center; background-color: #f6f7f7; border-radius: 4px; width: 33%;">
                        <p style="margin: 0 0 4px 0; font-size: 24px; font-weight: 600; color: #1d2327;">
                            {$escaped_value}
                        </p>
                        <p style="margin: 0; font-size: 12px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">
                            {$escaped_label}
                        </p>
                    </td>
HTML;
			++$count;
			if ( $count % 3 === 0 && $count < count( $summary ) ) {
				$cells .= '</tr><tr>';
			}
		}

		return <<<HTML
    <!-- Summary Grid -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" cellspacing="8" cellpadding="0" border="0" width="100%">
                <tr>
                    {$cells}
                </tr>
            </table>
        </td>
    </tr>
HTML;
	}

	/**
	 * Format context array for human-readable display.
	 *
	 * @param array $context Raw context data.
	 * @return array Formatted label => value pairs.
	 */
	private function format_context_for_display( array $context ): array {
		$display = [];

		$label_map = [
			'order_id'         => 'Order ID',
			'order_number'     => 'Order Number',
			'invoice_id'       => 'Invoice ID',
			'contact_id'       => 'Contact ID',
			'payment_id'       => 'Payment ID',
			'customer_email'   => 'Customer Email',
			'amount'           => 'Amount',
			'currency'         => 'Currency',
			'contact_currency' => 'Contact Currency',
			'order_currency'   => 'Order Currency',
			'fee_currency'     => 'Fee Currency',
			'zoho_currency'    => 'Zoho Currency',
			'error_code'       => 'Error Code',
			'endpoint'         => 'API Endpoint',
			'retry_count'      => 'Retry Attempt',
			'sync_status'      => 'Sync Status',
		];

		foreach ( $context as $key => $value ) {
			// Skip internal/debug keys.
			if ( str_starts_with( $key, '_' ) || $key === 'exception' || $key === 'trace' ) {
				continue;
			}

			// Format arrays as JSON.
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}

			// Convert booleans.
			if ( is_bool( $value ) ) {
				$value = $value ? 'Yes' : 'No';
			}

			// Use friendly label if available.
			$label = $label_map[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );

			$display[ $label ] = (string) $value;
		}

		return $display;
	}

	/**
	 * Build a reconciliation report email.
	 *
	 * @param array  $summary       Report summary data.
	 * @param array  $discrepancies List of discrepancies (max 20 shown).
	 * @param string $period_start  Period start date.
	 * @param string $period_end    Period end date.
	 * @param string $report_url    URL to view full report.
	 * @return string Complete HTML email.
	 */
	public function build_reconciliation_email(
		array $summary,
		array $discrepancies,
		string $period_start,
		string $period_end,
		string $report_url
	): string {
		$has_discrepancies = ! empty( $discrepancies );
		$type              = $has_discrepancies ? 'warning' : 'success';

		// Build summary grid.
		$summary_html = $this->build_reconciliation_summary( $summary );

		// Build discrepancies table.
		$discrepancies_html = '';
		if ( $has_discrepancies ) {
			$discrepancies_html = $this->build_discrepancies_table( array_slice( $discrepancies, 0, 20 ) );
			if ( count( $discrepancies ) > 20 ) {
				$remaining           = count( $discrepancies ) - 20;
				$discrepancies_html .= <<<HTML
    <tr>
        <td style="padding: 16px; text-align: center; font-size: 13px; color: #646970; font-style: italic;">
            ... and {$remaining} more discrepancies. View full report for details.
        </td>
    </tr>
HTML;
			}
		}

		$status_badge = $has_discrepancies
			? '<span style="color: #996800; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">&#9888; Discrepancies Found</span>'
			: '<span style="color: #00a32a; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">&#10003; All Matched</span>';

		$badge_bg     = $has_discrepancies ? '#fcf6e5' : '#edfaef';
		$badge_border = $has_discrepancies ? '#dba617' : '#00a32a';

		$headline = $has_discrepancies
			? sprintf(
				/* translators: %d: Number of discrepancies */
				__( '%d discrepancies require attention', 'zbooks-for-woocommerce' ),
				count( $discrepancies )
			)
			: __( 'All records matched successfully', 'zbooks-for-woocommerce' );

		$content = <<<HTML
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
    <!-- Status Badge -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: {$badge_bg}; border: 1px solid {$badge_border}; border-radius: 4px; padding: 8px 16px;">
                        {$status_badge}
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Main Message -->
    <tr>
        <td style="padding-bottom: 16px;">
            <h2 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 600; color: #1d2327; line-height: 1.3;">
                Reconciliation Report
            </h2>
            <p style="margin: 0 0 8px 0; font-size: 15px; color: #50575e; line-height: 1.6;">
                {$headline}
            </p>
            <p style="margin: 0; font-size: 13px; color: #646970;">
                <strong>Period:</strong> {$period_start} to {$period_end}
            </p>
        </td>
    </tr>

    {$summary_html}

    {$discrepancies_html}

    <!-- CTA Button -->
    <tr>
        <td style="padding-top: 24px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: #0073aa; border-radius: 4px;">
                        <a href="{$report_url}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 4px;">
                            View Full Report &rarr;
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;

		return $this->get_template( $content, $type );
	}

	/**
	 * Build reconciliation summary section.
	 *
	 * @param array $summary Summary data.
	 * @return string HTML for summary section.
	 */
	private function build_reconciliation_summary( array $summary ): string {
		$wc_orders       = (int) ( $summary['total_wc_orders'] ?? 0 );
		$zoho_invoices   = (int) ( $summary['total_zoho_invoices'] ?? 0 );
		$matched         = (int) ( $summary['matched_count'] ?? 0 );
		$missing_in_zoho = (int) ( $summary['missing_in_zoho'] ?? 0 );
		$amount_mismatch = (int) ( $summary['amount_mismatches'] ?? 0 );
		$amount_diff     = (float) ( $summary['amount_difference'] ?? 0 );

		$missing_color  = $missing_in_zoho > 0 ? '#d63638' : '#1d2327';
		$mismatch_color = $amount_mismatch > 0 ? '#996800' : '#1d2327';
		$matched_color  = '#00a32a';
		$formatted_diff = function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $amount_diff ) ) : number_format( $amount_diff, 2 );

		return <<<HTML
    <!-- Summary Grid -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f6f7f7; border-radius: 8px; border: 1px solid #e0e0e0;">
                <tr>
                    <td style="padding: 20px;">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                            <tr>
                                <td width="33%" style="padding: 8px; text-align: center;">
                                    <p style="margin: 0 0 4px 0; font-size: 28px; font-weight: 700; color: #1d2327;">{$wc_orders}</p>
                                    <p style="margin: 0; font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">WC Orders</p>
                                </td>
                                <td width="33%" style="padding: 8px; text-align: center; border-left: 1px solid #e0e0e0; border-right: 1px solid #e0e0e0;">
                                    <p style="margin: 0 0 4px 0; font-size: 28px; font-weight: 700; color: #1d2327;">{$zoho_invoices}</p>
                                    <p style="margin: 0; font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">Zoho Invoices</p>
                                </td>
                                <td width="33%" style="padding: 8px; text-align: center;">
                                    <p style="margin: 0 0 4px 0; font-size: 28px; font-weight: 700; color: {$matched_color};">{$matched}</p>
                                    <p style="margin: 0; font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">Matched</p>
                                </td>
                            </tr>
                        </table>
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top: 16px; border-top: 1px solid #e0e0e0; padding-top: 16px;">
                            <tr>
                                <td width="33%" style="padding: 8px; text-align: center;">
                                    <p style="margin: 0 0 4px 0; font-size: 20px; font-weight: 600; color: {$missing_color};">{$missing_in_zoho}</p>
                                    <p style="margin: 0; font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">Missing in Zoho</p>
                                </td>
                                <td width="33%" style="padding: 8px; text-align: center; border-left: 1px solid #e0e0e0; border-right: 1px solid #e0e0e0;">
                                    <p style="margin: 0 0 4px 0; font-size: 20px; font-weight: 600; color: {$mismatch_color};">{$amount_mismatch}</p>
                                    <p style="margin: 0; font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">Amount Mismatch</p>
                                </td>
                                <td width="33%" style="padding: 8px; text-align: center;">
                                    <p style="margin: 0 0 4px 0; font-size: 20px; font-weight: 600; color: #1d2327;">{$formatted_diff}</p>
                                    <p style="margin: 0; font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">Total Difference</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
HTML;
	}

	/**
	 * Build discrepancies table for reconciliation email.
	 *
	 * @param array $discrepancies List of discrepancy items.
	 * @return string HTML for discrepancies table.
	 */
	private function build_discrepancies_table( array $discrepancies ): string {
		if ( empty( $discrepancies ) ) {
			return '';
		}

		$rows = '';
		foreach ( $discrepancies as $item ) {
			$type         = esc_html( $item['type'] ?? 'unknown' );
			$message      = esc_html( $item['message'] ?? '' );
			$order_number = esc_html( $item['order_number'] ?? '' );

			$type_color = match ( $type ) {
				'missing_in_zoho' => '#d63638',
				'amount_mismatch' => '#996800',
				'status_mismatch' => '#996800',
				default           => '#646970',
			};

			$type_label = match ( $type ) {
				'missing_in_zoho' => 'MISSING',
				'amount_mismatch' => 'AMOUNT',
				'status_mismatch' => 'STATUS',
				default           => strtoupper( $type ),
			};

			$order_info = ! empty( $order_number )
				? "<span style=\"font-size: 12px; color: #646970;\">Order #{$order_number}</span><br>"
				: '';

			$rows .= <<<HTML
                <tr>
                    <td style="padding: 12px 16px; border-bottom: 1px solid #e0e0e0; vertical-align: top; width: 80px;">
                        <span style="display: inline-block; padding: 4px 8px; background-color: rgba(0,0,0,0.05); border-radius: 3px; font-size: 10px; font-weight: 600; color: {$type_color}; text-transform: uppercase; letter-spacing: 0.5px;">
                            {$type_label}
                        </span>
                    </td>
                    <td style="padding: 12px 16px; border-bottom: 1px solid #e0e0e0; font-size: 13px; color: #1d2327; line-height: 1.5;">
                        {$order_info}
                        {$message}
                    </td>
                </tr>
HTML;
		}

		return <<<HTML
    <!-- Discrepancies Table -->
    <tr>
        <td style="padding-bottom: 8px;">
            <p style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #1d2327;">
                Discrepancies Found
            </p>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #ffffff; border-radius: 4px; border: 1px solid #e0e0e0;">
                {$rows}
            </table>
        </td>
    </tr>
HTML;
	}

	/**
	 * Build a digest email containing multiple notifications.
	 *
	 * @param array  $grouped  Notifications grouped by type (error, warning, success, info).
	 * @param string $severity Overall severity level for the email header.
	 * @return string Complete HTML email.
	 */
	public function build_digest_email( array $grouped, string $severity = 'info' ): string {
		$total_count = array_sum( array_map( 'count', $grouped ) );
		$time        = current_time( 'F j, Y \a\t g:i A' );
		$logs_url    = admin_url( 'admin.php?page=zbooks-log' );

		// Build sections for each notification type.
		$sections_html = '';

		// Process in severity order: errors first, then warnings, success, info.
		$type_order = [ 'error', 'warning', 'success', 'info' ];

		foreach ( $type_order as $type ) {
			if ( empty( $grouped[ $type ] ) ) {
				continue;
			}
			$sections_html .= $this->build_digest_section( $type, $grouped[ $type ] );
		}

		// Build status badge based on severity.
		$badge_config = match ( $severity ) {
			'error'   => [
				'bg'     => '#fcf0f1',
				'border' => '#f0b8b8',
				'color'  => '#d63638',
				'icon'   => '&#9888;',
				'label'  => 'Action Required',
			],
			'warning' => [
				'bg'     => '#fcf6e5',
				'border' => '#dba617',
				'color'  => '#996800',
				'icon'   => '&#9888;',
				'label'  => 'Attention Needed',
			],
			'success' => [
				'bg'     => '#edfaef',
				'border' => '#00a32a',
				'color'  => '#00a32a',
				'icon'   => '&#10003;',
				'label'  => 'All Good',
			],
			default   => [
				'bg'     => '#f0f6fc',
				'border' => '#0073aa',
				'color'  => '#0073aa',
				'icon'   => '&#8505;',
				'label'  => 'Notifications',
			],
		};

		$headline = sprintf(
			/* translators: %d: Number of notifications */
			_n(
				'%d notification from ZBooks',
				'%d notifications from ZBooks',
				$total_count,
				'zbooks-for-woocommerce'
			),
			$total_count
		);

		$content = <<<HTML
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
    <!-- Status Badge -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: {$badge_config['bg']}; border: 1px solid {$badge_config['border']}; border-radius: 4px; padding: 8px 16px;">
                        <span style="color: {$badge_config['color']}; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                            {$badge_config['icon']} {$badge_config['label']}
                        </span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Main Message -->
    <tr>
        <td style="padding-bottom: 24px;">
            <h2 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 600; color: #1d2327; line-height: 1.3;">
                {$headline}
            </h2>
            <p style="margin: 0; font-size: 13px; color: #646970;">
                <strong>Generated:</strong> {$time}
            </p>
        </td>
    </tr>

    {$sections_html}

    <!-- CTA Button -->
    <tr>
        <td style="padding-top: 16px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: #0073aa; border-radius: 4px;">
                        <a href="{$logs_url}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 4px;">
                            View Sync Logs &rarr;
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;

		return $this->get_template( $content, $severity );
	}

	/**
	 * Build a section for a specific notification type in the digest.
	 *
	 * @param string $type          Notification type (error, warning, success, info).
	 * @param array  $notifications Notifications of this type.
	 * @return string HTML for this section.
	 */
	private function build_digest_section( string $type, array $notifications ): string {
		$config = match ( $type ) {
			'error'   => [
				'bg'     => '#fcf0f1',
				'border' => '#d63638',
				'icon'   => '&#10060;',
				'title'  => __( 'Errors', 'zbooks-for-woocommerce' ),
			],
			'warning' => [
				'bg'     => '#fcf6e5',
				'border' => '#dba617',
				'icon'   => '&#9888;',
				'title'  => __( 'Warnings', 'zbooks-for-woocommerce' ),
			],
			'success' => [
				'bg'     => '#edfaef',
				'border' => '#00a32a',
				'icon'   => '&#10003;',
				'title'  => __( 'Completed', 'zbooks-for-woocommerce' ),
			],
			default   => [
				'bg'     => '#f0f6fc',
				'border' => '#0073aa',
				'icon'   => '&#8505;',
				'title'  => __( 'Information', 'zbooks-for-woocommerce' ),
			],
		};

		$count      = count( $notifications );
		$items_html = '';

		foreach ( $notifications as $notification ) {
			$title     = esc_html( $notification['title'] ?? '' );
			$message   = wp_kses_post( $notification['message'] ?? '' );
			$timestamp = esc_html( $notification['timestamp'] ?? '' );

			$items_html .= <<<HTML
                <tr>
                    <td style="padding: 12px 16px; border-bottom: 1px solid rgba(0,0,0,0.05);">
                        <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: #1d2327;">
                            {$title}
                        </p>
                        <p style="margin: 0 0 4px 0; font-size: 13px; color: #50575e; line-height: 1.5;">
                            {$message}
                        </p>
                        <p style="margin: 0; font-size: 11px; color: #8c8f94;">
                            {$timestamp}
                        </p>
                    </td>
                </tr>
HTML;
		}

		return <<<HTML
    <!-- {$config['title']} Section -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                <tr>
                    <td style="background-color: {$config['bg']}; border-left: 4px solid {$config['border']}; padding: 12px 16px; border-radius: 0 4px 4px 0;">
                        <p style="margin: 0; font-size: 14px; font-weight: 600; color: #1d2327;">
                            {$config['icon']} {$config['title']} ({$count})
                        </p>
                    </td>
                </tr>
            </table>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none; border-radius: 0 0 4px 4px;">
                {$items_html}
            </table>
        </td>
    </tr>
HTML;
	}

	/**
	 * Get accent color for email type.
	 *
	 * @param string $type Email type.
	 * @return string Hex color.
	 */
	private function get_accent_color( string $type ): string {
		return match ( $type ) {
			'error'   => self::COLORS['error'],
			'warning' => self::COLORS['warning'],
			'success' => self::COLORS['success'],
			default   => self::COLORS['primary'],
		};
	}
}
