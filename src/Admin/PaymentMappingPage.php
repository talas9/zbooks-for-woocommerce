<?php
/**
 * Payment method mapping admin page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Api\ZohoClient;
use Zbooks\Repository\PaymentMethodMappingRepository;
use Zbooks\Logger\SyncLogger;

defined('ABSPATH') || exit;

/**
 * Admin page for mapping WooCommerce payment methods to Zoho.
 */
class PaymentMappingPage {

    /**
     * Zoho client.
     *
     * @var ZohoClient
     */
    private ZohoClient $zoho_client;

    /**
     * Mapping repository.
     *
     * @var PaymentMethodMappingRepository
     */
    private PaymentMethodMappingRepository $repository;

    /**
     * Logger.
     *
     * @var SyncLogger
     */
    private SyncLogger $logger;

    /**
     * Cached Zoho bank accounts.
     *
     * @var array|null
     */
    private ?array $zoho_accounts = null;

    /**
     * Constructor.
     *
     * @param ZohoClient                     $zoho_client Zoho client instance.
     * @param PaymentMethodMappingRepository $repository  Mapping repository.
     * @param SyncLogger                     $logger      Logger instance.
     */
    public function __construct(
        ZohoClient $zoho_client,
        PaymentMethodMappingRepository $repository,
        SyncLogger $logger
    ) {
        $this->zoho_client = $zoho_client;
        $this->repository = $repository;
        $this->logger = $logger;

        add_action('admin_menu', [$this, 'add_submenu_page']);
        add_action('admin_init', [$this, 'handle_form_submission']);
    }

    /**
     * Add submenu page.
     */
    public function add_submenu_page(): void {
        add_submenu_page(
            'zbooks',
            __('Payment Mapping', 'zbooks-for-woocommerce'),
            __('Payment Mapping', 'zbooks-for-woocommerce'),
            'manage_woocommerce',
            'zbooks-payment-mapping',
            [$this, 'render_page']
        );
    }

    /**
     * Handle form submission.
     */
    public function handle_form_submission(): void {
        if (!isset($_POST['zbooks_payment_mapping_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['zbooks_payment_mapping_nonce'])), 'zbooks_save_payment_mapping')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $mappings = isset($_POST['zbooks_payment_mapping']) ? map_deep(wp_unslash($_POST['zbooks_payment_mapping']), 'sanitize_text_field') : [];
        $this->repository->save_all($mappings);

        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Payment method mappings saved successfully.', 'zbooks-for-woocommerce'); ?></p>
            </div>
            <?php
        });
    }

    /**
     * Render the admin page.
     */
    public function render_page(): void {
        $wc_gateways = $this->get_wc_payment_gateways();
        $mappings = $this->repository->get_all();
        $zoho_accounts = $this->get_zoho_bank_accounts();
        $zoho_modes = $this->get_zoho_payment_modes();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment Method Mapping', 'zbooks-for-woocommerce'); ?></h1>

            <p class="description">
                <?php esc_html_e('Map your WooCommerce payment methods to Zoho Books payment modes and specify which bank/cash account payments should be deposited to.', 'zbooks-for-woocommerce'); ?>
            </p>

            <?php if (empty($zoho_accounts)) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e('Unable to fetch bank accounts from Zoho Books. Please ensure your API credentials are configured correctly.', 'zbooks-for-woocommerce'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('zbooks_save_payment_mapping', 'zbooks_payment_mapping_nonce'); ?>

                <table class="widefat striped" style="max-width: 900px; margin-top: 20px;">
                    <thead>
                        <tr>
                            <th style="padding: 10px 15px;"><?php esc_html_e('WooCommerce Payment Method', 'zbooks-for-woocommerce'); ?></th>
                            <th style="padding: 10px 15px;"><?php esc_html_e('Zoho Payment Mode', 'zbooks-for-woocommerce'); ?></th>
                            <th style="padding: 10px 15px;"><?php esc_html_e('Deposit To (Bank/Cash Account)', 'zbooks-for-woocommerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($wc_gateways as $gateway_id => $gateway_title) : ?>
                            <?php $mapping = $mappings[$gateway_id] ?? []; ?>
                            <tr>
                                <td style="padding: 8px 15px;">
                                    <strong><?php echo esc_html($gateway_title); ?></strong>
                                    <br>
                                    <code style="font-size: 11px; color: #666;"><?php echo esc_html($gateway_id); ?></code>
                                </td>
                                <td style="padding: 8px 15px;">
                                    <select name="zbooks_payment_mapping[<?php echo esc_attr($gateway_id); ?>][zoho_mode]"
                                            style="width: 100%; max-width: 200px;">
                                        <option value=""><?php esc_html_e('-- Select Mode --', 'zbooks-for-woocommerce'); ?></option>
                                        <?php foreach ($zoho_modes as $mode) : ?>
                                            <option value="<?php echo esc_attr($mode); ?>"
                                                <?php selected($mapping['zoho_mode'] ?? '', $mode); ?>>
                                                <?php echo esc_html($mode); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td style="padding: 8px 15px;">
                                    <select name="zbooks_payment_mapping[<?php echo esc_attr($gateway_id); ?>][zoho_account_id]"
                                            class="zbooks-account-select"
                                            data-gateway="<?php echo esc_attr($gateway_id); ?>"
                                            style="width: 100%; max-width: 250px;">
                                        <option value="" data-name=""><?php esc_html_e('-- Select Account --', 'zbooks-for-woocommerce'); ?></option>
                                        <?php
                                        $grouped = $this->group_accounts_by_type($zoho_accounts);
                                        foreach ($grouped as $type_label => $type_accounts) :
                                        ?>
                                            <optgroup label="<?php echo esc_attr($type_label); ?>">
                                                <?php foreach ($type_accounts as $account) : ?>
                                                    <option value="<?php echo esc_attr($account['account_id']); ?>"
                                                        data-name="<?php echo esc_attr($account['account_name']); ?>"
                                                        <?php selected($mapping['zoho_account_id'] ?? '', $account['account_id']); ?>>
                                                        <?php echo esc_html($account['account_name']); ?>
                                                        <?php if (!empty($account['account_code'])) : ?>
                                                            (<?php echo esc_html($account['account_code']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden"
                                           name="zbooks_payment_mapping[<?php echo esc_attr($gateway_id); ?>][zoho_account_name]"
                                           class="zbooks-account-name"
                                           data-gateway="<?php echo esc_attr($gateway_id); ?>"
                                           value="<?php echo esc_attr($mapping['zoho_account_name'] ?? ''); ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top: 20px;">
                    <?php submit_button(__('Save Mappings', 'zbooks-for-woocommerce'), 'primary', 'submit', false); ?>
                    <button type="button" class="button zbooks-refresh-accounts" style="margin-left: 10px;">
                        <?php esc_html_e('Refresh Zoho Accounts', 'zbooks-for-woocommerce'); ?>
                    </button>
                </p>
            </form>

            <hr style="margin: 30px 0;">

            <h2><?php esc_html_e('Default Mappings', 'zbooks-for-woocommerce'); ?></h2>
            <p class="description">
                <?php esc_html_e('If a payment method is not mapped above, the following default mappings will be used:', 'zbooks-for-woocommerce'); ?>
            </p>

            <table class="widefat" style="max-width: 500px; margin-top: 15px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Payment Method', 'zbooks-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Default Mode', 'zbooks-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>PayPal</td><td>PayPal</td></tr>
                    <tr><td>Stripe / Credit Card</td><td>Credit Card</td></tr>
                    <tr><td>BACS (Bank Transfer)</td><td>Bank Transfer</td></tr>
                    <tr><td>Cheque</td><td>Check</td></tr>
                    <tr><td>Cash on Delivery</td><td>Cash</td></tr>
                    <tr><td>Other methods</td><td>Others</td></tr>
                </tbody>
            </table>

            <p class="description" style="margin-top: 15px;">
                <strong><?php esc_html_e('Note:', 'zbooks-for-woocommerce'); ?></strong>
                <?php esc_html_e('If no "Deposit To" account is selected, Zoho Books will use your default bank account.', 'zbooks-for-woocommerce'); ?>
            </p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Sync account name hidden field when account select changes.
            $('.zbooks-account-select').on('change', function() {
                var $select = $(this);
                var gateway = $select.data('gateway');
                var selectedOption = $select.find('option:selected');
                var accountName = selectedOption.data('name') || '';

                // Update the corresponding hidden field.
                $('.zbooks-account-name[data-gateway="' + gateway + '"]').val(accountName);
            });

            // Initialize account names on page load (for existing selections).
            $('.zbooks-account-select').each(function() {
                var $select = $(this);
                var gateway = $select.data('gateway');
                var selectedOption = $select.find('option:selected');
                var accountName = selectedOption.data('name') || '';
                var $hidden = $('.zbooks-account-name[data-gateway="' + gateway + '"]');

                // Only update if hidden field is empty but select has a value.
                if (!$hidden.val() && accountName) {
                    $hidden.val(accountName);
                }
            });

            $('.zbooks-refresh-accounts').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Refreshing...', 'zbooks-for-woocommerce')); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zbooks_refresh_bank_accounts',
                        nonce: '<?php echo esc_js(wp_create_nonce('zbooks_refresh_accounts')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php echo esc_js(__('Failed to refresh accounts.', 'zbooks-for-woocommerce')); ?>');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Refresh Zoho Accounts', 'zbooks-for-woocommerce')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error. Please try again.', 'zbooks-for-woocommerce')); ?>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Refresh Zoho Accounts', 'zbooks-for-woocommerce')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get all enabled WooCommerce payment gateways.
     *
     * @return array Array of gateway ID => title.
     */
    private function get_wc_payment_gateways(): array {
        $gateways = [];

        if (!class_exists('WC_Payment_Gateways')) {
            return $gateways;
        }

        $wc_gateways = \WC_Payment_Gateways::instance()->payment_gateways();

        foreach ($wc_gateways as $gateway) {
            // Include all gateways, not just enabled ones, for mapping purposes.
            $gateways[$gateway->id] = $gateway->get_method_title() ?: $gateway->get_title();
        }

        return $gateways;
    }

    /**
     * Get Zoho Books deposit accounts (bank, cash, and payment clearing).
     *
     * Fetches from both Chart of Accounts and Bank Accounts endpoints
     * to get all valid deposit destinations including payment clearing accounts.
     *
     * @return array Array of accounts.
     */
    private function get_zoho_bank_accounts(): array {
        // Check cache first.
        $cached = get_transient('zbooks_zoho_bank_accounts');
        if ($cached !== false) {
            return $cached;
        }

        if (!$this->zoho_client->is_configured()) {
            return [];
        }

        $accounts = [];
        $seen_ids = [];

        // 1. Fetch from Bank Accounts endpoint (includes payment clearing accounts).
        try {
            $bank_response = $this->zoho_client->request(function ($client) {
                return $client->bankaccounts->getList();
            });

            $bank_data = [];
            if (is_array($bank_response)) {
                $bank_data = $bank_response['bankaccounts'] ?? $bank_response;
            } elseif (method_exists($bank_response, 'toArray')) {
                $bank_data = $bank_response->toArray();
            }

            foreach ($bank_data as $account) {
                $account_id = $account['account_id'] ?? '';
                if ($account_id && !isset($seen_ids[$account_id])) {
                    $seen_ids[$account_id] = true;
                    $accounts[] = [
                        'account_id' => $account_id,
                        'account_name' => $account['account_name'] ?? '',
                        'account_code' => $account['account_code'] ?? '',
                        'account_type' => $account['account_type'] ?? 'bank',
                    ];
                }
            }

            $this->logger->debug('Fetched from bankaccounts endpoint', [
                'count' => count($bank_data),
            ]);
        } catch (\Exception $e) {
            $this->logger->debug('Bank accounts fetch failed, trying Chart of Accounts', [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Fetch from Chart of Accounts for additional account types.
        try {
            $coa_response = $this->zoho_client->request(function ($client) {
                return $client->chartofaccounts->getList([
                    'filter_by' => 'AccountType.Active',
                ]);
            });

            $coa_data = [];
            if (is_array($coa_response)) {
                $coa_data = $coa_response['chartofaccounts'] ?? $coa_response;
            } elseif (method_exists($coa_response, 'toArray')) {
                $coa_data = $coa_response->toArray();
            }

            // Include payment clearing, cash, and other current asset accounts not already added.
            $valid_types = ['cash', 'other_current_asset', 'payment_clearing'];

            foreach ($coa_data as $account) {
                $account_id = $account['account_id'] ?? '';
                $account_type = strtolower($account['account_type'] ?? '');

                if ($account_id && !isset($seen_ids[$account_id]) && in_array($account_type, $valid_types, true)) {
                    $seen_ids[$account_id] = true;
                    $accounts[] = [
                        'account_id' => $account_id,
                        'account_name' => $account['account_name'] ?? '',
                        'account_code' => $account['account_code'] ?? '',
                        'account_type' => $account['account_type'] ?? '',
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch from Chart of Accounts', [
                'error' => $e->getMessage(),
            ]);
        }

        // Sort by account type then name for grouped display.
        usort($accounts, function ($a, $b) {
            $type_cmp = strcasecmp($a['account_type'], $b['account_type']);
            if ($type_cmp !== 0) {
                return $type_cmp;
            }
            return strcasecmp($a['account_name'], $b['account_name']);
        });

        // Cache for 1 hour.
        set_transient('zbooks_zoho_bank_accounts', $accounts, HOUR_IN_SECONDS);

        $this->logger->debug('Fetched Zoho deposit accounts', [
            'count' => count($accounts),
        ]);

        return $accounts;
    }

    /**
     * Get standard Zoho payment modes.
     *
     * These are the system-defined payment modes in Zoho Books.
     * Note: Zoho Books API doesn't have an endpoint to fetch custom payment modes.
     * These values are based on the Zoho Books API documentation.
     *
     * @return array Array of payment mode names.
     */
    private function get_zoho_payment_modes(): array {
        return [
            'Cash',
            'Check',
            'Credit Card',
            'Bank Transfer',
            'Bank Remittance',
            'PayPal',
            'Stripe',
            'Braintree',
            'Authorize.Net',
            '2Checkout',
            'Payflow Pro',
            'Google Checkout',
            'Others',
        ];
    }

    /**
     * Group accounts by their type for optgroup display.
     *
     * @param array $accounts List of accounts.
     * @return array Grouped accounts with type labels as keys.
     */
    private function group_accounts_by_type(array $accounts): array {
        $type_labels = [
            'bank' => __('Bank', 'zbooks-for-woocommerce'),
            'cash' => __('Cash', 'zbooks-for-woocommerce'),
            'payment_clearing' => __('Payment Clearing Account', 'zbooks-for-woocommerce'),
            'other_current_asset' => __('Other Current Asset', 'zbooks-for-woocommerce'),
            'other_current_liability' => __('Other Current Liability', 'zbooks-for-woocommerce'),
            'other_asset' => __('Other Asset', 'zbooks-for-woocommerce'),
            'undeposited_funds' => __('Undeposited Funds', 'zbooks-for-woocommerce'),
        ];

        $grouped = [];

        foreach ($accounts as $account) {
            $type = strtolower($account['account_type'] ?? 'other');
            $label = $type_labels[$type] ?? ucwords(str_replace('_', ' ', $type));

            if (!isset($grouped[$label])) {
                $grouped[$label] = [];
            }
            $grouped[$label][] = $account;
        }

        // Sort groups by a predefined order.
        $order = [
            __('Bank', 'zbooks-for-woocommerce'),
            __('Cash', 'zbooks-for-woocommerce'),
            __('Payment Clearing Account', 'zbooks-for-woocommerce'),
            __('Other Current Asset', 'zbooks-for-woocommerce'),
            __('Other Current Liability', 'zbooks-for-woocommerce'),
            __('Undeposited Funds', 'zbooks-for-woocommerce'),
            __('Other Asset', 'zbooks-for-woocommerce'),
        ];

        $sorted = [];
        foreach ($order as $key) {
            if (isset($grouped[$key])) {
                $sorted[$key] = $grouped[$key];
            }
        }

        // Add any remaining groups not in the order.
        foreach ($grouped as $key => $value) {
            if (!isset($sorted[$key])) {
                $sorted[$key] = $value;
            }
        }

        return $sorted;
    }
}
