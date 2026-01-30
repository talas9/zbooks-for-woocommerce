/**
 * Zbooks Payments Module
 *
 * Handles payment gateway to Zoho account mapping.
 * Allows mapping WooCommerce payment gateways to Zoho Books bank accounts.
 *
 * @package    Zbooks
 * @author     talas9
 * @since      1.0.0
 */

(function($) {
    'use strict';

    /**
     * Payment Mapping Module
     * Handles payment gateway mapping functionality
     */
    window.ZbooksPaymentMapping = {
        nonce: '',

        /**
         * Initialize the payment mapping module
         */
        init: function() {
            // Check if we're on the payment mapping page
            if (!$('.zbooks-account-select').length) {
                return;
            }

            this.nonce = typeof zbooks_refresh_accounts !== 'undefined' ? zbooks_refresh_accounts.nonce : '';
            this.bindEvents();
            this.initAccountNames();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};

            // Sync account name hidden field when account select changes
            $('.zbooks-account-select').on('change', function() {
                var $select = $(this);
                var gateway = $select.data('gateway');
                var selectedOption = $select.find('option:selected');
                var accountName = selectedOption.data('name') || '';

                // Update the corresponding hidden field
                $('.zbooks-account-name[data-gateway="' + gateway + '"]').val(accountName);
            });

            // Refresh accounts button
            $('.zbooks-refresh-accounts').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text(i18n.refreshing || 'Refreshing...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zbooks_refresh_bank_accounts',
                        nonce: self.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || (i18n.failed_to_refresh || 'Failed to refresh accounts.'));
                            $btn.prop('disabled', false).text(i18n.refresh_zoho_accounts || 'Refresh Zoho Accounts');
                        }
                    },
                    error: function() {
                        alert(i18n.network_error_try_again || 'Network error. Please try again.');
                        $btn.prop('disabled', false).text(i18n.refresh_zoho_accounts || 'Refresh Zoho Accounts');
                    }
                });
            });
        },

        /**
         * Initialize account names on page load
         */
        initAccountNames: function() {
            // Initialize account names for existing selections
            $('.zbooks-account-select').each(function() {
                var $select = $(this);
                var gateway = $select.data('gateway');
                var selectedOption = $select.find('option:selected');
                var accountName = selectedOption.data('name') || '';
                var $hidden = $('.zbooks-account-name[data-gateway="' + gateway + '"]');

                // Only update if hidden field is empty but select has a value
                if (!$hidden.val() && accountName) {
                    $hidden.val(accountName);
                }
            });
        }
    };

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        ZbooksPaymentMapping.init();
    });

    // Register with ZBooks module system if available
    if (window.ZBooks && typeof window.ZBooks.registerModule === 'function') {
        window.ZBooks.registerModule('payments', function() {
            ZbooksPaymentMapping.init();
        });
    }

})(jQuery);
