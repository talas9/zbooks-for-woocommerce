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

		// Enqueue scripts/styles for connection tab.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue connection tab assets.
	 * WordPress.org requires proper enqueue instead of inline tags.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		// Only load on settings page, connection tab.
		if ( $hook !== 'toplevel_page_zbooks' ) {
			return;
		}

		// Check if we're on connection tab.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = $_GET['tab'] ?? 'connection';
		if ( $tab !== 'connection' ) {
			return;
		}

		// Enqueue CSS module.
		wp_enqueue_style(
			'zbooks-connection-tab',
			ZBOOKS_PLUGIN_URL . 'assets/css/modules/connection-tab.css',
			[],
			ZBOOKS_VERSION
		);

		// Enqueue connection tab JS module.
		wp_enqueue_script(
			'zbooks-connection-tab',
			ZBOOKS_PLUGIN_URL . 'assets/js/modules/connection-tab.js',
			[ 'jquery', 'zbooks-admin' ],
			ZBOOKS_VERSION,
			true
		);

		// Localize script with PHP values (WordPress.org compliant).
		$is_configured = $this->client->is_configured();
		wp_localize_script(
			'zbooks-connection-tab',
			'ZbooksConnectionConfig',
			[
				'isConfigured' => $is_configured,
				'nonce'        => wp_create_nonce( 'zbooks_ajax_nonce' ),
				'i18n'         => [
					'sessionExpired'   => __( 'Session expired. Please refresh the page and try again.', 'zbooks-for-woocommerce' ),
					'permissionDenied' => __( 'Permission denied.', 'zbooks-for-woocommerce' ),
					'serverError'      => __( 'Server error. Please try again later.', 'zbooks-for-woocommerce' ),
					'networkError'     => __( 'Network error. Please check your connection.', 'zbooks-for-woocommerce' ),
					'checking'         => __( 'Checking...', 'zbooks-for-woocommerce' ),
					'connected'        => __( 'Connected', 'zbooks-for-woocommerce' ),
					'failed'           => __( 'Failed', 'zbooks-for-woocommerce' ),
					'error'            => __( 'Error', 'zbooks-for-woocommerce' ),
					'yes'              => __( 'Yes', 'zbooks-for-woocommerce' ),
					'no'               => __( 'No', 'zbooks-for-woocommerce' ),
					'configured'       => __( 'Configured', 'zbooks-for-woocommerce' ),
					'notConfigured'    => __( 'Not configured', 'zbooks-for-woocommerce' ),
					'selected'         => __( 'Selected', 'zbooks-for-woocommerce' ),
					'notSelected'      => __( 'Not selected', 'zbooks-for-woocommerce' ),
					'unknownError'     => __( 'Unknown error occurred', 'zbooks-for-woocommerce' ),
					'requestFailed'    => __( 'Request failed', 'zbooks-for-woocommerce' ),
				],
			]
		);
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
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=zbooks-setup' ) ); ?>" class="button" style="margin-left: 10px;">
						<span class="dashicons dashicons-admin-settings" style="vertical-align: middle;"></span>
						<?php esc_html_e( 'Run Setup Wizard', 'zbooks-for-woocommerce' ); ?>
					</a>
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
						class="regular-text zbooks-select-on-click"
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

			<!-- Styles now loaded from assets/css/modules/connection-tab.css -->

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

			<!-- Styles now loaded from assets/css/modules/connection-tab.css -->

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

		<!-- JavaScript now output via admin_print_footer_scripts hook -->
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
		check_ajax_referer( 'zbooks_ajax_nonce', 'nonce' );

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
