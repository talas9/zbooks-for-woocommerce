<?php
/**
 * Settings page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Api\ZohoClient;
use Zbooks\Api\TokenManager;

defined('ABSPATH') || exit;

/**
 * Admin settings page using WordPress Settings API.
 */
class SettingsPage {

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
     * Available tabs (lazy-loaded).
     *
     * @var array
     */
    private array $tabs = [];

    /**
     * Constructor.
     *
     * @param ZohoClient   $client        Zoho client.
     * @param TokenManager $token_manager Token manager.
     */
    public function __construct(ZohoClient $client, TokenManager $token_manager) {
        $this->client = $client;
        $this->token_manager = $token_manager;
        $this->register_hooks();
    }

    /**
     * Get tabs configuration (lazy-loaded to avoid early translation).
     *
     * @return array
     */
    private function get_tabs(): array {
        if (empty($this->tabs)) {
            $this->tabs = [
                'connection' => __('Connection', 'zbooks-for-woocommerce'),
                'sync' => __('Sync', 'zbooks-for-woocommerce'),
                'advanced' => __('Advanced', 'zbooks-for-woocommerce'),
            ];
        }
        return $this->tabs;
    }

    /**
     * Get current tab from URL or default.
     *
     * @return string
     */
    private function get_current_tab(): string {
        $tabs = $this->get_tabs();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab display
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'connection';
        return isset($tabs[$tab]) ? $tab : 'connection';
    }

    /**
     * Register hooks.
     */
    private function register_hooks(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Add top-level ZBooks menu and settings submenu.
     */
    public function add_menu_page(): void {
        // Add top-level menu.
        add_menu_page(
            __('ZBooks', 'zbooks-for-woocommerce'),
            __('ZBooks', 'zbooks-for-woocommerce'),
            'manage_woocommerce',
            'zbooks',
            [$this, 'render_page'],
            'dashicons-book-alt',
            56 // Position after WooCommerce
        );

        // Add Settings as first submenu (replaces the auto-created duplicate).
        add_submenu_page(
            'zbooks',
            __('Settings', 'zbooks-for-woocommerce'),
            __('Settings', 'zbooks-for-woocommerce'),
            'manage_woocommerce',
            'zbooks', // Same slug as parent to replace duplicate
            [$this, 'render_page']
        );
    }

    /**
     * Register settings.
     */
    public function register_settings(): void {
        // Register all settings (needed for sanitization regardless of tab).
        $this->register_all_settings();

        // Register sections and fields per tab.
        $this->register_connection_tab();
        $this->register_sync_tab();
        $this->register_advanced_tab();
    }

    /**
     * Register all setting options.
     */
    private function register_all_settings(): void {
        register_setting('zbooks_settings_connection', 'zbooks_oauth_credentials', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_credentials'],
        ]);
        register_setting('zbooks_settings_connection', 'zbooks_datacenter', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_key',
        ]);
        register_setting('zbooks_settings_connection', 'zbooks_organization_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting('zbooks_settings_sync', 'zbooks_sync_triggers', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_triggers'],
        ]);
        register_setting('zbooks_settings_sync', 'zbooks_payment_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_payment_settings'],
        ]);
        register_setting('zbooks_settings_sync', 'zbooks_refund_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_refund_settings'],
        ]);
        register_setting('zbooks_settings_sync', 'zbooks_invoice_numbering', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_invoice_numbering'],
        ]);
        register_setting('zbooks_settings_sync', 'zbooks_shipping_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_shipping_settings'],
        ]);

        register_setting('zbooks_settings_advanced', 'zbooks_retry_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_retry'],
        ]);
        register_setting('zbooks_settings_advanced', 'zbooks_log_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_log_settings'],
        ]);
    }

    /**
     * Register Connection tab sections and fields.
     */
    private function register_connection_tab(): void {
        // OAuth credentials section.
        add_settings_section(
            'zbooks_oauth_section',
            __('Zoho API Credentials', 'zbooks-for-woocommerce'),
            [$this, 'render_oauth_section'],
            'zbooks-settings-connection'
        );

        add_settings_field(
            'zbooks_client_id',
            __('Client ID', 'zbooks-for-woocommerce'),
            [$this, 'render_text_field'],
            'zbooks-settings-connection',
            'zbooks_oauth_section',
            ['id' => 'client_id', 'type' => 'text']
        );

        add_settings_field(
            'zbooks_client_secret',
            __('Client Secret', 'zbooks-for-woocommerce'),
            [$this, 'render_text_field'],
            'zbooks-settings-connection',
            'zbooks_oauth_section',
            ['id' => 'client_secret', 'type' => 'password']
        );

        add_settings_field(
            'zbooks_refresh_token',
            __('Refresh Token', 'zbooks-for-woocommerce'),
            [$this, 'render_text_field'],
            'zbooks-settings-connection',
            'zbooks_oauth_section',
            ['id' => 'refresh_token', 'type' => 'password']
        );

        // Organization section.
        add_settings_section(
            'zbooks_org_section',
            __('Organization', 'zbooks-for-woocommerce'),
            [$this, 'render_org_section'],
            'zbooks-settings-connection'
        );

        add_settings_field(
            'zbooks_datacenter',
            __('Datacenter', 'zbooks-for-woocommerce'),
            [$this, 'render_datacenter_field'],
            'zbooks-settings-connection',
            'zbooks_org_section'
        );

        add_settings_field(
            'zbooks_organization_id',
            __('Organization', 'zbooks-for-woocommerce'),
            [$this, 'render_organization_field'],
            'zbooks-settings-connection',
            'zbooks_org_section'
        );
    }

    /**
     * Register Sync tab sections and fields.
     */
    private function register_sync_tab(): void {
        // Sync triggers section.
        add_settings_section(
            'zbooks_sync_section',
            __('Order Status Triggers', 'zbooks-for-woocommerce'),
            [$this, 'render_sync_section'],
            'zbooks-settings-sync'
        );

        add_settings_field(
            'zbooks_sync_triggers',
            __('Status Actions', 'zbooks-for-woocommerce'),
            [$this, 'render_triggers_field'],
            'zbooks-settings-sync',
            'zbooks_sync_section'
        );

        // Payment settings section.
        add_settings_section(
            'zbooks_payment_section',
            __('Payment Settings', 'zbooks-for-woocommerce'),
            [$this, 'render_payment_section'],
            'zbooks-settings-sync'
        );

        add_settings_field(
            'zbooks_payment_settings',
            __('Payment Sync', 'zbooks-for-woocommerce'),
            [$this, 'render_payment_settings_field'],
            'zbooks-settings-sync',
            'zbooks_payment_section'
        );

        // Refund settings section.
        add_settings_section(
            'zbooks_refund_section',
            __('Refund Settings', 'zbooks-for-woocommerce'),
            [$this, 'render_refund_section'],
            'zbooks-settings-sync'
        );

        add_settings_field(
            'zbooks_refund_settings',
            __('Refund Sync', 'zbooks-for-woocommerce'),
            [$this, 'render_refund_settings_field'],
            'zbooks-settings-sync',
            'zbooks_refund_section'
        );

        // Invoice numbering section.
        add_settings_section(
            'zbooks_invoice_numbering_section',
            __('Invoice Numbering', 'zbooks-for-woocommerce'),
            [$this, 'render_invoice_numbering_section'],
            'zbooks-settings-sync'
        );

        add_settings_field(
            'zbooks_invoice_numbering',
            __('Order Number Handling', 'zbooks-for-woocommerce'),
            [$this, 'render_invoice_numbering_field'],
            'zbooks-settings-sync',
            'zbooks_invoice_numbering_section'
        );

        // Shipping settings section.
        add_settings_section(
            'zbooks_shipping_section',
            __('Shipping Settings', 'zbooks-for-woocommerce'),
            [$this, 'render_shipping_section'],
            'zbooks-settings-sync'
        );

        add_settings_field(
            'zbooks_shipping_settings',
            __('Shipping Account', 'zbooks-for-woocommerce'),
            [$this, 'render_shipping_settings_field'],
            'zbooks-settings-sync',
            'zbooks_shipping_section'
        );

        // Currency info section.
        add_settings_section(
            'zbooks_currency_section',
            __('Currency Handling', 'zbooks-for-woocommerce'),
            [$this, 'render_currency_section'],
            'zbooks-settings-sync'
        );
    }

    /**
     * Register Advanced tab sections and fields.
     */
    private function register_advanced_tab(): void {
        // Retry settings section.
        add_settings_section(
            'zbooks_retry_section',
            __('Retry Settings', 'zbooks-for-woocommerce'),
            [$this, 'render_retry_section'],
            'zbooks-settings-advanced'
        );

        add_settings_field(
            'zbooks_retry_settings',
            __('Retry Mode', 'zbooks-for-woocommerce'),
            [$this, 'render_retry_field'],
            'zbooks-settings-advanced',
            'zbooks_retry_section'
        );

        // Log settings section.
        add_settings_section(
            'zbooks_log_section',
            __('Log Settings', 'zbooks-for-woocommerce'),
            [$this, 'render_log_section'],
            'zbooks-settings-advanced'
        );

        add_settings_field(
            'zbooks_log_settings',
            __('Log Configuration', 'zbooks-for-woocommerce'),
            [$this, 'render_log_settings_field'],
            'zbooks-settings-advanced',
            'zbooks_log_section'
        );
    }

    /**
     * Render settings page.
     */
    public function render_page(): void {
        $current_tab = $this->get_current_tab();
        ?>
        <div class="wrap zbooks-settings">
            <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=zbooks-setup')); ?>" class="page-title-action">
                <?php esc_html_e('Run Setup Wizard', 'zbooks-for-woocommerce'); ?>
            </a>
            <hr class="wp-header-end">

            <?php settings_errors('zbooks_settings'); ?>

            <nav class="nav-tab-wrapper zbooks-tabs">
                <?php foreach ($this->get_tabs() as $tab_id => $tab_label) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=zbooks&tab=' . $tab_id)); ?>"
                       class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="zbooks-tab-content">
                <form method="post" action="options.php">
                    <?php
                    settings_fields('zbooks_settings_' . $current_tab);
                    do_settings_sections('zbooks-settings-' . $current_tab);
                    submit_button();
                    ?>
                </form>

                <?php if ('connection' === $current_tab) : ?>
                    <hr>
                    <h2><?php esc_html_e('Connection Test', 'zbooks-for-woocommerce'); ?></h2>
                    <p class="zbooks-test-connection">
                        <button type="button" class="button zbooks-test-connection-btn">
                            <?php esc_html_e('Test Connection', 'zbooks-for-woocommerce'); ?>
                        </button>
                        <span class="spinner"></span>
                        <span class="zbooks-connection-result"></span>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render OAuth section description.
     */
    public function render_oauth_section(): void {
        ?>
        <p>
            <?php
            printf(
                /* translators: %s: Zoho API console URL */
                esc_html__('Get your API credentials from the %s.', 'zbooks-for-woocommerce'),
                '<a href="https://api-console.zoho.com/" target="_blank">Zoho API Console</a>'
            );
            ?>
        </p>
        <?php
    }

    /**
     * Render organization section description.
     */
    public function render_org_section(): void {
        ?>
        <p><?php esc_html_e('Select your Zoho datacenter and organization.', 'zbooks-for-woocommerce'); ?></p>
        <?php
    }

    /**
     * Render sync section description.
     */
    public function render_sync_section(): void {
        ?>
        <p><?php esc_html_e('Configure which order statuses trigger automatic sync.', 'zbooks-for-woocommerce'); ?></p>
        <?php
    }

    /**
     * Render retry section description.
     */
    public function render_retry_section(): void {
        ?>
        <p><?php esc_html_e('Configure how failed syncs are retried.', 'zbooks-for-woocommerce'); ?></p>
        <?php
    }

    /**
     * Render payment section description.
     */
    public function render_payment_section(): void {
        ?>
        <p><?php esc_html_e('Configure how payments are synced to Zoho Books when orders are completed.', 'zbooks-for-woocommerce'); ?></p>
        <?php
    }

    /**
     * Render refund section description.
     */
    public function render_refund_section(): void {
        ?>
        <p><?php esc_html_e('Configure how refunds are synced to Zoho Books when WooCommerce refunds are processed.', 'zbooks-for-woocommerce'); ?></p>
        <?php
    }

    /**
     * Render log section description.
     */
    public function render_log_section(): void {
        ?>
        <p><?php esc_html_e('Configure logging behavior and error notifications.', 'zbooks-for-woocommerce'); ?></p>
        <?php
    }

    /**
     * Render shipping section description.
     */
    public function render_shipping_section(): void {
        ?>
        <p><?php esc_html_e('Configure how shipping charges are recorded in Zoho Books invoices.', 'zbooks-for-woocommerce'); ?></p>
        <?php
    }

    /**
     * Render shipping settings field.
     */
    public function render_shipping_settings_field(): void {
        $settings = get_option('zbooks_shipping_settings', [
            'account_id' => '',
        ]);
        $accounts = [];

        if ($this->client->is_configured()) {
            try {
                // Fetch income accounts from Zoho Books.
                $response = $this->client->request(function ($client) {
                    return $client->chartofaccounts->getList([
                        'account_type' => 'income',
                        'filter_by' => 'AccountType.Active',
                    ]);
                });

                // Convert object to array if needed.
                if (is_object($response)) {
                    $response = json_decode(wp_json_encode($response), true);
                }

                $accounts = $response['chartofaccounts'] ?? $response ?? [];
            } catch (\Exception $e) {
                // Ignore - will show empty dropdown.
            }
        }
        ?>
        <fieldset>
            <select name="zbooks_shipping_settings[account_id]" id="zbooks_shipping_account">
                <option value=""><?php esc_html_e('Use default (Shipping Charge)', 'zbooks-for-woocommerce'); ?></option>
                <?php foreach ($accounts as $account) :
                    $account_id = $account['account_id'] ?? '';
                    $account_name = $account['account_name'] ?? '';
                    if (empty($account_id)) {
                        continue;
                    }
                    ?>
                    <option value="<?php echo esc_attr($account_id); ?>" <?php selected($settings['account_id'], $account_id); ?>>
                        <?php echo esc_html($account_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description">
                <?php esc_html_e('Select the income account to record shipping charges. Leave empty to use Zoho\'s default "Shipping Charge" account.', 'zbooks-for-woocommerce'); ?>
            </p>
            <?php if (empty($accounts) && $this->client->is_configured()) : ?>
                <p class="description" style="color: #d63638;">
                    <?php esc_html_e('Could not load accounts from Zoho Books. Save settings and refresh the page.', 'zbooks-for-woocommerce'); ?>
                </p>
            <?php elseif (!$this->client->is_configured()) : ?>
                <p class="description">
                    <?php esc_html_e('Configure Zoho connection first to load accounts.', 'zbooks-for-woocommerce'); ?>
                </p>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    /**
     * Render currency section description.
     */
    public function render_currency_section(): void {
        ?>
        <div class="zbooks-info-box">
            <p>
                <strong><?php esc_html_e('How currency is handled:', 'zbooks-for-woocommerce'); ?></strong>
            </p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><?php esc_html_e('Currency is automatically taken from each WooCommerce order.', 'zbooks-for-woocommerce'); ?></li>
                <li><?php esc_html_e('When a new contact is created in Zoho Books, it will be assigned the currency from the first order.', 'zbooks-for-woocommerce'); ?></li>
                <li><?php esc_html_e('If an existing contact has a different currency than the order, the sync will fail with a clear error message.', 'zbooks-for-woocommerce'); ?></li>
                <li><?php esc_html_e('Zoho Books does not allow changing a contact\'s currency after transactions exist.', 'zbooks-for-woocommerce'); ?></li>
            </ul>
            <p class="description">
                <?php esc_html_e('If you have multi-currency orders, ensure customers use consistent email addresses per currency, or update the contact currency in Zoho Books before syncing.', 'zbooks-for-woocommerce'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render text input field.
     *
     * @param array $args Field arguments.
     */
    public function render_text_field(array $args): void {
        $credentials = $this->token_manager->get_credentials();
        $value = $credentials[$args['id']] ?? '';
        $type = $args['type'] ?? 'text';
        ?>
        <input
            type="<?php echo esc_attr($type); ?>"
            id="zbooks_<?php echo esc_attr($args['id']); ?>"
            name="zbooks_oauth_credentials[<?php echo esc_attr($args['id']); ?>]"
            value="<?php echo esc_attr($value ? '********' : ''); ?>"
            class="regular-text"
            placeholder="<?php echo esc_attr($value ? __('(saved)', 'zbooks-for-woocommerce') : ''); ?>"
        >
        <?php
    }

    /**
     * Render datacenter field.
     */
    public function render_datacenter_field(): void {
        $current = get_option('zbooks_datacenter', 'us');
        $datacenters = [
            'us' => 'United States (zoho.com)',
            'eu' => 'Europe (zoho.eu)',
            'in' => 'India (zoho.in)',
            'au' => 'Australia (zoho.com.au)',
            'jp' => 'Japan (zoho.jp)',
        ];
        ?>
        <select name="zbooks_datacenter" id="zbooks_datacenter">
            <?php foreach ($datacenters as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render organization field.
     */
    public function render_organization_field(): void {
        $current = get_option('zbooks_organization_id', '');
        $orgs = [];

        if ($this->client->is_configured()) {
            try {
                $orgs = $this->client->get_organizations();
            } catch (\Exception $e) {
                // Ignore - will show empty dropdown.
            }
        }
        ?>
        <select name="zbooks_organization_id" id="zbooks_organization_id">
            <option value=""><?php esc_html_e('Select organization...', 'zbooks-for-woocommerce'); ?></option>
            <?php foreach ($orgs as $org) : ?>
                <option value="<?php echo esc_attr($org['organization_id']); ?>" <?php selected($current, $org['organization_id']); ?>>
                    <?php echo esc_html($org['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Save credentials first, then refresh to load organizations.', 'zbooks-for-woocommerce'); ?>
        </p>
        <?php
    }

    /**
     * Render sync triggers field.
     */
    public function render_triggers_field(): void {
        $triggers = get_option('zbooks_sync_triggers', []);
        $statuses = wc_get_order_statuses();
        $actions = [
            'none' => __('No action', 'zbooks-for-woocommerce'),
            'sync_draft' => __('Create draft invoice', 'zbooks-for-woocommerce'),
            'sync_submit' => __('Create and submit invoice', 'zbooks-for-woocommerce'),
        ];
        ?>
        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th style="padding: 10px 15px;"><?php esc_html_e('Status', 'zbooks-for-woocommerce'); ?></th>
                    <th style="padding: 10px 15px;"><?php esc_html_e('Sync Action', 'zbooks-for-woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statuses as $status_key => $status_label) :
                    $status = str_replace('wc-', '', $status_key);
                    $current_action = $triggers[$status] ?? 'none';
                    ?>
                    <tr>
                        <td style="padding: 8px 15px;"><?php echo esc_html($status_label); ?></td>
                        <td style="padding: 8px 15px;">
                            <select name="zbooks_sync_triggers[<?php echo esc_attr($status); ?>]">
                                <?php foreach ($actions as $action_value => $action_label) : ?>
                                    <option value="<?php echo esc_attr($action_value); ?>" <?php selected($current_action, $action_value); ?>>
                                        <?php echo esc_html($action_label); ?>
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
     * Render retry settings field.
     */
    public function render_retry_field(): void {
        $settings = get_option('zbooks_retry_settings', [
            'mode' => 'max_retries',
            'max_count' => 5,
            'backoff_minutes' => 15,
        ]);
        ?>
        <fieldset>
            <label>
                <input type="radio" name="zbooks_retry_settings[mode]" value="max_retries"
                    <?php checked($settings['mode'], 'max_retries'); ?>>
                <?php esc_html_e('Retry up to', 'zbooks-for-woocommerce'); ?>
                <input type="number" name="zbooks_retry_settings[max_count]"
                    value="<?php echo esc_attr($settings['max_count']); ?>"
                    min="1" max="20" style="width: 60px;">
                <?php esc_html_e('times', 'zbooks-for-woocommerce'); ?>
            </label>
            <br><br>
            <label>
                <input type="radio" name="zbooks_retry_settings[mode]" value="indefinite"
                    <?php checked($settings['mode'], 'indefinite'); ?>>
                <?php esc_html_e('Retry indefinitely', 'zbooks-for-woocommerce'); ?>
            </label>
            <br><br>
            <label>
                <input type="radio" name="zbooks_retry_settings[mode]" value="manual"
                    <?php checked($settings['mode'], 'manual'); ?>>
                <?php esc_html_e('Manual retry only', 'zbooks-for-woocommerce'); ?>
            </label>
            <br><br>
            <label>
                <?php esc_html_e('Backoff interval:', 'zbooks-for-woocommerce'); ?>
                <input type="number" name="zbooks_retry_settings[backoff_minutes]"
                    value="<?php echo esc_attr($settings['backoff_minutes']); ?>"
                    min="5" max="60" style="width: 60px;">
                <?php esc_html_e('minutes (doubles each retry)', 'zbooks-for-woocommerce'); ?>
            </label>
        </fieldset>
        <?php
    }

    /**
     * Render payment settings field.
     */
    public function render_payment_settings_field(): void {
        $settings = get_option('zbooks_payment_settings', [
            'auto_apply_payment' => true,
        ]);
        ?>
        <fieldset>
            <label style="display: block; margin-bottom: 10px;">
                <input type="checkbox" name="zbooks_payment_settings[auto_apply_payment]" value="1"
                    <?php checked(!empty($settings['auto_apply_payment'])); ?>>
                <?php esc_html_e('Automatically apply payment when order status changes to Completed', 'zbooks-for-woocommerce'); ?>
            </label>
            <p class="description">
                <?php esc_html_e('When enabled, a payment will be recorded in Zoho Books when an order is marked as completed. The invoice must already be synced to Zoho Books.', 'zbooks-for-woocommerce'); ?>
            </p>
        </fieldset>
        <?php
    }

    /**
     * Render refund settings field.
     */
    public function render_refund_settings_field(): void {
        $settings = get_option('zbooks_refund_settings', [
            'auto_sync_refunds' => true,
            'create_cash_refund' => true,
        ]);
        ?>
        <fieldset>
            <label style="display: block; margin-bottom: 10px;">
                <input type="checkbox" name="zbooks_refund_settings[auto_sync_refunds]" value="1"
                    <?php checked(!empty($settings['auto_sync_refunds'])); ?>>
                <?php esc_html_e('Automatically sync refunds to Zoho Books', 'zbooks-for-woocommerce'); ?>
            </label>
            <p class="description">
                <?php esc_html_e('When enabled, a credit note will be created in Zoho Books when a refund is processed in WooCommerce. The original invoice must already be synced.', 'zbooks-for-woocommerce'); ?>
            </p>

            <label style="display: block; margin: 15px 0 10px 24px;">
                <input type="checkbox" name="zbooks_refund_settings[create_cash_refund]" value="1"
                    <?php checked(!empty($settings['create_cash_refund'])); ?>>
                <?php esc_html_e('Also record cash refund from credit note', 'zbooks-for-woocommerce'); ?>
            </label>
            <p class="description" style="margin-left: 24px;">
                <?php esc_html_e('When enabled, a Credit Note Refund will be created to record the actual cash returned to the customer. If disabled, only the credit note and invoice adjustment will be recorded.', 'zbooks-for-woocommerce'); ?>
            </p>
        </fieldset>
        <?php
    }

    /**
     * Render invoice numbering section description.
     */
    public function render_invoice_numbering_section(): void {
        ?>
        <p><?php esc_html_e('Configure how WooCommerce order numbers are mapped to Zoho Books invoice numbers.', 'zbooks-for-woocommerce'); ?></p>
        <?php
    }

    /**
     * Render invoice numbering field.
     */
    public function render_invoice_numbering_field(): void {
        $settings = get_option('zbooks_invoice_numbering', [
            'use_reference_number' => true,
            'mark_as_sent' => true,
        ]);
        $use_reference = !empty($settings['use_reference_number']);
        $mark_as_sent = $settings['mark_as_sent'] ?? true;
        ?>
        <fieldset>
            <label style="display: block; margin-bottom: 10px;">
                <input type="checkbox" name="zbooks_invoice_numbering[use_reference_number]" value="1"
                    id="zbooks_use_reference_number"
                    <?php checked($use_reference); ?>>
                <?php esc_html_e('Use Zoho auto-numbering series for invoice numbers', 'zbooks-for-woocommerce'); ?>
                <strong style="color: #2271b1;"><?php esc_html_e('(Recommended)', 'zbooks-for-woocommerce'); ?></strong>
            </label>
            <p class="description">
                <?php esc_html_e('The WooCommerce order number is always stored in the "Reference Number" field for easy lookup in Zoho Books.', 'zbooks-for-woocommerce'); ?>
            </p>
            <p class="description" style="margin-top: 8px;">
                <?php esc_html_e('When enabled (default), Zoho Books will auto-generate sequential invoice numbers (e.g., INV-00001, INV-00002).', 'zbooks-for-woocommerce'); ?>
            </p>
            <p class="description" style="margin-top: 8px;">
                <?php esc_html_e('When disabled, the WooCommerce order number will also be used as the Zoho invoice number.', 'zbooks-for-woocommerce'); ?>
            </p>
            <div class="zbooks-warning-box" id="zbooks_invoice_number_warning" style="display: <?php echo $use_reference ? 'none' : 'block'; ?>; background: #fff8e5; border: 1px solid #f0c36d; border-left-width: 4px; border-radius: 4px; padding: 12px 16px; margin-top: 15px;">
                <p style="margin: 0 0 8px; color: #826200;">
                    <strong><span class="dashicons dashicons-warning" style="color: #dba617;"></span> <?php esc_html_e('Tax Audit Warning', 'zbooks-for-woocommerce'); ?></strong>
                </p>
                <p style="margin: 0; color: #826200;">
                    <?php esc_html_e('Using order numbers as invoice numbers may create gaps in your invoice sequence (e.g., if orders are cancelled or deleted). This can cause issues during tax audits in some jurisdictions where sequential invoice numbering is legally required.', 'zbooks-for-woocommerce'); ?>
                </p>
            </div>

            <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

            <label style="display: block; margin-bottom: 10px;">
                <input type="checkbox" name="zbooks_invoice_numbering[mark_as_sent]" value="1"
                    id="zbooks_mark_as_sent"
                    <?php checked($mark_as_sent); ?>>
                <?php esc_html_e('Mark invoices as "Sent" in Zoho Books', 'zbooks-for-woocommerce'); ?>
            </label>
            <p class="description">
                <?php esc_html_e('When enabled, invoices are marked as "Sent" after creation. When disabled, invoices remain as "Draft".', 'zbooks-for-woocommerce'); ?>
            </p>
            <p class="description" style="margin-top: 8px;">
                <strong><?php esc_html_e('Note:', 'zbooks-for-woocommerce'); ?></strong>
                <?php esc_html_e('If Zoho Books is sending email notifications when invoices are created, disable this option to keep invoices as drafts. You can also disable auto-email in Zoho Books: Settings → Email Templates → Invoice Notification Settings.', 'zbooks-for-woocommerce'); ?>
            </p>
        </fieldset>
        <script>
        jQuery(document).ready(function($) {
            $('#zbooks_use_reference_number').on('change', function() {
                var $warning = $('#zbooks_invoice_number_warning');
                if ($(this).is(':checked')) {
                    $warning.slideUp(200);
                } else {
                    $warning.slideDown(200);
                    if (!confirm('<?php echo esc_js(__('Warning: Using order numbers as invoice numbers may create gaps in your invoice sequence, which can cause issues during tax audits. Are you sure you want to disable this option?', 'zbooks-for-woocommerce')); ?>')) {
                        $(this).prop('checked', true);
                        $warning.hide();
                    }
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Sanitize credentials.
     *
     * @param array $input Input data.
     * @return array
     */
    public function sanitize_credentials(array $input): array {
        // Pass through unchanged if TokenManager is saving directly.
        // This prevents interference when credentials are saved programmatically
        // (e.g., from SetupWizard) rather than from the settings form.
        if (TokenManager::is_saving()) {
            return $input;
        }

        // Only process if this is an actual form submission with credential fields.
        if (!isset($input['client_id']) && !isset($input['client_secret']) && !isset($input['refresh_token'])) {
            return [];
        }

        $existing = $this->token_manager->get_credentials() ?? [];

        $input_client_id = $input['client_id'] ?? '';
        $input_client_secret = $input['client_secret'] ?? '';
        $input_refresh_token = $input['refresh_token'] ?? '';

        $client_id = !empty($input_client_id) && $input_client_id !== '********'
            ? sanitize_text_field($input_client_id)
            : ($existing['client_id'] ?? '');

        $client_secret = !empty($input_client_secret) && $input_client_secret !== '********'
            ? sanitize_text_field($input_client_secret)
            : ($existing['client_secret'] ?? '');

        $refresh_token = !empty($input_refresh_token) && $input_refresh_token !== '********'
            ? sanitize_text_field($input_refresh_token)
            : ($existing['refresh_token'] ?? '');

        $this->token_manager->save_credentials($client_id, $client_secret, $refresh_token);

        // Clear access token when credentials change.
        $credentials_changed = (!empty($input_client_id) && $input_client_id !== '********')
            || (!empty($input_refresh_token) && $input_refresh_token !== '********');

        if ($credentials_changed) {
            $this->token_manager->clear_tokens();
        }

        return []; // Actual storage is handled by token manager.
    }

    /**
     * Sanitize triggers.
     *
     * @param array $input Input data.
     * @return array
     */
    public function sanitize_triggers(array $input): array {
        $valid_actions = ['none', 'sync_draft', 'sync_submit'];
        $sanitized = [];

        foreach ($input as $status => $action) {
            $status = sanitize_key($status);
            $action = sanitize_key($action);

            if (in_array($action, $valid_actions, true)) {
                $sanitized[$status] = $action;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize retry settings.
     *
     * @param array $input Input data.
     * @return array
     */
    public function sanitize_retry(array $input): array {
        $valid_modes = ['max_retries', 'indefinite', 'manual'];

        return [
            'mode' => in_array($input['mode'] ?? '', $valid_modes, true)
                ? $input['mode']
                : 'max_retries',
            'max_count' => min(20, max(1, absint($input['max_count'] ?? 5))),
            'backoff_minutes' => min(60, max(5, absint($input['backoff_minutes'] ?? 15))),
        ];
    }

    /**
     * Sanitize payment settings.
     *
     * @param array $input Input data.
     * @return array
     */
    public function sanitize_payment_settings(array $input): array {
        return [
            'auto_apply_payment' => !empty($input['auto_apply_payment']),
        ];
    }

    /**
     * Sanitize refund settings.
     *
     * @param array $input Input data.
     * @return array
     */
    public function sanitize_refund_settings(array $input): array {
        return [
            'auto_sync_refunds' => !empty($input['auto_sync_refunds']),
            'create_cash_refund' => !empty($input['create_cash_refund']),
        ];
    }

    /**
     * Sanitize invoice numbering settings.
     *
     * @param array $input Input data.
     * @return array
     */
    public function sanitize_invoice_numbering(array $input): array {
        return [
            'use_reference_number' => !empty($input['use_reference_number']),
            'mark_as_sent' => !empty($input['mark_as_sent']),
        ];
    }

    /**
     * Sanitize shipping settings.
     *
     * @param array $input Input data.
     * @return array
     */
    public function sanitize_shipping_settings(array $input): array {
        return [
            'account_id' => sanitize_text_field($input['account_id'] ?? ''),
        ];
    }

    /**
     * Render log settings field.
     */
    public function render_log_settings_field(): void {
        $settings = get_option('zbooks_log_settings', [
            'retention_days' => 30,
            'max_file_size_mb' => 10,
            'email_on_error' => false,
            'error_email' => get_option('admin_email'),
        ]);
        ?>
        <fieldset>
            <label style="display: block; margin-bottom: 10px;">
                <?php esc_html_e('Keep logs for:', 'zbooks-for-woocommerce'); ?>
                <input type="number" name="zbooks_log_settings[retention_days]"
                    value="<?php echo esc_attr($settings['retention_days']); ?>"
                    min="1" max="365" style="width: 60px;">
                <?php esc_html_e('days', 'zbooks-for-woocommerce'); ?>
            </label>

            <label style="display: block; margin-bottom: 10px;">
                <?php esc_html_e('Maximum log file size:', 'zbooks-for-woocommerce'); ?>
                <input type="number" name="zbooks_log_settings[max_file_size_mb]"
                    value="<?php echo esc_attr($settings['max_file_size_mb']); ?>"
                    min="1" max="100" style="width: 60px;">
                <?php esc_html_e('MB (older entries will be rotated)', 'zbooks-for-woocommerce'); ?>
            </label>

            <label style="display: block; margin-bottom: 10px;">
                <input type="checkbox" name="zbooks_log_settings[email_on_error]" value="1"
                    <?php checked(!empty($settings['email_on_error'])); ?>>
                <?php esc_html_e('Send email notification on sync errors', 'zbooks-for-woocommerce'); ?>
            </label>

            <label style="display: block; margin-left: 24px;">
                <?php esc_html_e('Error notification email:', 'zbooks-for-woocommerce'); ?>
                <?php $error_email = !empty($settings['error_email']) ? $settings['error_email'] : get_option('admin_email'); ?>
                <input type="email" name="zbooks_log_settings[error_email]"
                    value="<?php echo esc_attr($error_email); ?>"
                    class="regular-text">
            </label>
        </fieldset>
        <?php
    }

    /**
     * Sanitize log settings.
     *
     * @param array $input Input data.
     * @return array
     */
    public function sanitize_log_settings(array $input): array {
        $error_email = !empty($input['error_email'])
            ? sanitize_email($input['error_email'])
            : get_option('admin_email');

        return [
            'retention_days' => min(365, max(1, absint($input['retention_days'] ?? 30))),
            'max_file_size_mb' => min(100, max(1, absint($input['max_file_size_mb'] ?? 10))),
            'email_on_error' => !empty($input['email_on_error']),
            'error_email' => $error_email,
        ];
    }
}
