/**
 * Zbooks Connection Tab Module
 *
 * Handles OAuth connection and connection testing functionality.
 *
 * @package    Zbooks
 * @author     talas9
 * @link       https://github.com/talas9/zbooks-for-woocommerce
 * @since      1.0.0
 */

(function($) {
    'use strict';

    // Register module with main app for lazy loading
    if (window.ZBooks && typeof window.ZBooks.registerModule === 'function') {
        window.ZBooks.registerModule('connection-tab', function() {
            if (window.ZbooksConnectionTab) {
                window.ZbooksConnectionTab.init();
            }
        });
    }

    /**
     * Connection Tab Module
     * Handles Zoho OAuth and connection testing
     */
    window.ZbooksConnectionTab = {

        /**
         * Initialize the module
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Test connection button
            $(document).on('click', '.zbooks-test-connection-btn', this.handleTestConnection.bind(this));
        },

        /**
         * Handle test connection button click
         */
        handleTestConnection: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var $container = $button.closest('.zbooks-test-connection');
            var $spinner = $container.find('.spinner');
            var $result = $container.find('.zbooks-connection-result');

            if ($button.hasClass('zbooks-btn-loading')) {
                return;
            }

            // Set loading state
            $button.addClass('zbooks-btn-loading').prop('disabled', true);
            $spinner.addClass('is-active');
            $result.removeClass('zbooks-connection-result--success zbooks-connection-result--error').hide();

            var config = window.ZbooksCommon ? window.ZbooksCommon.config : {};

            $.ajax({
                url: config.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'zbooks_test_connection',
                    nonce: config.nonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        $result
                            .addClass('zbooks-connection-result--success')
                            .html('<span class="dashicons dashicons-yes-alt"></span> Connected successfully')
                            .show();
                    } else {
                        $result
                            .addClass('zbooks-connection-result--error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + (response.data.message || 'Connection failed'))
                            .show();
                    }
                },
                error: function(xhr, status, error) {
                    $result
                        .addClass('zbooks-connection-result--error')
                        .html('<span class="dashicons dashicons-warning"></span> Network error: ' + error)
                        .show();
                },
                complete: function() {
                    $button.removeClass('zbooks-btn-loading').prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        }
    };

    // Note: Module is initialized via ZBooks.registerModule() above
    // and also by admin.js initLegacyModules() for backward compatibility
    // Direct initialization removed to prevent double-init

})(jQuery);
