<?php
/**
 * Setup wizard for initial plugin configuration.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Api\ZohoClient;
use Zbooks\Api\TokenManager;
use Zbooks\Repository\ItemMappingRepository;

defined('ABSPATH') || exit;

/**
 * Setup wizard for guiding users through initial configuration.
 */
class SetupWizard {

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
     * Item mapping repository.
     *
     * @var ItemMappingRepository
     */
    private ItemMappingRepository $item_mapping;

    /**
     * Wizard steps.
     *
     * @var array
     */
    private array $steps = [];

    /**
     * Constructor.
     *
     * @param ZohoClient            $client        Zoho client.
     * @param TokenManager          $token_manager Token manager.
     * @param ItemMappingRepository $item_mapping  Item mapping repository.
     */
    public function __construct(
        ZohoClient $client,
        TokenManager $token_manager,
        ?ItemMappingRepository $item_mapping = null
    ) {
        $this->client = $client;
        $this->token_manager = $token_manager;
        $this->item_mapping = $item_mapping ?? new ItemMappingRepository();
        $this->register_hooks();
    }

    /**
     * Get wizard steps (lazy loaded to avoid early translation).
     *
     * @return array
     */
    private function get_steps(): array {
        if (empty($this->steps)) {
            $this->steps = [
                'welcome' => [
                    'name' => __('Welcome', 'zbooks-for-woocommerce'),
                    'view' => 'welcome',
                ],
                'credentials' => [
                    'name' => __('API Credentials', 'zbooks-for-woocommerce'),
                    'view' => 'credentials',
                ],
                'organization' => [
                    'name' => __('Organization', 'zbooks-for-woocommerce'),
                    'view' => 'organization',
                ],
                'sync' => [
                    'name' => __('Sync Settings', 'zbooks-for-woocommerce'),
                    'view' => 'sync',
                ],
                'items' => [
                    'name' => __('Item Mapping', 'zbooks-for-woocommerce'),
                    'view' => 'items',
                ],
                'ready' => [
                    'name' => __('Ready!', 'zbooks-for-woocommerce'),
                    'view' => 'ready',
                ],
            ];
        }
        return $this->steps;
    }

    /**
     * Register hooks.
     */
    private function register_hooks(): void {
        add_action('admin_menu', [$this, 'add_wizard_page']);
        add_action('admin_init', [$this, 'maybe_redirect_to_wizard']);
        add_action('admin_init', [$this, 'handle_wizard_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_wizard_assets']);
    }

    /**
     * Add wizard page (hidden from menu).
     */
    public function add_wizard_page(): void {
        // Use 'options.php' as parent to hide from menu while keeping valid page.
        add_submenu_page(
            'options.php',
            __('ZBooks for WooCommerce Setup', 'zbooks-for-woocommerce'),
            __('ZBooks for WooCommerce Setup', 'zbooks-for-woocommerce'),
            'manage_woocommerce',
            'zbooks-setup',
            [$this, 'render_wizard']
        );
    }

    /**
     * Maybe redirect to wizard on plugin activation.
     */
    public function maybe_redirect_to_wizard(): void {
        if (!get_transient('zbooks_activation_redirect')) {
            return;
        }

        delete_transient('zbooks_activation_redirect');

        // Don't redirect if already configured.
        if ($this->is_configured()) {
            return;
        }

        // Don't redirect on multi-site bulk activation.
        if (is_network_admin() || isset($_GET['activate-multi'])) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=zbooks-setup'));
        exit;
    }

    /**
     * Check if plugin is fully configured.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->token_manager->has_credentials()
            && !empty(get_option('zbooks_organization_id'));
    }

    /**
     * Enqueue wizard assets.
     *
     * @param string $hook Current admin page.
     */
    public function enqueue_wizard_assets(string $hook): void {
        if ($hook !== 'admin_page_zbooks-setup') {
            return;
        }

        wp_enqueue_style(
            'zbooks-wizard',
            ZBOOKS_PLUGIN_URL . 'assets/css/wizard.css',
            [],
            ZBOOKS_VERSION
        );

        wp_enqueue_script(
            'zbooks-wizard',
            ZBOOKS_PLUGIN_URL . 'assets/js/wizard.js',
            ['jquery'],
            ZBOOKS_VERSION,
            true
        );

        wp_localize_script('zbooks-wizard', 'zbooksWizard', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zbooks_wizard_nonce'),
            'i18n' => [
                'testing' => __('Testing connection...', 'zbooks-for-woocommerce'),
                'loading_orgs' => __('Loading organizations...', 'zbooks-for-woocommerce'),
                'success' => __('Success!', 'zbooks-for-woocommerce'),
                'error' => __('Error:', 'zbooks-for-woocommerce'),
            ],
        ]);
    }

    /**
     * Handle wizard form submissions.
     */
    public function handle_wizard_actions(): void {
        if (!isset($_POST['zbooks_wizard_action'])) {
            return;
        }

        if (!isset($_POST['zbooks_wizard_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['zbooks_wizard_nonce'])), 'zbooks_wizard')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['zbooks_wizard_action']));

        switch ($action) {
            case 'save_credentials':
                $this->save_credentials();
                break;

            case 'save_organization':
                $this->save_organization();
                break;

            case 'save_sync':
                $this->save_sync_settings();
                break;

            case 'save_items':
                $this->save_item_mappings();
                break;

            case 'skip_items':
                $this->skip_item_mapping();
                break;

            case 'auto_map_items':
                $this->auto_map_items();
                break;

            case 'complete':
                $this->complete_wizard();
                break;
        }
    }

    /**
     * Save API credentials.
     *
     * Handles both grant codes (first-time setup) and refresh tokens.
     * Grant codes are automatically detected and exchanged for tokens.
     */
    private function save_credentials(): void {
        $client_id = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));
        $client_secret = sanitize_text_field(wp_unslash($_POST['client_secret'] ?? ''));
        $token = sanitize_text_field(wp_unslash($_POST['refresh_token'] ?? ''));
        $datacenter = sanitize_key(wp_unslash($_POST['datacenter'] ?? 'us'));

        if (empty($client_id) || empty($client_secret) || empty($token)) {
            $this->redirect_with_error('missing_fields');
            return;
        }

        update_option('zbooks_datacenter', $datacenter);

        try {
            // Try using the token as a refresh token first.
            // Grant codes and refresh tokens have the same format, so we can't distinguish them.
            // If refresh fails, we'll try exchanging it as a grant code.
            $this->token_manager->save_credentials($client_id, $client_secret, $token);

            try {
                // Try using the token as a refresh token first.
                $this->client->refresh_access_token();
            } catch (\Throwable $refresh_error) {
                // Refresh failed - might be a grant code, try exchanging it.
                $tokens = $this->client->exchange_grant_code(
                    $client_id,
                    $client_secret,
                    $token,
                    $datacenter
                );

                // Save the obtained refresh token (overwrites the grant code we saved).
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

            wp_safe_redirect(add_query_arg([
                'page' => 'zbooks-setup',
                'step' => 'organization',
            ], admin_url('admin.php')));
            exit;
        } catch (\Throwable $e) {
            $this->handle_credentials_error($e);
        }
    }

    /**
     * Handle credentials error and redirect with message.
     *
     * @param \Throwable $e The exception.
     */
    private function handle_credentials_error(\Throwable $e): void {
        $error_message = $e->getMessage();

        // Provide more helpful error messages.
        if (strpos($error_message, 'invalid_code') !== false) {
            $error_message = __('The grant code is invalid or expired. Grant codes expire quickly (3 min default). Please generate a new one.', 'zbooks-for-woocommerce');
        }

        // For dev mode, include exception class and file info.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $error_message .= ' [' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        }

        $this->redirect_with_error('connection_failed', $error_message);
    }

    /**
     * Redirect back to credentials step with error.
     *
     * @param string $error   Error code.
     * @param string $message Optional error message.
     */
    private function redirect_with_error(string $error, string $message = ''): void {
        $args = [
            'page' => 'zbooks-setup',
            'step' => 'credentials',
            'error' => $error,
        ];

        if ($message) {
            $args['message'] = urlencode($message);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Save organization selection.
     */
    private function save_organization(): void {
        $org_id = sanitize_text_field(wp_unslash($_POST['organization_id'] ?? ''));

        if (empty($org_id)) {
            wp_safe_redirect(add_query_arg([
                'page' => 'zbooks-setup',
                'step' => 'organization',
                'error' => 'no_org_selected',
            ], admin_url('admin.php')));
            exit;
        }

        update_option('zbooks_organization_id', $org_id);

        wp_safe_redirect(add_query_arg([
            'page' => 'zbooks-setup',
            'step' => 'sync',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Save sync settings.
     */
    private function save_sync_settings(): void {
        // Array sanitized in loop below.
        $input_triggers = isset($_POST['sync_triggers']) && is_array($_POST['sync_triggers']) ? wp_unslash($_POST['sync_triggers']) : [];

        // Valid trigger types.
        $valid_triggers = ['sync_draft', 'sync_submit', 'create_creditnote'];

        // Get valid order statuses.
        $valid_statuses = array_keys(wc_get_order_statuses());
        $valid_statuses = array_map(function ($status) {
            return str_replace('wc-', '', $status);
        }, $valid_statuses);
        $valid_statuses[] = ''; // Allow empty (disabled).

        $triggers = [];
        foreach ($input_triggers as $trigger => $status) {
            $trigger = sanitize_key($trigger);
            $status = sanitize_key($status);

            if (in_array($trigger, $valid_triggers, true) && in_array($status, $valid_statuses, true)) {
                $triggers[$trigger] = $status;
            }
        }

        update_option('zbooks_sync_triggers', $triggers);

        wp_safe_redirect(add_query_arg([
            'page' => 'zbooks-setup',
            'step' => 'items',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Save item mappings.
     */
    private function save_item_mappings(): void {
        // Array sanitized in loop below.
        $mappings = isset($_POST['item_mappings']) && is_array($_POST['item_mappings']) ? wp_unslash($_POST['item_mappings']) : [];

        foreach ($mappings as $product_id => $zoho_item_id) {
            $product_id = absint($product_id);
            $zoho_item_id = sanitize_text_field($zoho_item_id);

            if ($product_id && !empty($zoho_item_id)) {
                $this->item_mapping->set_mapping($product_id, $zoho_item_id);
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'zbooks-setup',
            'step' => 'ready',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Skip item mapping step.
     */
    private function skip_item_mapping(): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'zbooks-setup',
            'step' => 'ready',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Auto-map items by SKU.
     */
    private function auto_map_items(): void {
        $zoho_items = $this->get_zoho_items();
        $mapped_count = 0;

        if (!empty($zoho_items)) {
            $mapped_count = $this->item_mapping->auto_map_by_sku($zoho_items);
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'zbooks-setup',
            'step' => 'items',
            'auto_mapped' => $mapped_count,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Complete the wizard.
     */
    private function complete_wizard(): void {
        update_option('zbooks_wizard_completed', true);

        wp_safe_redirect(admin_url('admin.php?page=zbooks&setup=complete'));
        exit;
    }

    /**
     * Render the wizard.
     */
    public function render_wizard(): void {
        $steps = $this->get_steps();
        $current_step = sanitize_key(wp_unslash($_GET['step'] ?? 'welcome'));

        if (!isset($steps[$current_step])) {
            $current_step = 'welcome';
        }

        $step_keys = array_keys($steps);
        $current_index = array_search($current_step, $step_keys, true);
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php esc_html_e('ZBooks for WooCommerce Setup', 'zbooks-for-woocommerce'); ?></title>
            <?php wp_print_styles(['zbooks-wizard', 'dashicons', 'buttons']); ?>
        </head>
        <body class="zbooks-wizard-body">
            <div class="zbooks-wizard">
                <div class="zbooks-wizard-header">
                    <h1>
                        <span class="dashicons dashicons-cloud"></span>
                        <?php esc_html_e('ZBooks for WooCommerce Setup', 'zbooks-for-woocommerce'); ?>
                    </h1>
                </div>

                <div class="zbooks-wizard-steps">
                    <?php foreach ($steps as $key => $step) :
                        $index = array_search($key, $step_keys, true);
                        $class = 'zbooks-wizard-step';
                        if ($index < $current_index) {
                            $class .= ' completed';
                        } elseif ($index === $current_index) {
                            $class .= ' active';
                        }
                        ?>
                        <div class="<?php echo esc_attr($class); ?>">
                            <span class="step-number"><?php echo esc_html($index + 1); ?></span>
                            <span class="step-name"><?php echo esc_html($step['name']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="zbooks-wizard-content">
                    <?php $this->render_step($current_step); ?>
                </div>

                <div class="zbooks-wizard-footer">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=zbooks')); ?>">
                        <?php esc_html_e('Skip setup wizard', 'zbooks-for-woocommerce'); ?>
                    </a>
                </div>
            </div>

            <?php wp_print_scripts(['jquery', 'zbooks-wizard']); ?>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Render a wizard step.
     *
     * @param string $step Step key.
     */
    private function render_step(string $step): void {
        $error = sanitize_key(wp_unslash($_GET['error'] ?? ''));
        $message = sanitize_text_field(wp_unslash(urldecode($_GET['message'] ?? '')));

        if ($error) {
            $this->render_error($error, $message);
        }

        switch ($step) {
            case 'welcome':
                $this->render_welcome();
                break;
            case 'credentials':
                $this->render_credentials();
                break;
            case 'organization':
                $this->render_organization();
                break;
            case 'sync':
                $this->render_sync();
                break;
            case 'items':
                $this->render_items();
                break;
            case 'ready':
                $this->render_ready();
                break;
        }
    }

    /**
     * Render error message.
     *
     * @param string $error   Error code.
     * @param string $message Error message.
     */
    private function render_error(string $error, string $message = ''): void {
        $errors = [
            'missing_fields' => __('Please fill in all required fields.', 'zbooks-for-woocommerce'),
            'connection_failed' => __('Failed to connect to Zoho Books.', 'zbooks-for-woocommerce'),
            'no_org_selected' => __('Please select an organization.', 'zbooks-for-woocommerce'),
        ];

        $error_title = $errors[$error] ?? __('An error occurred.', 'zbooks-for-woocommerce');
        ?>
        <div class="zbooks-wizard-error" style="background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:15px 20px;border-radius:4px;margin-bottom:20px;">
            <p style="margin:0 0 5px 0;font-weight:bold;">
                <span class="dashicons dashicons-warning" style="vertical-align:middle;"></span>
                <?php echo esc_html($error_title); ?>
            </p>
            <?php if ($message) : ?>
                <p style="margin:10px 0 0 0;font-family:monospace;font-size:12px;background:#fff;padding:10px;border-radius:3px;word-break:break-word;">
                    <?php echo esc_html($message); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render welcome step.
     */
    private function render_welcome(): void {
        ?>
        <div class="zbooks-wizard-welcome">
            <h2><?php esc_html_e('Welcome to ZBooks for WooCommerce!', 'zbooks-for-woocommerce'); ?></h2>
            <p>
                <?php esc_html_e('This wizard will help you connect your WooCommerce store to Zoho Books for automatic invoice syncing.', 'zbooks-for-woocommerce'); ?>
            </p>

            <div class="zbooks-wizard-features">
                <div class="feature">
                    <span class="dashicons dashicons-update"></span>
                    <h3><?php esc_html_e('Automatic Sync', 'zbooks-for-woocommerce'); ?></h3>
                    <p><?php esc_html_e('Automatically create invoices when orders are placed or completed.', 'zbooks-for-woocommerce'); ?></p>
                </div>
                <div class="feature">
                    <span class="dashicons dashicons-admin-users"></span>
                    <h3><?php esc_html_e('Customer Sync', 'zbooks-for-woocommerce'); ?></h3>
                    <p><?php esc_html_e('Automatically create or match Zoho contacts from your customers.', 'zbooks-for-woocommerce'); ?></p>
                </div>
                <div class="feature">
                    <span class="dashicons dashicons-backup"></span>
                    <h3><?php esc_html_e('Retry Failed', 'zbooks-for-woocommerce'); ?></h3>
                    <p><?php esc_html_e('Automatic retry for failed syncs with configurable settings.', 'zbooks-for-woocommerce'); ?></p>
                </div>
            </div>

            <a href="<?php echo esc_url(add_query_arg(['page' => 'zbooks-setup', 'step' => 'credentials'], admin_url('admin.php'))); ?>" class="button button-primary button-hero">
                <?php esc_html_e("Let's Get Started", 'zbooks-for-woocommerce'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Render credentials step.
     */
    private function render_credentials(): void {
        // Prefill credentials from constants in local dev environment.
        $prefill_client_id = defined('ZBOOKS_DEV_CLIENT_ID') ? ZBOOKS_DEV_CLIENT_ID : '';
        $prefill_client_secret = defined('ZBOOKS_DEV_CLIENT_SECRET') ? ZBOOKS_DEV_CLIENT_SECRET : '';
        $prefill_refresh_token = defined('ZBOOKS_DEV_REFRESH_TOKEN') ? ZBOOKS_DEV_REFRESH_TOKEN : '';
        ?>
        <h2><?php esc_html_e('Connect to Zoho Books', 'zbooks-for-woocommerce'); ?></h2>
        <p>
            <?php
            printf(
                /* translators: %s: Zoho API console link */
                esc_html__('Create a Self Client application in the %s to get your API credentials.', 'zbooks-for-woocommerce'),
                '<a href="https://api-console.zoho.com/" target="_blank">Zoho API Console</a>'
            );
            ?>
        </p>

        <div class="zbooks-wizard-instructions">
            <h3><?php esc_html_e('How to get your credentials:', 'zbooks-for-woocommerce'); ?></h3>
            <ol>
                <li><?php esc_html_e('Go to Zoho API Console and sign in', 'zbooks-for-woocommerce'); ?></li>
                <li><?php esc_html_e('Click "Add Client" and select "Self Client"', 'zbooks-for-woocommerce'); ?></li>
                <li><?php esc_html_e('Copy the Client ID and Client Secret', 'zbooks-for-woocommerce'); ?></li>
                <li>
                    <?php esc_html_e('In "Generate Code" tab, enter this scope:', 'zbooks-for-woocommerce'); ?>
                    <br>
                    <code id="zoho-scope" style="display: inline-block; padding: 5px 10px; background: #f0f0f1; border-radius: 3px; margin: 5px 0; cursor: pointer; user-select: all;" title="<?php esc_attr_e('Click to copy', 'zbooks-for-woocommerce'); ?>">ZohoBooks.fullaccess.all</code>
                    <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('ZohoBooks.fullaccess.all'); this.textContent='<?php esc_attr_e('Copied!', 'zbooks-for-woocommerce'); ?>'; setTimeout(() => this.textContent='<?php esc_attr_e('Copy', 'zbooks-for-woocommerce'); ?>', 2000);">
                        <?php esc_html_e('Copy', 'zbooks-for-woocommerce'); ?>
                    </button>
                </li>
                <li><?php esc_html_e('Set duration (3-10 minutes recommended) and generate the code', 'zbooks-for-woocommerce'); ?></li>
                <li><?php esc_html_e('Paste the code below immediately', 'zbooks-for-woocommerce'); ?></li>
            </ol>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('zbooks_wizard', 'zbooks_wizard_nonce'); ?>
            <input type="hidden" name="zbooks_wizard_action" value="save_credentials">

            <table class="form-table">
                <tr>
                    <th><label for="datacenter"><?php esc_html_e('Zoho Datacenter', 'zbooks-for-woocommerce'); ?></label></th>
                    <td>
                        <select name="datacenter" id="datacenter" required>
                            <option value="us"><?php esc_html_e('United States (.com)', 'zbooks-for-woocommerce'); ?></option>
                            <option value="eu"><?php esc_html_e('Europe (.eu)', 'zbooks-for-woocommerce'); ?></option>
                            <option value="in"><?php esc_html_e('India (.in)', 'zbooks-for-woocommerce'); ?></option>
                            <option value="au"><?php esc_html_e('Australia (.com.au)', 'zbooks-for-woocommerce'); ?></option>
                            <option value="jp"><?php esc_html_e('Japan (.jp)', 'zbooks-for-woocommerce'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Select the datacenter where your Zoho account is hosted.', 'zbooks-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="client_id"><?php esc_html_e('Client ID', 'zbooks-for-woocommerce'); ?></label></th>
                    <td>
                        <input type="text" name="client_id" id="client_id" class="regular-text" required
                            value="<?php echo esc_attr($prefill_client_id); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="client_secret"><?php esc_html_e('Client Secret', 'zbooks-for-woocommerce'); ?></label></th>
                    <td>
                        <input type="password" name="client_secret" id="client_secret" class="regular-text" required
                            value="<?php echo esc_attr($prefill_client_secret); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="refresh_token"><?php esc_html_e('Grant Code / Refresh Token', 'zbooks-for-woocommerce'); ?></label></th>
                    <td>
                        <input type="password" name="refresh_token" id="refresh_token" class="regular-text" required
                            value="<?php echo esc_attr($prefill_refresh_token); ?>">
                        <p class="description">
                            <?php esc_html_e('The grant code generated from Zoho API Console.', 'zbooks-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="zbooks-wizard-actions">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Connect to Zoho Books', 'zbooks-for-woocommerce'); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * Render organization step.
     */
    private function render_organization(): void {
        $organizations = [];

        try {
            $organizations = $this->client->get_organizations();
        } catch (\Exception $e) {
            ?>
            <div class="zbooks-wizard-error">
                <?php echo esc_html($e->getMessage()); ?>
            </div>
            <?php
        }
        ?>
        <h2><?php esc_html_e('Select Your Organization', 'zbooks-for-woocommerce'); ?></h2>
        <p><?php esc_html_e('Choose the Zoho Books organization where invoices should be created.', 'zbooks-for-woocommerce'); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field('zbooks_wizard', 'zbooks_wizard_nonce'); ?>
            <input type="hidden" name="zbooks_wizard_action" value="save_organization">

            <?php if (empty($organizations)) : ?>
                <p class="zbooks-wizard-warning">
                    <?php esc_html_e('No organizations found. Please make sure your Zoho Books account has at least one organization.', 'zbooks-for-woocommerce'); ?>
                </p>
            <?php else : ?>
                <div class="zbooks-wizard-orgs">
                    <?php foreach ($organizations as $org) : ?>
                        <label class="zbooks-wizard-org">
                            <input type="radio" name="organization_id" value="<?php echo esc_attr($org['organization_id']); ?>" required>
                            <span class="org-details">
                                <strong><?php echo esc_html($org['name']); ?></strong>
                                <span class="org-id">ID: <?php echo esc_html($org['organization_id']); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="zbooks-wizard-actions">
                <a href="<?php echo esc_url(add_query_arg(['page' => 'zbooks-setup', 'step' => 'credentials'], admin_url('admin.php'))); ?>" class="button">
                    <?php esc_html_e('Back', 'zbooks-for-woocommerce'); ?>
                </a>
                <button type="submit" class="button button-primary" <?php disabled(empty($organizations)); ?>>
                    <?php esc_html_e('Continue', 'zbooks-for-woocommerce'); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * Render sync settings step.
     */
    private function render_sync(): void {
        // Get all order statuses for the dropdowns.
        $all_statuses = wc_get_order_statuses();
        $status_options = ['' => __('— None —', 'zbooks-for-woocommerce')];
        foreach ($all_statuses as $status_key => $status_label) {
            $status = str_replace('wc-', '', $status_key);
            $status_options[$status] = $status_label;
        }

        // Define the fixed Zoho triggers with defaults.
        $zoho_triggers = [
            'sync_draft' => [
                'label' => __('Create draft invoice', 'zbooks-for-woocommerce'),
                'description' => __('Invoice is created but not sent to customer.', 'zbooks-for-woocommerce'),
                'default' => 'processing',
            ],
            'sync_submit' => [
                'label' => __('Create and submit invoice', 'zbooks-for-woocommerce'),
                'description' => __('Invoice is created and marked as sent.', 'zbooks-for-woocommerce'),
                'default' => 'completed',
            ],
            'create_creditnote' => [
                'label' => __('Create credit note and refund', 'zbooks-for-woocommerce'),
                'description' => __('Creates credit note for the original invoice and records refund.', 'zbooks-for-woocommerce'),
                'default' => 'refunded',
            ],
        ];
        ?>
        <h2><?php esc_html_e('Configure Sync Triggers', 'zbooks-for-woocommerce'); ?></h2>
        <p><?php esc_html_e('Choose which order status triggers each Zoho Books action.', 'zbooks-for-woocommerce'); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field('zbooks_wizard', 'zbooks_wizard_nonce'); ?>
            <input type="hidden" name="zbooks_wizard_action" value="save_sync">

            <table class="widefat zbooks-wizard-triggers">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Zoho Action', 'zbooks-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Trigger on Status', 'zbooks-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($zoho_triggers as $trigger_key => $trigger_config) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($trigger_config['label']); ?></strong>
                                <p class="description" style="margin: 4px 0 0; font-size: 11px;">
                                    <?php echo esc_html($trigger_config['description']); ?>
                                </p>
                            </td>
                            <td>
                                <select name="sync_triggers[<?php echo esc_attr($trigger_key); ?>]" style="min-width: 150px;">
                                    <?php foreach ($status_options as $status_value => $status_label) : ?>
                                        <option value="<?php echo esc_attr($status_value); ?>" <?php selected($trigger_config['default'], $status_value); ?>>
                                            <?php echo esc_html($status_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="zbooks-wizard-actions">
                <a href="<?php echo esc_url(add_query_arg(['page' => 'zbooks-setup', 'step' => 'organization'], admin_url('admin.php'))); ?>" class="button">
                    <?php esc_html_e('Back', 'zbooks-for-woocommerce'); ?>
                </a>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Continue', 'zbooks-for-woocommerce'); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * Render item mapping step.
     */
    private function render_items(): void {
        $zoho_items = $this->get_zoho_items();
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => 50,
            'orderby' => 'name',
            'order' => 'ASC',
            'return' => 'objects',
        ]);

        $mappings = $this->item_mapping->get_all();
        $auto_mapped = absint(wp_unslash($_GET['auto_mapped'] ?? 0));
        ?>
        <h2><?php esc_html_e('Map Products to Zoho Items', 'zbooks-for-woocommerce'); ?></h2>
        <p>
            <?php esc_html_e('Map your WooCommerce products to Zoho Books items. This ensures invoices use the correct item codes.', 'zbooks-for-woocommerce'); ?>
        </p>

        <?php if ($auto_mapped > 0) : ?>
            <div class="zbooks-wizard-success" style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px;">
                <span class="dashicons dashicons-yes" style="color: #155724;"></span>
                <?php
                printf(
                    /* translators: %d: number of products auto-mapped */
                    esc_html__('Auto-mapped %d product(s) by SKU!', 'zbooks-for-woocommerce'),
                    absint($auto_mapped)
                );
                ?>
            </div>
        <?php endif; ?>

        <?php if (empty($zoho_items)) : ?>
            <div class="zbooks-wizard-warning" style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px;">
                <span class="dashicons dashicons-warning" style="color: #856404;"></span>
                <?php esc_html_e('No items found in Zoho Books. You can skip this step and set up mappings later.', 'zbooks-for-woocommerce'); ?>
            </div>
        <?php else : ?>
            <div style="margin-bottom: 15px;">
                <form method="post" action="" style="display: inline;">
                    <?php wp_nonce_field('zbooks_wizard', 'zbooks_wizard_nonce'); ?>
                    <input type="hidden" name="zbooks_wizard_action" value="auto_map_items">
                    <button type="submit" class="button" name="auto_map" value="1">
                        <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                        <?php esc_html_e('Auto-Map by SKU', 'zbooks-for-woocommerce'); ?>
                    </button>
                </form>
                <span style="margin-left: 10px; color: #666;">
                    <?php
                    printf(
                        /* translators: %d: number of Zoho items */
                        esc_html__('%d Zoho items available', 'zbooks-for-woocommerce'),
                        count($zoho_items)
                    );
                    ?>
                </span>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('zbooks_wizard', 'zbooks_wizard_nonce'); ?>
            <input type="hidden" name="zbooks_wizard_action" value="save_items">

            <?php if (!empty($products) && !empty($zoho_items)) : ?>
                <table class="widefat" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('WooCommerce Product', 'zbooks-for-woocommerce'); ?></th>
                            <th style="width: 120px;"><?php esc_html_e('SKU', 'zbooks-for-woocommerce'); ?></th>
                            <th style="width: 300px;"><?php esc_html_e('Zoho Item', 'zbooks-for-woocommerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product) :
                            $product_id = $product->get_id();
                            $current_mapping = $mappings[$product_id] ?? '';
                            ?>
                            <tr>
                                <td><?php echo esc_html($product->get_name()); ?></td>
                                <td><?php echo esc_html($product->get_sku() ?: '-'); ?></td>
                                <td>
                                    <select name="item_mappings[<?php echo esc_attr($product_id); ?>]" style="width: 100%;">
                                        <option value=""><?php esc_html_e('-- Not Mapped --', 'zbooks-for-woocommerce'); ?></option>
                                        <?php foreach ($zoho_items as $item) : ?>
                                            <option value="<?php echo esc_attr($item['item_id']); ?>"
                                                    <?php selected($current_mapping, $item['item_id']); ?>>
                                                <?php echo esc_html($item['name']); ?>
                                                <?php if (!empty($item['sku'])) : ?>
                                                    (<?php echo esc_html($item['sku']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (count($products) >= 50) : ?>
                    <p class="description">
                        <?php esc_html_e('Showing first 50 products. You can map additional products from ZBooks > Settings > Products tab after setup.', 'zbooks-for-woocommerce'); ?>
                    </p>
                <?php endif; ?>
            <?php elseif (empty($products)) : ?>
                <p><?php esc_html_e('No published products found in WooCommerce.', 'zbooks-for-woocommerce'); ?></p>
            <?php endif; ?>

            <p class="zbooks-wizard-actions">
                <a href="<?php echo esc_url(add_query_arg(['page' => 'zbooks-setup', 'step' => 'sync'], admin_url('admin.php'))); ?>" class="button">
                    <?php esc_html_e('Back', 'zbooks-for-woocommerce'); ?>
                </a>
                <button type="submit" name="zbooks_wizard_action" value="skip_items" class="button">
                    <?php esc_html_e('Skip for Now', 'zbooks-for-woocommerce'); ?>
                </button>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Save & Continue', 'zbooks-for-woocommerce'); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * Get Zoho items for mapping.
     *
     * @return array
     */
    private function get_zoho_items(): array {
        $cached = get_transient('zbooks_zoho_items');
        if ($cached !== false) {
            return $cached;
        }

        if (!$this->client->is_configured()) {
            return [];
        }

        try {
            $response = $this->client->request(function ($client) {
                return $client->items->getList(['per_page' => 200]);
            }, [
                'endpoint' => 'items.getList',
            ]);

            $items = [];

            // Convert object to array if needed.
            if (is_object($response)) {
                $response = json_decode(wp_json_encode($response), true);
            }

            if (is_array($response)) {
                $items_data = $response['items'] ?? $response;
                if (is_array($items_data)) {
                    foreach ($items_data as $item) {
                        if (is_array($item) && isset($item['item_id'], $item['name'])) {
                            $items[] = [
                                'item_id' => $item['item_id'],
                                'name' => $item['name'],
                                'sku' => $item['sku'] ?? '',
                            ];
                        }
                    }
                }
            }

            usort($items, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });

            set_transient('zbooks_zoho_items', $items, HOUR_IN_SECONDS);
            return $items;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Render ready step.
     */
    private function render_ready(): void {
        ?>
        <div class="zbooks-wizard-ready">
            <span class="dashicons dashicons-yes-alt"></span>
            <h2><?php esc_html_e("You're All Set!", 'zbooks-for-woocommerce'); ?></h2>
            <p><?php esc_html_e('ZBooks for WooCommerce is now configured and ready to sync your WooCommerce orders to Zoho Books.', 'zbooks-for-woocommerce'); ?></p>

            <div class="zbooks-wizard-summary">
                <h3><?php esc_html_e('What happens next?', 'zbooks-for-woocommerce'); ?></h3>
                <ul>
                    <li><?php esc_html_e('New orders will be synced automatically based on your trigger settings', 'zbooks-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('You can manually sync orders from the order edit page', 'zbooks-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Use the Bulk Sync page to sync existing orders', 'zbooks-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Check the settings page to adjust configuration anytime', 'zbooks-for-woocommerce'); ?></li>
                </ul>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('zbooks_wizard', 'zbooks_wizard_nonce'); ?>
                <input type="hidden" name="zbooks_wizard_action" value="complete">

                <p class="zbooks-wizard-actions">
                    <button type="submit" class="button button-primary button-hero">
                        <?php esc_html_e('Go to Settings', 'zbooks-for-woocommerce'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }
}
