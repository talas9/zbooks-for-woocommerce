<?php
/**
 * Orders settings tab for settings page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Api\ZohoClient;

defined( 'ABSPATH' ) || exit;

/**
 * Orders settings tab - handles order sync triggers, invoice numbering, and shipping settings.
 */
class OrdersTab {

	/**
	 * Zoho client.
	 *
	 * @var ZohoClient
	 */
	private ZohoClient $client;

	/**
	 * Constructor.
	 *
	 * @param ZohoClient $client Zoho client.
	 */
	public function __construct( ZohoClient $client ) {
		$this->client = $client;
	}

	/**
	 * Register settings for this tab.
	 */
	public function register_settings(): void {
		register_setting(
			'zbooks_settings_orders',
			'zbooks_sync_triggers',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_triggers' ],
			]
		);
		register_setting(
			'zbooks_settings_orders',
			'zbooks_invoice_numbering',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_invoice_numbering' ],
			]
		);
		register_setting(
			'zbooks_settings_orders',
			'zbooks_shipping_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_shipping_settings' ],
			]
		);

		// Order sync triggers section.
		add_settings_section(
			'zbooks_orders_section',
			__( 'Order Status Triggers', 'zbooks-for-woocommerce' ),
			[ $this, 'render_orders_section' ],
			'zbooks-settings-orders'
		);

		add_settings_field(
			'zbooks_sync_triggers',
			__( 'Status Actions', 'zbooks-for-woocommerce' ),
			[ $this, 'render_triggers_field' ],
			'zbooks-settings-orders',
			'zbooks_orders_section'
		);

		// Invoice numbering section.
		add_settings_section(
			'zbooks_invoice_numbering_section',
			__( 'Invoice Numbering', 'zbooks-for-woocommerce' ),
			[ $this, 'render_invoice_numbering_section' ],
			'zbooks-settings-orders'
		);

		add_settings_field(
			'zbooks_invoice_numbering',
			__( 'Order Number Handling', 'zbooks-for-woocommerce' ),
			[ $this, 'render_invoice_numbering_field' ],
			'zbooks-settings-orders',
			'zbooks_invoice_numbering_section'
		);

		// Shipping settings section.
		add_settings_section(
			'zbooks_shipping_section',
			__( 'Shipping Settings', 'zbooks-for-woocommerce' ),
			[ $this, 'render_shipping_section' ],
			'zbooks-settings-orders'
		);

		add_settings_field(
			'zbooks_shipping_settings',
			__( 'Shipping Account', 'zbooks-for-woocommerce' ),
			[ $this, 'render_shipping_settings_field' ],
			'zbooks-settings-orders',
			'zbooks_shipping_section'
		);

		// Currency info section.
		add_settings_section(
			'zbooks_currency_section',
			__( 'Currency Handling', 'zbooks-for-woocommerce' ),
			[ $this, 'render_currency_section' ],
			'zbooks-settings-orders'
		);
	}

	/**
	 * Render the tab content.
	 */
	public function render_content(): void {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'zbooks_settings_orders' );
			do_settings_sections( 'zbooks-settings-orders' );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Render orders section description.
	 */
	public function render_orders_section(): void {
		?>
		<p><?php esc_html_e( 'Configure which order statuses trigger automatic sync.', 'zbooks-for-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Render sync triggers field.
	 */
	public function render_triggers_field(): void {
		$triggers = get_option(
			'zbooks_sync_triggers',
			[
				'sync_draft'        => 'processing',
				'sync_submit'       => 'completed',
				'create_creditnote' => 'refunded',
			]
		);

		// Get all order statuses for the dropdowns.
		$all_statuses   = wc_get_order_statuses();
		$status_options = [ '' => __( '— None —', 'zbooks-for-woocommerce' ) ];
		foreach ( $all_statuses as $status_key => $status_label ) {
			$status                    = str_replace( 'wc-', '', $status_key );
			$status_options[ $status ] = $status_label;
		}

		// Define the fixed Zoho triggers.
		$zoho_triggers = [
			'sync_draft'        => [
				'label'       => __( 'Create draft invoice', 'zbooks-for-woocommerce' ),
				'description' => __( 'Invoice is created but not sent to customer.', 'zbooks-for-woocommerce' ),
				'default'     => 'processing',
			],
			'sync_submit'       => [
				'label'       => __( 'Create and submit invoice', 'zbooks-for-woocommerce' ),
				'description' => __( 'Invoice is created and marked as sent.', 'zbooks-for-woocommerce' ),
				'default'     => 'completed',
			],
			'create_creditnote' => [
				'label'       => __( 'Create credit note and refund', 'zbooks-for-woocommerce' ),
				'description' => __( 'Creates credit note for the original invoice and records refund.', 'zbooks-for-woocommerce' ),
				'default'     => 'refunded',
			],
		];
		?>
		<table class="widefat" style="max-width: 600px;">
			<thead>
				<tr>
					<th style="padding: 10px 15px;"><?php esc_html_e( 'Zoho Action', 'zbooks-for-woocommerce' ); ?></th>
					<th style="padding: 10px 15px;"><?php esc_html_e( 'Trigger on Status', 'zbooks-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $zoho_triggers as $trigger_key => $trigger_config ) :
					$current_status = $triggers[ $trigger_key ] ?? $trigger_config['default'];
					?>
					<tr>
						<td style="padding: 8px 15px;">
							<strong><?php echo esc_html( $trigger_config['label'] ); ?></strong>
							<p class="description" style="margin: 4px 0 0; font-size: 11px;">
								<?php echo esc_html( $trigger_config['description'] ); ?>
							</p>
						</td>
						<td style="padding: 8px 15px;">
							<select name="zbooks_sync_triggers[<?php echo esc_attr( $trigger_key ); ?>]" style="min-width: 150px;">
								<?php foreach ( $status_options as $status_value => $status_label ) : ?>
									<option value="<?php echo esc_attr( $status_value ); ?>" <?php selected( $current_status, $status_value ); ?>>
										<?php echo esc_html( $status_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render invoice numbering section description.
	 */
	public function render_invoice_numbering_section(): void {
		?>
		<p><?php esc_html_e( 'Configure how WooCommerce order numbers are mapped to Zoho Books invoice numbers.', 'zbooks-for-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Render invoice numbering field.
	 */
	public function render_invoice_numbering_field(): void {
		$settings      = get_option(
			'zbooks_invoice_numbering',
			[
				'use_reference_number' => true,
				'mark_as_sent'         => true,
				'send_invoice_email'   => false,
			]
		);
		$use_reference      = ! empty( $settings['use_reference_number'] );
		$mark_as_sent       = $settings['mark_as_sent'] ?? true;
		$send_invoice_email = ! empty( $settings['send_invoice_email'] );
		?>
		<fieldset>
			<label style="display: block; margin-bottom: 10px;">
				<input type="checkbox" name="zbooks_invoice_numbering[use_reference_number]" value="1"
					id="zbooks_use_reference_number"
					<?php checked( $use_reference ); ?>>
				<?php esc_html_e( 'Use Zoho auto-numbering series for invoice numbers', 'zbooks-for-woocommerce' ); ?>
				<strong style="color: #2271b1;"><?php esc_html_e( '(Recommended)', 'zbooks-for-woocommerce' ); ?></strong>
			</label>
			<p class="description">
				<?php esc_html_e( 'The WooCommerce order number is always stored in the "Reference Number" field for easy lookup in Zoho Books.', 'zbooks-for-woocommerce' ); ?>
			</p>
			<p class="description" style="margin-top: 8px;">
				<?php esc_html_e( 'When enabled (default), Zoho Books will auto-generate sequential invoice numbers (e.g., INV-00001, INV-00002).', 'zbooks-for-woocommerce' ); ?>
			</p>
			<p class="description" style="margin-top: 8px;">
				<?php esc_html_e( 'When disabled, the WooCommerce order number will also be used as the Zoho invoice number.', 'zbooks-for-woocommerce' ); ?>
			</p>
			<div class="zbooks-warning-box" id="zbooks_invoice_number_warning" style="display: <?php echo $use_reference ? 'none' : 'block'; ?>; background: #fff8e5; border: 1px solid #f0c36d; border-left-width: 4px; border-radius: 4px; padding: 12px 16px; margin-top: 15px;">
				<p style="margin: 0 0 8px; color: #826200;">
					<strong><span class="dashicons dashicons-warning" style="color: #dba617;"></span> <?php esc_html_e( 'Tax Audit Warning', 'zbooks-for-woocommerce' ); ?></strong>
				</p>
				<p style="margin: 0; color: #826200;">
					<?php esc_html_e( 'Using order numbers as invoice numbers may create gaps in your invoice sequence (e.g., if orders are cancelled or deleted). This can cause issues during tax audits in some jurisdictions where sequential invoice numbering is legally required.', 'zbooks-for-woocommerce' ); ?>
				</p>
			</div>

			<hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

			<label style="display: block; margin-bottom: 10px;">
				<input type="checkbox" name="zbooks_invoice_numbering[mark_as_sent]" value="1"
					id="zbooks_mark_as_sent"
					<?php checked( $mark_as_sent ); ?>>
				<?php esc_html_e( 'Mark invoices as "Sent" in Zoho Books', 'zbooks-for-woocommerce' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'When enabled, invoices are marked as "Sent" after creation. When disabled, invoices remain as "Draft".', 'zbooks-for-woocommerce' ); ?>
			</p>

			<hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

			<label style="display: block; margin-bottom: 10px;">
				<input type="checkbox" name="zbooks_invoice_numbering[send_invoice_email]" value="1"
					id="zbooks_send_invoice_email"
					<?php checked( $send_invoice_email ); ?>>
				<?php esc_html_e( 'Send invoice email to customer via Zoho Books', 'zbooks-for-woocommerce' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'When enabled, Zoho Books will automatically email the invoice to the customer when created.', 'zbooks-for-woocommerce' ); ?>
			</p>
			<p class="description" style="margin-top: 8px;">
				<strong><?php esc_html_e( 'Default: Disabled', 'zbooks-for-woocommerce' ); ?></strong> —
				<?php esc_html_e( 'Most stores handle customer notifications through WooCommerce. Enable only if you want Zoho Books to send its own invoice emails.', 'zbooks-for-woocommerce' ); ?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Render shipping section description.
	 */
	public function render_shipping_section(): void {
		?>
		<p><?php esc_html_e( 'Configure how shipping charges are recorded in Zoho Books invoices.', 'zbooks-for-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Render shipping settings field.
	 */
	public function render_shipping_settings_field(): void {
		$settings        = get_option(
			'zbooks_shipping_settings',
			[
				'account_id' => '',
			]
		);
		$income_accounts = $this->get_income_accounts();
		?>
		<select name="zbooks_shipping_settings[account_id]" style="min-width: 300px;">
			<option value=""><?php esc_html_e( 'Use default (Shipping Charge)', 'zbooks-for-woocommerce' ); ?></option>
			<?php foreach ( $income_accounts as $account ) : ?>
				<option value="<?php echo esc_attr( $account['account_id'] ); ?>"
					<?php selected( $settings['account_id'], $account['account_id'] ); ?>>
					<?php echo esc_html( $account['account_name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select the income account to record shipping charges. Leave empty to use Zoho\'s default "Shipping Charge" account.', 'zbooks-for-woocommerce' ); ?>
		</p>
		<?php if ( empty( $income_accounts ) && $this->client->is_configured() ) : ?>
			<p class="description" style="color: #d63638;">
				<?php esc_html_e( 'Could not load accounts from Zoho Books. Save settings and refresh the page.', 'zbooks-for-woocommerce' ); ?>
			</p>
		<?php elseif ( ! $this->client->is_configured() ) : ?>
			<p class="description">
				<?php esc_html_e( 'Configure Zoho connection first to load accounts.', 'zbooks-for-woocommerce' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render currency section description.
	 */
	public function render_currency_section(): void {
		?>
		<div class="zbooks-info-box">
			<p>
				<strong><?php esc_html_e( 'How currency is handled:', 'zbooks-for-woocommerce' ); ?></strong>
			</p>
			<ul style="list-style: disc; margin-left: 20px;">
				<li><?php esc_html_e( 'Currency is automatically taken from each WooCommerce order.', 'zbooks-for-woocommerce' ); ?></li>
				<li><?php esc_html_e( 'When a new contact is created in Zoho Books, it will be assigned the currency from the first order.', 'zbooks-for-woocommerce' ); ?></li>
				<li><?php esc_html_e( 'If an existing contact has a different currency than the order, the sync will fail with a clear error message.', 'zbooks-for-woocommerce' ); ?></li>
				<li><?php esc_html_e( 'Zoho Books does not allow changing a contact\'s currency after transactions exist.', 'zbooks-for-woocommerce' ); ?></li>
			</ul>
			<p class="description">
				<?php esc_html_e( 'If you have multi-currency orders, ensure customers use consistent email addresses per currency, or update the contact currency in Zoho Books before syncing.', 'zbooks-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get income accounts from Zoho Books.
	 *
	 * @return array List of income accounts.
	 */
	private function get_income_accounts(): array {
		// Check cache first.
		$cached = get_transient( 'zbooks_zoho_income_accounts' );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( ! $this->client->is_configured() ) {
			return [];
		}

		$accounts = [];

		try {
			$response = $this->client->request(
				function ( $client ) {
					return $client->chartofaccounts->getList(
						[
							'account_type' => 'income',
							'filter_by'    => 'AccountType.Active',
						]
					);
				},
				[
					'endpoint' => 'chartofaccounts.getList',
					'filter'   => 'income accounts',
				]
			);

			// Convert object to array if needed.
			if ( is_object( $response ) ) {
				$response = json_decode( wp_json_encode( $response ), true );
			}

			$coa_data = $response['chartofaccounts'] ?? $response ?? [];

			foreach ( $coa_data as $account ) {
				$account_id = $account['account_id'] ?? '';
				if ( $account_id ) {
					$accounts[] = [
						'account_id'   => $account_id,
						'account_name' => $account['account_name'] ?? '',
					];
				}
			}

			// Sort by name.
			usort(
				$accounts,
				function ( $a, $b ) {
					return strcasecmp( $a['account_name'], $b['account_name'] );
				}
			);

			// Cache for 1 hour.
			set_transient( 'zbooks_zoho_income_accounts', $accounts, HOUR_IN_SECONDS );
		} catch ( \Exception $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentionally showing empty dropdown on error.
			unset( $e );
		}

		return $accounts;
	}

	/**
	 * Sanitize triggers.
	 *
	 * @param array $input Input data.
	 * @return array
	 */
	public function sanitize_triggers( array $input ): array {
		// Valid trigger types.
		$valid_triggers = [ 'sync_draft', 'sync_submit', 'create_creditnote' ];

		// Get valid order statuses.
		$valid_statuses   = array_keys( wc_get_order_statuses() );
		$valid_statuses   = array_map(
			function ( $status ) {
				return str_replace( 'wc-', '', $status );
			},
			$valid_statuses
		);
		$valid_statuses[] = ''; // Allow empty (disabled).

		$sanitized = [];

		foreach ( $input as $trigger => $status ) {
			$trigger = sanitize_key( $trigger );
			$status  = sanitize_key( $status );

			// Validate trigger type and status.
			if ( in_array( $trigger, $valid_triggers, true ) && in_array( $status, $valid_statuses, true ) ) {
				$sanitized[ $trigger ] = $status;
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize invoice numbering settings.
	 *
	 * @param array $input Input data.
	 * @return array
	 */
	public function sanitize_invoice_numbering( array $input ): array {
		return [
			'use_reference_number' => ! empty( $input['use_reference_number'] ),
			'mark_as_sent'         => ! empty( $input['mark_as_sent'] ),
			'send_invoice_email'   => ! empty( $input['send_invoice_email'] ),
		];
	}

	/**
	 * Sanitize shipping settings.
	 *
	 * @param array $input Input data.
	 * @return array
	 */
	public function sanitize_shipping_settings( array $input ): array {
		return [
			'account_id' => isset( $input['account_id'] )
				? sanitize_text_field( $input['account_id'] )
				: '',
		];
	}
}
