/**
 * Zbooks Custom Fields Module
 *
 * Handles custom field mapping for customers, invoices, and credit notes.
 * Allows mapping WooCommerce fields to Zoho Books custom fields.
 *
 * @package    Zbooks
 * @author     talas9
 * @since      1.0.0
 */

(function($) {
    'use strict';

    /**
     * Field Mapping Module
     * Handles custom field mapping functionality
     */
    window.ZbooksFieldMapping = {
        customerIndex: 0,
        invoiceIndex: 0,
        creditnoteIndex: 0,
        initialized: false,

        /**
         * Initialize the field mapping module
         */
        init: function() {
            // Prevent double initialization
            if (this.initialized) {
                return;
            }

            // Check if we're on the field mapping page
            if (!$('#zbooks-customer-mappings').length) {
                return;
            }

            this.initialized = true;

            // Initialize indices from existing mapping counts
            this.customerIndex = $('#zbooks-customer-mappings .zbooks-mapping-row').length;
            this.invoiceIndex = $('#zbooks-invoice-mappings .zbooks-mapping-row').length;
            this.creditnoteIndex = $('#zbooks-creditnote-mappings .zbooks-mapping-row').length;

            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Add new mapping row
            $(document).on('click', '.zbooks-add-mapping', function() {
                var type = $(this).data('type');
                var templateId = '#zbooks-' + type + '-mapping-template';
                var tableId = '#zbooks-' + type + '-mappings tbody';
                var index;

                if (type === 'customer') {
                    index = self.customerIndex++;
                } else if (type === 'invoice') {
                    index = self.invoiceIndex++;
                } else {
                    index = self.creditnoteIndex++;
                }

                var template = $(templateId).html().replace(/\{\{index\}\}/g, index);
                $(tableId).find('.zbooks-no-mappings').remove();
                $(tableId).append(template);
            });

            // Remove mapping row
            $(document).on('click', '.zbooks-remove-mapping', function() {
                $(this).closest('tr').remove();
            });

            // Update hidden label and type fields when Zoho field changes
            $(document).on('change', '.zbooks-zoho-field', function() {
                var $selected = $(this).find('option:selected');
                var label = $selected.text();
                var fieldType = $selected.data('type') || 'string';
                $(this).siblings('input[name$="[zoho_field_label]"]').val(label);
                $(this).siblings('input[name$="[zoho_field_type]"]').val(fieldType);
            });

            // Save mappings
            $('#zbooks-save-field-mappings').on('click', function() {
                self.saveMappings();
            });

            // Refresh Zoho fields
            $('#zbooks-refresh-zoho-fields').on('click', function() {
                self.refreshZohoFields();
            });
        },

        /**
         * Save field mappings
         */
        saveMappings: function() {
            var $btn = $('#zbooks-save-field-mappings');
            var $spinner = $('#zbooks-mapping-spinner');
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};
            var ajaxUrl = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.ajaxUrl) || ajaxurl;
            var nonce = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.nonce) || '';

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            var customerMappings = [];
            var invoiceMappings = [];
            var creditnoteMappings = [];

            // Collect customer mappings
            $('#zbooks-customer-mappings .zbooks-mapping-row').each(function() {
                var $row = $(this);
                var wcField = $row.find('.zbooks-wc-field').val();
                var $zohoSelect = $row.find('.zbooks-zoho-field');
                var zohoField = $zohoSelect.val();
                var $selected = $zohoSelect.find('option:selected');
                var zohoLabel = $selected.text();
                var zohoType = $selected.data('type') || 'string';

                if (wcField && zohoField) {
                    customerMappings.push({
                        wc_field: wcField,
                        zoho_field: zohoField,
                        zoho_field_label: zohoLabel,
                        zoho_field_type: zohoType
                    });
                }
            });

            // Collect invoice mappings
            $('#zbooks-invoice-mappings .zbooks-mapping-row').each(function() {
                var $row = $(this);
                var wcField = $row.find('.zbooks-wc-field').val();
                var $zohoSelect = $row.find('.zbooks-zoho-field');
                var zohoField = $zohoSelect.val();
                var $selected = $zohoSelect.find('option:selected');
                var zohoLabel = $selected.text();
                var zohoType = $selected.data('type') || 'string';

                if (wcField && zohoField) {
                    invoiceMappings.push({
                        wc_field: wcField,
                        zoho_field: zohoField,
                        zoho_field_label: zohoLabel,
                        zoho_field_type: zohoType
                    });
                }
            });

            // Collect credit note mappings
            $('#zbooks-creditnote-mappings .zbooks-mapping-row').each(function() {
                var $row = $(this);
                var wcField = $row.find('.zbooks-wc-field').val();
                var $zohoSelect = $row.find('.zbooks-zoho-field');
                var zohoField = $zohoSelect.val();
                var $selected = $zohoSelect.find('option:selected');
                var zohoLabel = $selected.text();
                var zohoType = $selected.data('type') || 'string';

                if (wcField && zohoField) {
                    creditnoteMappings.push({
                        wc_field: wcField,
                        zoho_field: zohoField,
                        zoho_field_label: zohoLabel,
                        zoho_field_type: zohoType
                    });
                }
            });

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_save_field_mappings',
                    nonce: nonce,
                    customer_mappings: customerMappings,
                    invoice_mappings: invoiceMappings,
                    creditnote_mappings: creditnoteMappings
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
                    $('#zbooks-field-mapping-notices').html('<div class="notice notice-error is-dismissible"><p>' + (i18n.failed_to_save_mappings || 'Failed to save mappings.') + '</p></div>');
                }
            });
        },

        /**
         * Refresh Zoho custom fields
         */
        refreshZohoFields: function() {
            var $btn = $('#zbooks-refresh-zoho-fields');
            var $spinner = $('#zbooks-mapping-spinner');
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};
            var ajaxUrl = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.ajaxUrl) || ajaxurl;
            var nonce = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.nonce) || '';

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_fetch_zoho_custom_fields',
                    nonce: nonce
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
                    $('#zbooks-field-mapping-notices').html('<div class="notice notice-error is-dismissible"><p>' + (i18n.failed_to_fetch_fields || 'Failed to fetch Zoho fields.') + '</p></div>');
                }
            });
        }
    };

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        ZbooksFieldMapping.init();
    });

    // Register with ZBooks module system if available
    if (window.ZBooks && typeof window.ZBooks.registerModule === 'function') {
        window.ZBooks.registerModule('custom-fields', function() {
            ZbooksFieldMapping.init();
        });
    }

})(jQuery);
