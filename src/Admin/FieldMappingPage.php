<?php
/**
 * Field Mapping admin page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Api\ZohoClient;
use Zbooks\Repository\FieldMappingRepository;
use Zbooks\Logger\SyncLogger;

defined('ABSPATH') || exit;

/**
 * Admin page for managing custom field mappings.
 */
class FieldMappingPage {

    /**
     * Zoho client.
     *
     * @var ZohoClient
     */
    private ZohoClient $zoho_client;

    /**
     * Field mapping repository.
     *
     * @var FieldMappingRepository
     */
    private FieldMappingRepository $field_mapping_repository;

    /**
     * Logger.
     *
     * @var SyncLogger
     */
    private SyncLogger $logger;

    /**
     * Constructor.
     *
     * @param ZohoClient             $zoho_client Zoho client.
     * @param FieldMappingRepository $field_mapping_repository Field mapping repository.
     * @param SyncLogger             $logger Logger.
     */
    public function __construct(
        ZohoClient $zoho_client,
        FieldMappingRepository $field_mapping_repository,
        SyncLogger $logger
    ) {
        $this->zoho_client = $zoho_client;
        $this->field_mapping_repository = $field_mapping_repository;
        $this->logger = $logger;
        $this->register_hooks();
    }

    /**
     * Register hooks.
     */
    private function register_hooks(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('wp_ajax_zbooks_save_field_mappings', [$this, 'ajax_save_mappings']);
        add_action('wp_ajax_zbooks_fetch_zoho_custom_fields', [$this, 'ajax_fetch_zoho_fields']);
    }

    /**
     * Add submenu page under ZBooks menu.
     */
    public function add_menu_page(): void {
        add_submenu_page(
            'zbooks',
            __('Field Mapping', 'zbooks-for-woocommerce'),
            __('Field Mapping', 'zbooks-for-woocommerce'),
            'manage_woocommerce',
            'zbooks-field-mapping',
            [$this, 'render_page']
        );
    }

    /**
     * Render the page.
     */
    public function render_page(): void {
        $customer_mappings = $this->field_mapping_repository->get_customer_mappings();
        $invoice_mappings = $this->field_mapping_repository->get_invoice_mappings();
        $wc_customer_fields = $this->field_mapping_repository->get_available_customer_fields();
        $wc_invoice_fields = $this->field_mapping_repository->get_available_invoice_fields();

        // Try to fetch Zoho custom fields.
        $zoho_contact_fields = $this->get_cached_zoho_fields('contacts');
        $zoho_invoice_fields = $this->get_cached_zoho_fields('invoices');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Custom Field Mapping', 'zbooks-for-woocommerce'); ?></h1>
            <p class="description">
                <?php esc_html_e('Map WooCommerce order and customer fields to Zoho Books custom fields. These mappings will be applied when syncing orders.', 'zbooks-for-woocommerce'); ?>
            </p>

            <div id="zbooks-field-mapping-notices"></div>

            <!-- Customer Field Mappings -->
            <div class="zbooks-mapping-section">
                <h2><?php esc_html_e('Customer (Contact) Field Mappings', 'zbooks-for-woocommerce'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Map WooCommerce customer/order billing data to Zoho Books contact custom fields.', 'zbooks-for-woocommerce'); ?>
                </p>

                <table class="wp-list-table widefat fixed striped" id="zbooks-customer-mappings">
                    <thead>
                        <tr>
                            <th style="width: 40%;"><?php esc_html_e('WooCommerce Field', 'zbooks-for-woocommerce'); ?></th>
                            <th style="width: 40%;"><?php esc_html_e('Zoho Custom Field', 'zbooks-for-woocommerce'); ?></th>
                            <th style="width: 20%;"><?php esc_html_e('Actions', 'zbooks-for-woocommerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($customer_mappings)) : ?>
                            <tr class="zbooks-no-mappings">
                                <td colspan="3"><?php esc_html_e('No customer field mappings configured.', 'zbooks-for-woocommerce'); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($customer_mappings as $index => $mapping) : ?>
                                <tr class="zbooks-mapping-row" data-index="<?php echo esc_attr($index); ?>">
                                    <td>
                                        <select name="customer_mappings[<?php echo esc_attr($index); ?>][wc_field]" class="zbooks-wc-field regular-text">
                                            <option value=""><?php esc_html_e('Select field...', 'zbooks-for-woocommerce'); ?></option>
                                            <?php foreach ($wc_customer_fields as $key => $label) : ?>
                                                <option value="<?php echo esc_attr($key); ?>" <?php selected($mapping['wc_field'], $key); ?>>
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="customer_mappings[<?php echo esc_attr($index); ?>][zoho_field]" class="zbooks-zoho-field regular-text" data-type="contacts">
                                            <option value=""><?php esc_html_e('Select Zoho field...', 'zbooks-for-woocommerce'); ?></option>
                                            <?php foreach ($zoho_contact_fields as $field) : ?>
                                                <option value="<?php echo esc_attr($field['customfield_id']); ?>" <?php selected($mapping['zoho_field'], $field['customfield_id']); ?>>
                                                    <?php echo esc_html($field['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="customer_mappings[<?php echo esc_attr($index); ?>][zoho_field_label]" value="<?php echo esc_attr($mapping['zoho_field_label'] ?? ''); ?>">
                                    </td>
                                    <td>
                                        <button type="button" class="button zbooks-remove-mapping"><?php esc_html_e('Remove', 'zbooks-for-woocommerce'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">
                                <button type="button" class="button zbooks-add-mapping" data-type="customer">
                                    <?php esc_html_e('Add Customer Mapping', 'zbooks-for-woocommerce'); ?>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <hr>

            <!-- Invoice Field Mappings -->
            <div class="zbooks-mapping-section">
                <h2><?php esc_html_e('Invoice Field Mappings', 'zbooks-for-woocommerce'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Map WooCommerce order data to Zoho Books invoice custom fields.', 'zbooks-for-woocommerce'); ?>
                </p>

                <table class="wp-list-table widefat fixed striped" id="zbooks-invoice-mappings">
                    <thead>
                        <tr>
                            <th style="width: 40%;"><?php esc_html_e('WooCommerce Field', 'zbooks-for-woocommerce'); ?></th>
                            <th style="width: 40%;"><?php esc_html_e('Zoho Custom Field', 'zbooks-for-woocommerce'); ?></th>
                            <th style="width: 20%;"><?php esc_html_e('Actions', 'zbooks-for-woocommerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoice_mappings)) : ?>
                            <tr class="zbooks-no-mappings">
                                <td colspan="3"><?php esc_html_e('No invoice field mappings configured.', 'zbooks-for-woocommerce'); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($invoice_mappings as $index => $mapping) : ?>
                                <tr class="zbooks-mapping-row" data-index="<?php echo esc_attr($index); ?>">
                                    <td>
                                        <select name="invoice_mappings[<?php echo esc_attr($index); ?>][wc_field]" class="zbooks-wc-field regular-text">
                                            <option value=""><?php esc_html_e('Select field...', 'zbooks-for-woocommerce'); ?></option>
                                            <?php foreach ($wc_invoice_fields as $key => $label) : ?>
                                                <option value="<?php echo esc_attr($key); ?>" <?php selected($mapping['wc_field'], $key); ?>>
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="invoice_mappings[<?php echo esc_attr($index); ?>][zoho_field]" class="zbooks-zoho-field regular-text" data-type="invoices">
                                            <option value=""><?php esc_html_e('Select Zoho field...', 'zbooks-for-woocommerce'); ?></option>
                                            <?php foreach ($zoho_invoice_fields as $field) : ?>
                                                <option value="<?php echo esc_attr($field['customfield_id']); ?>" <?php selected($mapping['zoho_field'], $field['customfield_id']); ?>>
                                                    <?php echo esc_html($field['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="invoice_mappings[<?php echo esc_attr($index); ?>][zoho_field_label]" value="<?php echo esc_attr($mapping['zoho_field_label'] ?? ''); ?>">
                                    </td>
                                    <td>
                                        <button type="button" class="button zbooks-remove-mapping"><?php esc_html_e('Remove', 'zbooks-for-woocommerce'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">
                                <button type="button" class="button zbooks-add-mapping" data-type="invoice">
                                    <?php esc_html_e('Add Invoice Mapping', 'zbooks-for-woocommerce'); ?>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <hr>

            <p>
                <button type="button" class="button button-primary" id="zbooks-save-field-mappings">
                    <?php esc_html_e('Save Mappings', 'zbooks-for-woocommerce'); ?>
                </button>
                <button type="button" class="button" id="zbooks-refresh-zoho-fields">
                    <?php esc_html_e('Refresh Zoho Fields', 'zbooks-for-woocommerce'); ?>
                </button>
                <span class="spinner" id="zbooks-mapping-spinner"></span>
            </p>

            <!-- Template for new customer mapping row -->
            <script type="text/template" id="zbooks-customer-mapping-template">
                <tr class="zbooks-mapping-row" data-index="{{index}}">
                    <td>
                        <select name="customer_mappings[{{index}}][wc_field]" class="zbooks-wc-field regular-text">
                            <option value=""><?php esc_html_e('Select field...', 'zbooks-for-woocommerce'); ?></option>
                            <?php foreach ($wc_customer_fields as $key => $label) : ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="customer_mappings[{{index}}][zoho_field]" class="zbooks-zoho-field regular-text" data-type="contacts">
                            <option value=""><?php esc_html_e('Select Zoho field...', 'zbooks-for-woocommerce'); ?></option>
                            <?php foreach ($zoho_contact_fields as $field) : ?>
                                <option value="<?php echo esc_attr($field['customfield_id']); ?>"><?php echo esc_html($field['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="customer_mappings[{{index}}][zoho_field_label]" value="">
                    </td>
                    <td>
                        <button type="button" class="button zbooks-remove-mapping"><?php esc_html_e('Remove', 'zbooks-for-woocommerce'); ?></button>
                    </td>
                </tr>
            </script>

            <!-- Template for new invoice mapping row -->
            <script type="text/template" id="zbooks-invoice-mapping-template">
                <tr class="zbooks-mapping-row" data-index="{{index}}">
                    <td>
                        <select name="invoice_mappings[{{index}}][wc_field]" class="zbooks-wc-field regular-text">
                            <option value=""><?php esc_html_e('Select field...', 'zbooks-for-woocommerce'); ?></option>
                            <?php foreach ($wc_invoice_fields as $key => $label) : ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="invoice_mappings[{{index}}][zoho_field]" class="zbooks-zoho-field regular-text" data-type="invoices">
                            <option value=""><?php esc_html_e('Select Zoho field...', 'zbooks-for-woocommerce'); ?></option>
                            <?php foreach ($zoho_invoice_fields as $field) : ?>
                                <option value="<?php echo esc_attr($field['customfield_id']); ?>"><?php echo esc_html($field['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="invoice_mappings[{{index}}][zoho_field_label]" value="">
                    </td>
                    <td>
                        <button type="button" class="button zbooks-remove-mapping"><?php esc_html_e('Remove', 'zbooks-for-woocommerce'); ?></button>
                    </td>
                </tr>
            </script>
        </div>

        <style>
            .zbooks-mapping-section {
                margin: 20px 0;
            }
            .zbooks-mapping-row select {
                width: 100%;
            }
            #zbooks-mapping-spinner {
                float: none;
                margin-left: 10px;
            }
            #zbooks-mapping-spinner.is-active {
                visibility: visible;
            }
            .zbooks-no-mappings td {
                font-style: italic;
                color: #666;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var customerIndex = <?php echo count($customer_mappings); ?>;
            var invoiceIndex = <?php echo count($invoice_mappings); ?>;

            // Add new mapping row.
            $('.zbooks-add-mapping').on('click', function() {
                var type = $(this).data('type');
                var templateId = '#zbooks-' + type + '-mapping-template';
                var tableId = '#zbooks-' + type + '-mappings tbody';
                var index = type === 'customer' ? customerIndex++ : invoiceIndex++;

                var template = $(templateId).html().replace(/\{\{index\}\}/g, index);
                $(tableId).find('.zbooks-no-mappings').remove();
                $(tableId).append(template);
            });

            // Remove mapping row.
            $(document).on('click', '.zbooks-remove-mapping', function() {
                $(this).closest('tr').remove();
            });

            // Update hidden label field when Zoho field changes.
            $(document).on('change', '.zbooks-zoho-field', function() {
                var label = $(this).find('option:selected').text();
                $(this).siblings('input[type="hidden"]').val(label);
            });

            // Save mappings.
            $('#zbooks-save-field-mappings').on('click', function() {
                var $btn = $(this);
                var $spinner = $('#zbooks-mapping-spinner');

                $btn.prop('disabled', true);
                $spinner.addClass('is-active');

                var customerMappings = [];
                var invoiceMappings = [];

                // Collect customer mappings.
                $('#zbooks-customer-mappings .zbooks-mapping-row').each(function() {
                    var $row = $(this);
                    var wcField = $row.find('.zbooks-wc-field').val();
                    var zohoField = $row.find('.zbooks-zoho-field').val();
                    var zohoLabel = $row.find('.zbooks-zoho-field option:selected').text();

                    if (wcField && zohoField) {
                        customerMappings.push({
                            wc_field: wcField,
                            zoho_field: zohoField,
                            zoho_field_label: zohoLabel
                        });
                    }
                });

                // Collect invoice mappings.
                $('#zbooks-invoice-mappings .zbooks-mapping-row').each(function() {
                    var $row = $(this);
                    var wcField = $row.find('.zbooks-wc-field').val();
                    var zohoField = $row.find('.zbooks-zoho-field').val();
                    var zohoLabel = $row.find('.zbooks-zoho-field option:selected').text();

                    if (wcField && zohoField) {
                        invoiceMappings.push({
                            wc_field: wcField,
                            zoho_field: zohoField,
                            zoho_field_label: zohoLabel
                        });
                    }
                });

                $.ajax({
                    url: zbooks.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'zbooks_save_field_mappings',
                        nonce: zbooks.nonce,
                        customer_mappings: customerMappings,
                        invoice_mappings: invoiceMappings
                    },
                    success: function(response) {
                        $btn.prop('disabled', false);
                        $spinner.removeClass('is-active');

                        var $notices = $('#zbooks-field-mapping-notices');
                        if (response.success) {
                            $notices.html('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
                        } else {
                            $notices.html('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false);
                        $spinner.removeClass('is-active');
                        $('#zbooks-field-mapping-notices').html('<div class="notice notice-error is-dismissible"><p><?php esc_html_e('Failed to save mappings.', 'zbooks-for-woocommerce'); ?></p></div>');
                    }
                });
            });

            // Refresh Zoho fields.
            $('#zbooks-refresh-zoho-fields').on('click', function() {
                var $btn = $(this);
                var $spinner = $('#zbooks-mapping-spinner');

                $btn.prop('disabled', true);
                $spinner.addClass('is-active');

                $.ajax({
                    url: zbooks.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'zbooks_fetch_zoho_custom_fields',
                        nonce: zbooks.nonce
                    },
                    success: function(response) {
                        $btn.prop('disabled', false);
                        $spinner.removeClass('is-active');

                        if (response.success) {
                            location.reload();
                        } else {
                            $('#zbooks-field-mapping-notices').html('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false);
                        $spinner.removeClass('is-active');
                        $('#zbooks-field-mapping-notices').html('<div class="notice notice-error is-dismissible"><p><?php esc_html_e('Failed to fetch Zoho fields.', 'zbooks-for-woocommerce'); ?></p></div>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get cached Zoho custom fields.
     *
     * @param string $type 'contacts' or 'invoices'.
     * @return array
     */
    private function get_cached_zoho_fields(string $type): array {
        $cache_key = 'zbooks_zoho_custom_fields_' . $type;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        try {
            $fields = $this->fetch_zoho_custom_fields($type);
            set_transient($cache_key, $fields, HOUR_IN_SECONDS);
            return $fields;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch Zoho custom fields', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch custom fields from Zoho.
     *
     * @param string $type 'contacts' or 'invoices'.
     * @return array
     */
    private function fetch_zoho_custom_fields(string $type): array {
        try {
            // Get custom fields by fetching settings or an entity that includes them.
            // The webleit SDK accesses custom fields through settings API.
            $response = $this->zoho_client->request(function ($client) use ($type) {
                // Access the settings module for custom fields.
                if ($type === 'contacts') {
                    // Get contact settings/preferences which include custom fields.
                    return $client->settings->getList(['module' => 'contacts']);
                } else {
                    // Get invoice settings which include custom fields.
                    return $client->settings->getList(['module' => 'invoices']);
                }
            });

            // Parse the response - structure depends on SDK version.
            $custom_fields = [];

            if (is_array($response)) {
                // Check various possible response structures.
                $fields_data = $response['custom_fields']
                    ?? $response['settings']['custom_fields']
                    ?? $response['preferences']['custom_fields']
                    ?? [];

                foreach ($fields_data as $field) {
                    if (isset($field['customfield_id'])) {
                        $custom_fields[] = [
                            'customfield_id' => (string) $field['customfield_id'],
                            'label' => $field['label'] ?? $field['field_name'] ?? '',
                            'data_type' => $field['data_type'] ?? 'string',
                        ];
                    }
                }
            }

            return $custom_fields;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch Zoho custom fields from API', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            // Return empty - user can manually enter field IDs.
            return [];
        }
    }

    /**
     * AJAX handler for saving field mappings.
     */
    public function ajax_save_mappings(): void {
        check_ajax_referer('zbooks_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $customer_mappings_raw = isset( $_POST['customer_mappings'] ) ? wp_unslash( $_POST['customer_mappings'] ) : [];
        $invoice_mappings_raw = isset( $_POST['invoice_mappings'] ) ? wp_unslash( $_POST['invoice_mappings'] ) : [];

        // Sanitize each mapping value with sanitize_text_field() via sanitize_mappings().
        $customer_mappings = is_array( $customer_mappings_raw ) ? $this->sanitize_mappings( $customer_mappings_raw ) : [];
        $invoice_mappings = is_array( $invoice_mappings_raw ) ? $this->sanitize_mappings( $invoice_mappings_raw ) : [];

        $this->field_mapping_repository->save_customer_mappings($customer_mappings);
        $this->field_mapping_repository->save_invoice_mappings($invoice_mappings);

        $this->logger->info('Field mappings saved', [
            'customer_count' => count($customer_mappings),
            'invoice_count' => count($invoice_mappings),
        ]);

        wp_send_json_success(['message' => __('Field mappings saved successfully.', 'zbooks-for-woocommerce')]);
    }

    /**
     * Sanitize field mapping data.
     *
     * @param mixed $mappings Raw mapping data.
     * @return array Sanitized mappings.
     */
    private function sanitize_mappings($mappings): array {
        if (!is_array($mappings)) {
            return [];
        }

        $sanitized = [];
        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $sanitized[] = [
                'wc_field'         => isset($mapping['wc_field']) ? sanitize_text_field($mapping['wc_field']) : '',
                'zoho_field'       => isset($mapping['zoho_field']) ? sanitize_text_field($mapping['zoho_field']) : '',
                'zoho_field_label' => isset($mapping['zoho_field_label']) ? sanitize_text_field($mapping['zoho_field_label']) : '',
            ];
        }

        return $sanitized;
    }

    /**
     * AJAX handler for fetching Zoho custom fields.
     */
    public function ajax_fetch_zoho_fields(): void {
        check_ajax_referer('zbooks_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        // Clear cache.
        delete_transient('zbooks_zoho_custom_fields_contacts');
        delete_transient('zbooks_zoho_custom_fields_invoices');

        try {
            $contact_fields = $this->fetch_zoho_custom_fields('contacts');
            $invoice_fields = $this->fetch_zoho_custom_fields('invoices');

            // Re-cache.
            set_transient('zbooks_zoho_custom_fields_contacts', $contact_fields, HOUR_IN_SECONDS);
            set_transient('zbooks_zoho_custom_fields_invoices', $invoice_fields, HOUR_IN_SECONDS);

            wp_send_json_success([
                'message' => __('Zoho custom fields refreshed successfully.', 'zbooks-for-woocommerce'),
                'contact_fields' => $contact_fields,
                'invoice_fields' => $invoice_fields,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
