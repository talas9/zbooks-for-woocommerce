<?php
/**
 * Connection settings tab for settings page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Api\ZohoClient;
use Zbooks\Api\TokenManager;

defined( 'ABSPATH' ) || exit;

/**
 * Connection settings tab - handles Zoho API credentials and organization settings.
 */
class ConnectionTab {

	/**
	 * Zoho client.
	 *
	 * @var ZohoClient
	 */
	private ZohoClient $client;

	/**
	 * Token manager.
	 *
	 * @var TokenManager
	 */
	private TokenManager $token_manager;

	/**
	 * Constructor.
	 *
	 * @param ZohoClient   $client        Zoho client.
	 * @param TokenManager $token_manager Token manager.
	 */
	public function __construct( ZohoClient $client, TokenManager $token_manager ) {
		$this->client        = $client;
		$this->token_manager = $token_manager;

		// Register AJAX handlers.
		add_action( 'wp_ajax_zbooks_authenticate', [ $this, 'ajax_authenticate' ] );
		add_action( 'wp_ajax_zbooks_save_organization', [ $this, 'ajax_save_organization' ] );
		add_action( 'wp_ajax_zbooks_test_connection', [ $this, 'ajax_test_connection' ] );
	}

	/**
	 * Register settings for this tab.
	 */
	public function register_settings(): void {
		// Only register organization setting - credentials handled via AJAX.
		register_setting(
			'zbooks_settings_connection',
			'zbooks_organization_id',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			]
		);
	}

	/**
	 * Render the tab content.
	 */
	public function render_content(): void {
		$credentials    = $this->token_manager->get_credentials();
		$is_configured  = $this->client->is_configured();
		$has_org        = ! empty( get_option( 'zbooks_organization_id' ) );
		$current_dc     = get_option( 'zbooks_datacenter', 'us' );
		$current_org_id = get_option( 'zbooks_organization_id', '' );

		$datacenters = [
			'us' => 'United States (zoho.com)',
			'eu' => 'Europe (zoho.eu)',
			'in' => 'India (zoho.in)',
			'au' => 'Australia (zoho.com.au)',
			'jp' => 'Japan (zoho.jp)',
		];
		?>
		<div class="zbooks-connection-tab">
			<!-- API Credentials Section -->
			<h2><?php esc_html_e( 'Zoho API Credentials', 'zbooks-for-woocommerce' ); ?></h2>

			<?php if ( $is_configured ) : ?>
				<!-- Connected State -->
				<div id="zbooks-auth-success-panel" style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: 20px; margin: 15px 0;">
					<p style="margin: 0 0 10px 0; font-size: 14px;">
						<span class="dashicons dashicons-yes-alt" style="color: #155724; font-size: 20px; vertical-align: middle;"></span>
						<strong style="color: #155724;"><?php esc_html_e( 'Connected to Zoho Books', 'zbooks-for-woocommerce' ); ?></strong>
					</p>
					<p style="margin: 0 0 15px 0; color: #155724;">
						<?php
						printf(
							/* translators: %s: datacenter name */
							esc_html__( 'Datacenter: %s', 'zbooks-for-woocommerce' ),
							'<strong>' . esc_html( $datacenters[ $current_dc ] ?? $current_dc ) . '</strong>'
						);
						?>
					</p>
					<button type="button" id="zbooks-show-credentials-btn" class="button">
						<span class="dashicons dashicons-admin-network" style="vertical-align: middle;"></span>
						<?php esc_html_e( 'Change Credentials', 'zbooks-for-woocommerce' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<!-- Credentials Form (hidden if already configured) -->
			<div id="zbooks-credentials-form" <?php echo $is_configured ? 'style="display: none;"' : ''; ?>>
				<p>
					<?php
					printf(
						/* translators: %s: Zoho API console URL */
						esc_html__( 'Get your API credentials from the %s.', 'zbooks-for-woocommerce' ),
						'<a href="https://api-console.zoho.com/" target="_blank">Zoho API Console</a>'
					);
					?>
				</p>
				<p style="margin-top: 10px;">
					<label style="display: block; margin-bottom: 5px;">
						<strong><?php esc_html_e( 'Required Scope:', 'zbooks-for-woocommerce' ); ?></strong>
					</label>
					<input type="text" value="ZohoBooks.fullaccess.all" readonly
						class="regular-text" style="background: #f0f0f1; cursor: text;"
						onclick="this.select();"
						title="<?php esc_attr_e( 'Click to select and copy', 'zbooks-for-woocommerce' ); ?>">
					<span class="description" style="display: block; margin-top: 5px;">
						<?php esc_html_e( 'Use this scope when generating your authorization code in the Zoho API Console.', 'zbooks-for-woocommerce' ); ?>
					</span>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="zbooks_datacenter"><?php esc_html_e( 'Datacenter', 'zbooks-for-woocommerce' ); ?></label>
						</th>
						<td>
							<select name="zbooks_datacenter" id="zbooks_datacenter" class="zbooks-credential-field">
								<?php foreach ( $datacenters as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_dc, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="zbooks_client_id"><?php esc_html_e( 'Client ID', 'zbooks-for-woocommerce' ); ?></label>
						</th>
						<td>
							<input type="text" id="zbooks_client_id" class="regular-text zbooks-credential-field"
								value=""
								placeholder="<?php echo esc_attr( ! empty( $credentials['client_id'] ) ? __( 'Enter new or leave blank to keep existing', 'zbooks-for-woocommerce' ) : '' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="zbooks_client_secret"><?php esc_html_e( 'Client Secret', 'zbooks-for-woocommerce' ); ?></label>
						</th>
						<td>
							<input type="password" id="zbooks_client_secret" class="regular-text zbooks-credential-field"
								value=""
								placeholder="<?php echo esc_attr( ! empty( $credentials['client_secret'] ) ? __( 'Enter new or leave blank to keep existing', 'zbooks-for-woocommerce' ) : '' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="zbooks_refresh_token"><?php esc_html_e( 'Grant Code / Refresh Token', 'zbooks-for-woocommerce' ); ?></label>
						</th>
						<td>
							<input type="password" id="zbooks_refresh_token" class="regular-text zbooks-credential-field"
								value=""
								placeholder="<?php echo esc_attr( ! empty( $credentials['refresh_token'] ) ? __( 'Enter new grant code to re-authenticate', 'zbooks-for-woocommerce' ) : '' ); ?>">
							<p class="description">
								<?php esc_html_e( 'Enter the grant code from Zoho API Console. It will be exchanged for a refresh token automatically.', 'zbooks-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"></th>
						<td>
							<button type="button" id="zbooks-authenticate-btn" class="button button-primary">
								<?php esc_html_e( 'Authenticate', 'zbooks-for-woocommerce' ); ?>
							</button>
							<?php if ( $is_configured ) : ?>
								<button type="button" id="zbooks-cancel-reauth-btn" class="button" style="margin-left: 10px;">
									<?php esc_html_e( 'Cancel', 'zbooks-for-woocommerce' ); ?>
								</button>
							<?php endif; ?>
							<span class="spinner" style="float: none; margin-top: 0;"></span>
							<span id="zbooks-auth-status" style="margin-left: 10px;"></span>
						</td>
					</tr>
				</table>
			</div>

			<hr style="margin: 30px 0;">

			<!-- Organization Section -->
			<h2><?php esc_html_e( 'Organization', 'zbooks-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Select your Zoho Books organization.', 'zbooks-for-woocommerce' ); ?></p>

			<?php if ( ! $is_configured ) : ?>
				<div class="zbooks-auth-warning" style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 15px; margin: 15px 0;">
					<p style="margin: 0; color: #856404;">
						<span class="dashicons dashicons-warning" style="color: #856404;"></span>
						<?php esc_html_e( 'Please authenticate first to load organizations.', 'zbooks-for-woocommerce' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<style>
				.zbooks-org-selector {
					position: relative;
					max-width: 450px;
				}
				.zbooks-org-selected {
					background: #fff;
					border: 1px solid #8c8f94;
					border-radius: 4px;
					padding: 12px 40px 12px 15px;
					cursor: pointer;
					transition: all 0.2s;
					min-height: 60px;
					display: flex;
					align-items: center;
				}
				.zbooks-org-selected:hover {
					border-color: #2271b1;
				}
				.zbooks-org-selected:focus {
					border-color: #2271b1;
					box-shadow: 0 0 0 1px #2271b1;
					outline: none;
				}
				.zbooks-org-selected::after {
					content: '';
					position: absolute;
					right: 15px;
					top: 50%;
					transform: translateY(-50%);
					border: 5px solid transparent;
					border-top-color: #50575e;
					transition: transform 0.2s;
				}
				.zbooks-org-selector.is-open .zbooks-org-selected::after {
					transform: translateY(-50%) rotate(180deg);
				}
				.zbooks-org-selected .org-placeholder {
					color: #757575;
				}
				.zbooks-org-dropdown {
					position: absolute;
					top: 100%;
					left: 0;
					right: 0;
					background: #fff;
					border: 1px solid #8c8f94;
					border-top: none;
					border-radius: 0 0 4px 4px;
					max-height: 300px;
					overflow-y: auto;
					z-index: 100;
					display: none;
					box-shadow: 0 4px 12px rgba(0,0,0,0.15);
				}
				.zbooks-org-selector.is-open .zbooks-org-dropdown {
					display: block;
				}
				.zbooks-org-search {
					padding: 10px;
					border-bottom: 1px solid #ddd;
					position: sticky;
					top: 0;
					background: #fff;
				}
				.zbooks-org-search input {
					width: 100%;
					padding: 8px 12px;
					border: 1px solid #ddd;
					border-radius: 4px;
				}
				.zbooks-org-item {
					padding: 12px 15px;
					cursor: pointer;
					border-bottom: 1px solid #f0f0f1;
					transition: background 0.15s;
				}
				.zbooks-org-item:hover {
					background: #f0f7fc;
				}
				.zbooks-org-item.is-selected {
					background: #e5f3ff;
					border-left: 3px solid #2271b1;
				}
				.zbooks-org-item:last-child {
					border-bottom: none;
				}
				.zbooks-org-item .org-name {
					font-weight: 600;
					font-size: 14px;
					color: #1d2327;
					margin-bottom: 4px;
				}
				.zbooks-org-item .org-meta {
					font-size: 12px;
					color: #666;
					display: flex;
					flex-wrap: wrap;
					gap: 10px;
				}
				.zbooks-org-item .org-meta span {
					display: inline-flex;
					align-items: center;
					gap: 3px;
				}
				.zbooks-org-item .org-meta .dashicons {
					font-size: 14px;
					width: 14px;
					height: 14px;
				}
				.zbooks-org-selected .org-name {
					font-weight: 600;
					font-size: 14px;
					color: #1d2327;
				}
				.zbooks-org-selected .org-meta {
					font-size: 12px;
					color: #666;
					margin-top: 2px;
				}
				.zbooks-org-empty {
					padding: 20px;
					text-align: center;
					color: #666;
				}
			</style>

			<div id="zbooks-org-container" style="margin: 20px 0;">
				<?php
				$orgs_data     = [];
				$org_error_msg = '';
				if ( $is_configured ) {
					try {
						$orgs_data = $this->client->get_organizations();
					} catch ( \Exception $e ) {
						$org_error_msg = $e->getMessage();
						?>
						<div class="zbooks-org-error" style="background: #fff5f5; border: 1px solid #f8d7da; border-left: 4px solid #dc3545; border-radius: 4px; padding: 12px 15px; margin-bottom: 15px; max-width: 450px;">
							<p style="color: #721c24; font-weight: 600; margin: 0 0 5px 0;">
								<span class="dashicons dashicons-warning" style="color: #dc3545; vertical-align: middle;"></span>
								<?php esc_html_e( 'Unable to load organizations', 'zbooks-for-woocommerce' ); ?>
							</p>
							<p style="color: #721c24; margin: 0; font-size: 13px;">
								<?php echo esc_html( $org_error_msg ); ?>
							</p>
						</div>
						<?php
					}
				}

				// Find current org details.
				$current_org = null;
				foreach ( $orgs_data as $org ) {
					if ( $org['organization_id'] === $current_org_id ) {
						$current_org = $org;
						break;
					}
				}
				?>

				<!-- Hidden input for form submission -->
				<input type="hidden" id="zbooks_organization_id" name="zbooks_organization_id" value="<?php echo esc_attr( $current_org_id ); ?>">

				<!-- Custom Rich Dropdown -->
				<div class="zbooks-org-selector" id="zbooks-org-selector" <?php echo ( ! $is_configured || empty( $orgs_data ) ) ? 'style="pointer-events: none; opacity: 0.6;"' : ''; ?>>
					<div class="zbooks-org-selected" tabindex="0">
						<?php if ( $current_org ) : ?>
							<div class="org-content">
								<div class="org-name"><?php echo esc_html( $current_org['name'] ); ?></div>
								<div class="org-meta">
									<?php if ( ! empty( $current_org['country'] ) ) : ?>
										<span><span class="dashicons dashicons-location"></span><?php echo esc_html( $current_org['country'] ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $current_org['currency_code'] ) ) : ?>
										<span><span class="dashicons dashicons-money-alt"></span><?php echo esc_html( $current_org['currency_code'] ); ?></span>
									<?php endif; ?>
								</div>
							</div>
						<?php else : ?>
							<span class="org-placeholder"><?php esc_html_e( '— Select Organization —', 'zbooks-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="zbooks-org-dropdown">
						<?php if ( count( $orgs_data ) > 5 ) : ?>
							<div class="zbooks-org-search">
								<input type="text" placeholder="<?php esc_attr_e( 'Search organizations...', 'zbooks-for-woocommerce' ); ?>" id="zbooks-org-search-input">
							</div>
						<?php endif; ?>
						<div class="zbooks-org-list">
							<?php if ( empty( $orgs_data ) ) : ?>
								<div class="zbooks-org-empty">
									<?php esc_html_e( 'No organizations available', 'zbooks-for-woocommerce' ); ?>
								</div>
							<?php else : ?>
								<?php foreach ( $orgs_data as $org ) : ?>
									<div class="zbooks-org-item <?php echo ( $current_org_id === $org['organization_id'] ) ? 'is-selected' : ''; ?>"
										data-id="<?php echo esc_attr( $org['organization_id'] ); ?>"
										data-name="<?php echo esc_attr( $org['name'] ); ?>"
										data-country="<?php echo esc_attr( $org['country'] ?? '' ); ?>"
										data-currency="<?php echo esc_attr( $org['currency_code'] ?? '' ); ?>"
										data-currency-symbol="<?php echo esc_attr( $org['currency_symbol'] ?? '' ); ?>"
										data-fiscal="<?php echo esc_attr( $org['fiscal_year_start_month'] ?? '' ); ?>">
										<div class="org-name"><?php echo esc_html( $org['name'] ); ?></div>
										<div class="org-meta">
											<span><span class="dashicons dashicons-admin-generic"></span>ID: <?php echo esc_html( $org['organization_id'] ); ?></span>
											<?php if ( ! empty( $org['country'] ) ) : ?>
												<span><span class="dashicons dashicons-location"></span><?php echo esc_html( $org['country'] ); ?></span>
											<?php endif; ?>
											<?php if ( ! empty( $org['currency_code'] ) ) : ?>
												<span><span class="dashicons dashicons-money-alt"></span><?php echo esc_html( $org['currency_code'] ); ?></span>
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">
					<button type="button" id="zbooks-save-org-btn" class="button button-primary" <?php disabled( ! $is_configured || empty( $orgs_data ) ); ?>>
						<?php esc_html_e( 'Save Organization', 'zbooks-for-woocommerce' ); ?>
					</button>
					<span class="spinner" style="float: none; margin-top: 0;"></span>
					<span id="zbooks-org-status"></span>
					<?php if ( $has_org ) : ?>
						<span class="zbooks-org-saved" style="color: #46b450;">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Saved', 'zbooks-for-woocommerce' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<hr style="margin: 30px 0;">

			<!-- Connection Status Section -->
			<h2><?php esc_html_e( 'Connection Status', 'zbooks-for-woocommerce' ); ?></h2>

			<style>
				.zbooks-status-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
					gap: 15px;
					max-width: 800px;
				}
				.zbooks-status-card {
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 8px;
					padding: 16px;
					text-align: center;
					transition: all 0.3s ease;
				}
				.zbooks-status-card:hover {
					box-shadow: 0 2px 8px rgba(0,0,0,0.08);
				}
				.zbooks-status-card .status-icon {
					width: 40px;
					height: 40px;
					border-radius: 50%;
					display: flex;
					align-items: center;
					justify-content: center;
					margin: 0 auto 10px;
					font-size: 20px;
					transition: all 0.3s ease;
					overflow: hidden;
					border: none !important;
					border-left: none !important;
				}
				.zbooks-status-card .status-icon .dashicons {
					line-height: 1;
					width: 20px;
					height: 20px;
					font-size: 20px;
					border: none !important;
				}
				.zbooks-status-card .status-icon.is-success {
					background: #d4edda;
					color: #155724;
				}
				.zbooks-status-card .status-icon.is-error {
					background: #f8d7da;
					color: #721c24;
					border: none !important;
					border-left: none !important;
				}
				.zbooks-status-card .status-icon.is-loading {
					background: #e2e3e5;
					color: #383d41;
				}
				.zbooks-status-card .status-label {
					font-size: 12px;
					color: #666;
					margin-bottom: 4px;
					text-transform: uppercase;
					letter-spacing: 0.5px;
				}
				.zbooks-status-card .status-value {
					font-size: 14px;
					font-weight: 600;
					color: #23282d;
				}
				.zbooks-status-card .status-value.is-success { color: #155724; }
				.zbooks-status-card .status-value.is-error { color: #721c24; }
				.zbooks-status-card .status-value.is-loading { color: #666; }
				@keyframes zbooks-pulse {
					0%, 100% { opacity: 1; transform: scale(1); }
					50% { opacity: 0.7; transform: scale(0.95); }
				}
				.zbooks-status-card.is-testing .status-icon {
					animation: zbooks-pulse 1.5s ease-in-out infinite;
				}
				@keyframes zbooks-spin {
					from { transform: rotate(0deg); }
					to { transform: rotate(360deg); }
				}
				.zbooks-status-card.is-testing .status-icon .dashicons {
					animation: zbooks-spin 1s linear infinite;
				}
				/* Error details box */
				.zbooks-error-details {
					background: #fff5f5;
					border: 1px solid #f8d7da;
					border-left: 4px solid #dc3545;
					border-radius: 4px;
					padding: 15px;
					margin-top: 15px;
					max-width: 800px;
					display: none;
				}
				.zbooks-error-details.is-visible {
					display: block;
				}
				.zbooks-error-details .error-title {
					font-weight: 600;
					color: #721c24;
					margin: 0 0 8px 0;
					display: flex;
					align-items: center;
					gap: 8px;
				}
				.zbooks-error-details .error-title .dashicons {
					color: #dc3545;
				}
				.zbooks-error-details .error-message {
					color: #721c24;
					font-family: monospace;
					font-size: 13px;
					background: #fff;
					padding: 10px;
					border-radius: 4px;
					margin: 0;
					word-break: break-word;
					white-space: pre-wrap;
				}
			</style>

			<div class="zbooks-status-grid">
				<!-- API Credentials -->
				<div class="zbooks-status-card" id="zbooks-card-credentials">
					<div class="status-icon <?php echo $is_configured ? 'is-success' : 'is-error'; ?>">
						<span class="dashicons <?php echo $is_configured ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
					</div>
					<div class="status-label"><?php esc_html_e( 'API Credentials', 'zbooks-for-woocommerce' ); ?></div>
					<div class="status-value zbooks-status-credentials <?php echo $is_configured ? 'is-success' : 'is-error'; ?>">
						<?php echo $is_configured ? esc_html__( 'Configured', 'zbooks-for-woocommerce' ) : esc_html__( 'Not configured', 'zbooks-for-woocommerce' ); ?>
					</div>
				</div>

				<!-- Organization -->
				<div class="zbooks-status-card" id="zbooks-card-organization">
					<div class="status-icon <?php echo $has_org ? 'is-success' : 'is-error'; ?>">
						<span class="dashicons <?php echo $has_org ? 'dashicons-building' : 'dashicons-warning'; ?>"></span>
					</div>
					<div class="status-label"><?php esc_html_e( 'Organization', 'zbooks-for-woocommerce' ); ?></div>
					<div class="status-value zbooks-status-organization <?php echo $has_org ? 'is-success' : 'is-error'; ?>">
						<?php echo $has_org ? esc_html__( 'Selected', 'zbooks-for-woocommerce' ) : esc_html__( 'Not selected', 'zbooks-for-woocommerce' ); ?>
					</div>
				</div>

				<!-- API Connection -->
				<div class="zbooks-status-card" id="zbooks-card-connection">
					<div class="status-icon is-loading">
						<span class="dashicons dashicons-update"></span>
					</div>
					<div class="status-label"><?php esc_html_e( 'API Connection', 'zbooks-for-woocommerce' ); ?></div>
					<div class="status-value zbooks-status-connection is-loading">
						<?php esc_html_e( 'Checking...', 'zbooks-for-woocommerce' ); ?>
					</div>
				</div>

				<!-- Ready to Sync -->
				<div class="zbooks-status-card" id="zbooks-card-ready">
					<div class="status-icon <?php echo ( $is_configured && $has_org ) ? 'is-success' : 'is-error'; ?>">
						<span class="dashicons <?php echo ( $is_configured && $has_org ) ? 'dashicons-yes-alt' : 'dashicons-minus'; ?>"></span>
					</div>
					<div class="status-label"><?php esc_html_e( 'Ready to Sync', 'zbooks-for-woocommerce' ); ?></div>
					<div class="status-value zbooks-status-ready <?php echo ( $is_configured && $has_org ) ? 'is-success' : 'is-error'; ?>">
						<?php echo ( $is_configured && $has_org ) ? esc_html__( 'Yes', 'zbooks-for-woocommerce' ) : esc_html__( 'No', 'zbooks-for-woocommerce' ); ?>
					</div>
				</div>
			</div>

			<!-- Error Details Box -->
			<div class="zbooks-error-details" id="zbooks-error-details">
				<p class="error-title">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Connection Error Details', 'zbooks-for-woocommerce' ); ?>
				</p>
				<pre class="error-message" id="zbooks-error-message"></pre>
			</div>

			<div style="margin-top: 20px;">
				<button type="button" id="zbooks-test-connection-btn" class="button" <?php disabled( ! $is_configured ); ?>>
					<span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
					<?php esc_html_e( 'Test Connection', 'zbooks-for-woocommerce' ); ?>
				</button>
				<span class="description" style="margin-left: 10px;">
					<?php esc_html_e( 'Click to verify API connection', 'zbooks-for-woocommerce' ); ?>
				</span>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var $authBtn = $('#zbooks-authenticate-btn');
			var $saveOrgBtn = $('#zbooks-save-org-btn');
			var $credentialFields = $('.zbooks-credential-field');
			var $orgSelect = $('#zbooks_organization_id');
			var $orgDetails = $('#zbooks-org-details');
			var $successPanel = $('#zbooks-auth-success-panel');
			var $credentialsForm = $('#zbooks-credentials-form');
			var $showCredentialsBtn = $('#zbooks-show-credentials-btn');
			var $cancelReauthBtn = $('#zbooks-cancel-reauth-btn');
			var $testConnectionBtn = $('#zbooks-test-connection-btn');
			var isConfigured = <?php echo $is_configured ? 'true' : 'false'; ?>;

			// Status indicator HTML templates.
			var statusYes = '<span style="color: #46b450;"><span class="dashicons dashicons-yes"></span> ';
			var statusNo = '<span style="color: #d63638;"><span class="dashicons dashicons-no"></span> ';

			// Update status card.
			function updateStatusCard(cardId, isSuccess, text, icon) {
				var $card = $('#' + cardId);
				var $icon = $card.find('.status-icon');
				var $value = $card.find('.status-value');

				$card.removeClass('is-testing');
				$icon.removeClass('is-success is-error is-loading').addClass(isSuccess ? 'is-success' : 'is-error');
				$icon.find('.dashicons').attr('class', 'dashicons ' + icon);
				$value.removeClass('is-success is-error is-loading').addClass(isSuccess ? 'is-success' : 'is-error').text(text);
			}

			// Update connection status cards.
			function updateConnectionStatus(apiConfigured, orgSelected) {
				// API Credentials card.
				updateStatusCard('zbooks-card-credentials', apiConfigured,
					apiConfigured ? '<?php echo esc_js( __( 'Configured', 'zbooks-for-woocommerce' ) ); ?>' : '<?php echo esc_js( __( 'Not configured', 'zbooks-for-woocommerce' ) ); ?>',
					apiConfigured ? 'dashicons-yes-alt' : 'dashicons-warning'
				);

				// Organization card.
				updateStatusCard('zbooks-card-organization', orgSelected,
					orgSelected ? '<?php echo esc_js( __( 'Selected', 'zbooks-for-woocommerce' ) ); ?>' : '<?php echo esc_js( __( 'Not selected', 'zbooks-for-woocommerce' ) ); ?>',
					orgSelected ? 'dashicons-building' : 'dashicons-warning'
				);

				// Ready to Sync card.
				var isReady = apiConfigured && orgSelected;
				updateStatusCard('zbooks-card-ready', isReady,
					isReady ? '<?php echo esc_js( __( 'Yes', 'zbooks-for-woocommerce' ) ); ?>' : '<?php echo esc_js( __( 'No', 'zbooks-for-woocommerce' ) ); ?>',
					isReady ? 'dashicons-yes-alt' : 'dashicons-minus'
				);
			}

			// Test connection function.
			function testConnection() {
				var $card = $('#zbooks-card-connection');
				var $icon = $card.find('.status-icon');
				var $value = $card.find('.status-value');
				var $errorDetails = $('#zbooks-error-details');
				var $errorMessage = $('#zbooks-error-message');

				// Hide error details initially.
				$errorDetails.removeClass('is-visible');

				if (!isConfigured) {
					$icon.removeClass('is-success is-loading').addClass('is-error');
					$icon.find('.dashicons').attr('class', 'dashicons dashicons-warning');
					$value.removeClass('is-success is-loading').addClass('is-error').text('<?php echo esc_js( __( 'Not configured', 'zbooks-for-woocommerce' ) ); ?>');
					return;
				}

				// Show loading state.
				$card.addClass('is-testing');
				$icon.removeClass('is-success is-error').addClass('is-loading');
				$icon.find('.dashicons').attr('class', 'dashicons dashicons-update');
				$value.removeClass('is-success is-error').addClass('is-loading').text('<?php echo esc_js( __( 'Testing...', 'zbooks-for-woocommerce' ) ); ?>');
				$testConnectionBtn.prop('disabled', true);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'zbooks_test_connection',
						nonce: '<?php echo esc_js( wp_create_nonce( 'zbooks_test_connection' ) ); ?>'
					},
					success: function(response) {
						$card.removeClass('is-testing');
						$testConnectionBtn.prop('disabled', false);

						if (response.success) {
							$icon.removeClass('is-loading is-error').addClass('is-success');
							$icon.find('.dashicons').attr('class', 'dashicons dashicons-cloud');
							$value.removeClass('is-loading is-error').addClass('is-success').text('<?php echo esc_js( __( 'Connected', 'zbooks-for-woocommerce' ) ); ?>');
							$errorDetails.removeClass('is-visible');
						} else {
							$icon.removeClass('is-loading is-success').addClass('is-error');
							$icon.find('.dashicons').attr('class', 'dashicons dashicons-warning');
							$value.removeClass('is-loading is-success').addClass('is-error').text('<?php echo esc_js( __( 'Failed', 'zbooks-for-woocommerce' ) ); ?>');

							// Show error details.
							var errorMsg = response.data.message || '<?php echo esc_js( __( 'Unknown error occurred', 'zbooks-for-woocommerce' ) ); ?>';
							$errorMessage.text(errorMsg);
							$errorDetails.addClass('is-visible');
						}
					},
					error: function(xhr, status, error) {
						$card.removeClass('is-testing');
						$testConnectionBtn.prop('disabled', false);
						$icon.removeClass('is-loading is-success').addClass('is-error');
						$icon.find('.dashicons').attr('class', 'dashicons dashicons-warning');
						$value.removeClass('is-loading is-success').addClass('is-error').text('<?php echo esc_js( __( 'Error', 'zbooks-for-woocommerce' ) ); ?>');

						// Show error details.
						var errorMsg = '<?php echo esc_js( __( 'Request failed', 'zbooks-for-woocommerce' ) ); ?>: ' + (error || status);
						$errorMessage.text(errorMsg);
						$errorDetails.addClass('is-visible');
					}
				});
			}

			// Auto-test connection on page load.
			testConnection();

			// Test connection button click.
			$testConnectionBtn.on('click', function() {
				testConnection();
			});

			// Rich Organization Dropdown.
			var $orgSelector = $('#zbooks-org-selector');
			var $orgSelected = $orgSelector.find('.zbooks-org-selected');
			var $orgDropdown = $orgSelector.find('.zbooks-org-dropdown');
			var $orgHiddenInput = $('#zbooks_organization_id');
			var $orgSearchInput = $('#zbooks-org-search-input');

			// Toggle dropdown.
			$orgSelected.on('click', function(e) {
				e.stopPropagation();
				$orgSelector.toggleClass('is-open');
				if ($orgSelector.hasClass('is-open') && $orgSearchInput.length) {
					$orgSearchInput.focus();
				}
			});

			// Close dropdown on outside click.
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.zbooks-org-selector').length) {
					$orgSelector.removeClass('is-open');
				}
			});

			// Search organizations.
			$orgSearchInput.on('input', function() {
				var query = $(this).val().toLowerCase();
				$orgSelector.find('.zbooks-org-item').each(function() {
					var name = $(this).data('name').toLowerCase();
					var country = ($(this).data('country') || '').toLowerCase();
					var id = $(this).data('id').toLowerCase();
					if (name.indexOf(query) > -1 || country.indexOf(query) > -1 || id.indexOf(query) > -1) {
						$(this).show();
					} else {
						$(this).hide();
					}
				});
			});

			// Select organization.
			$orgSelector.on('click', '.zbooks-org-item', function() {
				var $item = $(this);
				var orgId = $item.data('id');
				var orgName = $item.data('name');
				var country = $item.data('country') || '';
				var currency = $item.data('currency') || '';

				// Update hidden input.
				$orgHiddenInput.val(orgId);

				// Update selected display.
				var metaHtml = '';
				if (country) {
					metaHtml += '<span><span class="dashicons dashicons-location"></span>' + $('<div>').text(country).html() + '</span>';
				}
				if (currency) {
					metaHtml += '<span><span class="dashicons dashicons-money-alt"></span>' + currency + '</span>';
				}

				$orgSelected.html(
					'<div class="org-content">' +
						'<div class="org-name">' + $('<div>').text(orgName).html() + '</div>' +
						'<div class="org-meta">' + metaHtml + '</div>' +
					'</div>'
				);

				// Update selection state.
				$orgSelector.find('.zbooks-org-item').removeClass('is-selected');
				$item.addClass('is-selected');

				// Close dropdown.
				$orgSelector.removeClass('is-open');
			});

			// Get selected organization ID.
			function getSelectedOrgId() {
				return $orgHiddenInput.val() || '';
			}

			// Show credentials form when "Change Credentials" is clicked.
			$showCredentialsBtn.on('click', function() {
				$successPanel.slideUp(200);
				$credentialsForm.slideDown(200);
				// Clear fields for fresh input.
				$credentialFields.filter('input').val('');
			});

			// Hide credentials form when "Cancel" is clicked.
			$cancelReauthBtn.on('click', function() {
				$credentialsForm.slideUp(200);
				$successPanel.slideDown(200);
				$('#zbooks-auth-status').html('');
			});

			// Authenticate button click.
			$authBtn.on('click', function() {
				var $btn = $(this);
				var $spinner = $btn.siblings('.spinner');
				var $status = $('#zbooks-auth-status');

				var clientId = $('#zbooks_client_id').val();
				var clientSecret = $('#zbooks_client_secret').val();
				var refreshToken = $('#zbooks_refresh_token').val();
				var datacenter = $('#zbooks_datacenter').val();

				// Require at least the grant code/refresh token for re-authentication.
				if (!refreshToken) {
					$status.html('<span style="color: #d63638;"><?php echo esc_js( __( 'Please enter a grant code or refresh token.', 'zbooks-for-woocommerce' ) ); ?></span>');
					return;
				}

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$status.html('');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'zbooks_authenticate',
						nonce: '<?php echo esc_js( wp_create_nonce( 'zbooks_authenticate' ) ); ?>',
						client_id: clientId,
						client_secret: clientSecret,
						refresh_token: refreshToken,
						datacenter: datacenter
					},
					success: function(response) {
						$spinner.removeClass('is-active');
						if (response.success) {
							$status.html('<span style="color: #46b450;">' + response.data.message + '</span>');
							isConfigured = true;

							// Rebuild organization dropdown.
							var $orgList = $orgSelector.find('.zbooks-org-list');
							$orgList.html('');
							$orgSelected.html('<span class="org-placeholder"><?php echo esc_js( __( '— Select Organization —', 'zbooks-for-woocommerce' ) ); ?></span>');
							$orgHiddenInput.val('');

							if (response.data.organizations && response.data.organizations.length) {
								// Add search box if more than 5 orgs.
								if (response.data.organizations.length > 5 && !$orgSearchInput.length) {
									$orgSelector.find('.zbooks-org-dropdown').prepend(
										'<div class="zbooks-org-search">' +
											'<input type="text" placeholder="<?php echo esc_js( __( 'Search organizations...', 'zbooks-for-woocommerce' ) ); ?>" id="zbooks-org-search-input">' +
										'</div>'
									);
									$orgSearchInput = $('#zbooks-org-search-input');
									$orgSearchInput.on('input', function() {
										var query = $(this).val().toLowerCase();
										$orgSelector.find('.zbooks-org-item').each(function() {
											var name = $(this).data('name').toLowerCase();
											var country = ($(this).data('country') || '').toLowerCase();
											var id = $(this).data('id').toLowerCase();
											$(this).toggle(name.indexOf(query) > -1 || country.indexOf(query) > -1 || id.indexOf(query) > -1);
										});
									});
								}

								$.each(response.data.organizations, function(i, org) {
									var metaHtml = '<span><span class="dashicons dashicons-admin-generic"></span>ID: ' + org.organization_id + '</span>';
									if (org.country) {
										metaHtml += '<span><span class="dashicons dashicons-location"></span>' + $('<div>').text(org.country).html() + '</span>';
									}
									if (org.currency_code) {
										metaHtml += '<span><span class="dashicons dashicons-money-alt"></span>' + org.currency_code + '</span>';
									}

									var $item = $('<div class="zbooks-org-item">')
										.attr('data-id', org.organization_id)
										.attr('data-name', org.name)
										.attr('data-country', org.country || '')
										.attr('data-currency', org.currency_code || '')
										.attr('data-currency-symbol', org.currency_symbol || '')
										.attr('data-fiscal', org.fiscal_year_start_month || '')
										.html(
											'<div class="org-name">' + $('<div>').text(org.name).html() + '</div>' +
											'<div class="org-meta">' + metaHtml + '</div>'
										);
									$orgList.append($item);
								});
							}

							// Enable dropdown and save button.
							$orgSelector.removeAttr('style');
							$saveOrgBtn.prop('disabled', false);
							$testConnectionBtn.prop('disabled', false);

							// Hide warnings and error messages.
							$('.zbooks-auth-warning').hide();
							$('.zbooks-org-error').hide();

							// Show message if no organizations returned.
							if (!response.data.organizations || !response.data.organizations.length) {
								var emptyMsg = '<?php echo esc_js( __( 'No organizations found. Please check your Zoho Books account.', 'zbooks-for-woocommerce' ) ); ?>';
								if (response.data.org_error) {
									emptyMsg = '<?php echo esc_js( __( 'Failed to load organizations:', 'zbooks-for-woocommerce' ) ); ?> ' + response.data.org_error + '<br><small><?php echo esc_js( __( 'Please reload the page to try again.', 'zbooks-for-woocommerce' ) ); ?></small>';
								}
								$orgList.html('<div class="zbooks-org-empty" style="color: #721c24;">' + emptyMsg + '</div>');
							}

							// Hide form and show success panel after a brief delay.
							setTimeout(function() {
								$credentialsForm.slideUp(200);
								$successPanel.find('p').eq(1).html('<?php echo esc_js( __( 'Your API credentials are configured and valid.', 'zbooks-for-woocommerce' ) ); ?>');
								$successPanel.slideDown(200);
							}, 1500);

							// Update connection status cards and test connection.
							updateConnectionStatus(true, getSelectedOrgId() !== '');
							testConnection();
						} else {
							$status.html('<span style="color: #d63638;">' + response.data.message + '</span>');
							$btn.prop('disabled', false);
						}
					},
					error: function() {
						$spinner.removeClass('is-active');
						$status.html('<span style="color: #d63638;"><?php echo esc_js( __( 'Request failed. Please try again.', 'zbooks-for-woocommerce' ) ); ?></span>');
						$btn.prop('disabled', false);
					}
				});
			});

			// Save organization button click.
			$saveOrgBtn.on('click', function() {
				var $btn = $(this);
				var $spinner = $btn.next('.spinner');
				var $status = $('#zbooks-org-status');
				var orgId = getSelectedOrgId();

				if (!orgId) {
					$status.html('<span style="color: #d63638;"><?php echo esc_js( __( 'Please select an organization.', 'zbooks-for-woocommerce' ) ); ?></span>');
					return;
				}

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$status.html('');
				$('.zbooks-org-saved').hide();

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'zbooks_save_organization',
						nonce: '<?php echo esc_js( wp_create_nonce( 'zbooks_save_organization' ) ); ?>',
						organization_id: orgId
					},
					success: function(response) {
						$spinner.removeClass('is-active');
						$btn.prop('disabled', false);
						if (response.success) {
							$status.html('<span style="color: #46b450;">' + response.data.message + '</span>');
							$('.zbooks-org-saved').show();

							// Update connection status table (both configured).
							updateConnectionStatus(true, true);
						} else {
							$status.html('<span style="color: #d63638;">' + response.data.message + '</span>');
						}
					},
					error: function() {
						$spinner.removeClass('is-active');
						$btn.prop('disabled', false);
						$status.html('<span style="color: #d63638;"><?php echo esc_js( __( 'Request failed. Please try again.', 'zbooks-for-woocommerce' ) ); ?></span>');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX: Authenticate with Zoho.
	 */
	public function ajax_authenticate(): void {
		check_ajax_referer( 'zbooks_authenticate', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$client_id     = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		$client_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
		$token         = sanitize_text_field( wp_unslash( $_POST['refresh_token'] ?? '' ) );
		$datacenter    = sanitize_key( wp_unslash( $_POST['datacenter'] ?? 'us' ) );

		// Handle masked values - keep existing.
		$existing = $this->token_manager->get_credentials();
		if ( '********' === $client_id || empty( $client_id ) ) {
			$client_id = $existing['client_id'] ?? '';
		}
		if ( '********' === $client_secret || empty( $client_secret ) ) {
			$client_secret = $existing['client_secret'] ?? '';
		}
		if ( '********' === $token || empty( $token ) ) {
			$token = $existing['refresh_token'] ?? '';
		}

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $token ) ) {
			wp_send_json_error( [ 'message' => __( 'Please fill in all credential fields.', 'zbooks-for-woocommerce' ) ] );
		}

		// Save datacenter.
		update_option( 'zbooks_datacenter', $datacenter );

		try {
			// Save credentials first.
			$this->token_manager->save_credentials( $client_id, $client_secret, $token );

			// Clear old tokens and caches.
			delete_option( 'zbooks_access_token' );
			delete_transient( 'zbooks_zoho_income_accounts' );
			delete_transient( 'zbooks_zoho_bank_accounts' );
			delete_transient( 'zbooks_zoho_expense_accounts' );

			try {
				// Try as refresh token first.
				$this->client->refresh_access_token();
			} catch ( \Throwable $refresh_error ) {
				// Try exchanging as grant code.
				$tokens = $this->client->exchange_grant_code(
					$client_id,
					$client_secret,
					$token,
					$datacenter
				);

				// Save the obtained refresh token.
				$this->token_manager->save_credentials(
					$client_id,
					$client_secret,
					$tokens['refresh_token']
				);

				// Save the access token.
				$this->token_manager->save_access_token(
					$tokens['access_token'],
					$tokens['expires_in']
				);
			}

			// Get organizations.
			$organizations = [];
			$org_error     = '';
			try {
				$organizations = $this->client->get_organizations();
			} catch ( \Exception $e ) {
				$org_error = $e->getMessage();
			}

			$response_data = [
				'message'       => __( 'Authentication successful!', 'zbooks-for-woocommerce' ),
				'organizations' => $organizations,
			];

			// Include organization error if any.
			if ( ! empty( $org_error ) ) {
				$response_data['org_error'] = $org_error;
			}

			wp_send_json_success( $response_data );
		} catch ( \Throwable $e ) {
			$error_message = $e->getMessage();

			// Provide more helpful error messages.
			if ( strpos( $error_message, 'invalid_code' ) !== false || strpos( $error_message, 'invalid_grant' ) !== false ) {
				$error_message = __( 'The grant code is invalid or has expired. Grant codes are valid for only 10 minutes and can only be used once. Please generate a new grant code from the Zoho API Console.', 'zbooks-for-woocommerce' );
			} elseif ( strpos( $error_message, 'invalid_client' ) !== false ) {
				$error_message = __( 'Invalid Client ID or Client Secret. Please verify your credentials in the Zoho API Console.', 'zbooks-for-woocommerce' );
			} elseif ( strpos( $error_message, 'access denied' ) !== false || strpos( $error_message, 'unauthorized' ) !== false ) {
				$error_message = __( 'Access Denied: The refresh token may have expired. Zoho tokens expire after 90 days of inactivity. Please generate a new grant code from the Zoho API Console.', 'zbooks-for-woocommerce' );
			}

			wp_send_json_error( [ 'message' => $error_message ] );
		}
	}

	/**
	 * AJAX: Save organization.
	 */
	public function ajax_save_organization(): void {
		check_ajax_referer( 'zbooks_save_organization', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$org_id = sanitize_text_field( wp_unslash( $_POST['organization_id'] ?? '' ) );

		if ( empty( $org_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Please select an organization.', 'zbooks-for-woocommerce' ) ] );
		}

		update_option( 'zbooks_organization_id', $org_id );

		wp_send_json_success( [ 'message' => __( 'Organization saved successfully!', 'zbooks-for-woocommerce' ) ] );
	}

	/**
	 * AJAX: Test connection to Zoho Books.
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'zbooks_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		if ( ! $this->client->is_configured() ) {
			wp_send_json_error( [ 'message' => __( 'API credentials not configured.', 'zbooks-for-woocommerce' ) ] );
		}

		try {
			// Try to fetch organizations to test the connection - this gives us the actual error.
			$orgs = $this->client->get_organizations();

			// Update connection health cache.
			set_transient( 'zbooks_connection_healthy', 'yes', 5 * MINUTE_IN_SECONDS );

			wp_send_json_success( [ 'message' => __( 'Connection successful!', 'zbooks-for-woocommerce' ) ] );
		} catch ( \Exception $e ) {
			set_transient( 'zbooks_connection_healthy', 'no', 5 * MINUTE_IN_SECONDS );

			$error_message = $e->getMessage();

			// Provide more helpful error messages for common issues.
			if ( stripos( $error_message, 'access denied' ) !== false || stripos( $error_message, 'unauthorized' ) !== false ) {
				$error_message = sprintf(
					/* translators: %s: original error message */
					__( 'Session Expired: Your Zoho authentication has expired. This can happen after 90 days of inactivity, if you revoked access in Zoho, or if you connected more than 20 apps to this Zoho account. Click "Change Credentials" below to re-authenticate. (Details: %s)', 'zbooks-for-woocommerce' ),
					$error_message
				);
			} elseif ( stripos( $error_message, 'invalid_token' ) !== false ) {
				$error_message = __( 'Session Expired: Your Zoho authentication is no longer valid. Click "Change Credentials" below to re-authenticate.', 'zbooks-for-woocommerce' );
			} elseif ( stripos( $error_message, 'invalid_code' ) !== false || stripos( $error_message, 'invalid_grant' ) !== false ) {
				$error_message = __( 'Invalid Grant Code: The grant code has expired or already been used. Grant codes are valid for only 10 minutes and can only be used once. Please generate a new grant code from the Zoho API Console.', 'zbooks-for-woocommerce' );
			}

			wp_send_json_error( [ 'message' => $error_message ] );
		}
	}

	/**
	 * Sanitize OAuth credentials (kept for backward compatibility).
	 *
	 * @param array $input Input credentials.
	 * @return array Sanitized credentials.
	 */
	public function sanitize_credentials( array $input ): array {
		$existing = $this->token_manager->get_credentials();
		$output   = [];

		foreach ( [ 'client_id', 'client_secret', 'refresh_token' ] as $key ) {
			$value = $input[ $key ] ?? '';
			if ( ! empty( $value ) && '********' !== $value ) {
				$output[ $key ] = sanitize_text_field( $value );
			} else {
				$output[ $key ] = $existing[ $key ] ?? '';
			}
		}

		return $output;
	}
}
