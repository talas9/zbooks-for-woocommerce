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
        initialized: false,

        /**
         * Initialize the module
         */
        init: function() {
            // Prevent double initialization
            if (this.initialized) {
                return;
            }

            // Check if we're on the connection tab
            if (!$('#zbooks-card-connection').length) {
                return;
            }

            this.initialized = true;
            this.bindEvents();

            // Auto-check connection on page load if configured
            this.autoCheckConnection();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Test connection button
            $(document).on('click', '.zbooks-test-connection-btn', this.handleTestConnection.bind(this));

            // Select text on click for readonly inputs
            $(document).on('click', '.zbooks-select-on-click', function() {
                this.select();
            });
        },

        /**
         * Auto-check connection status on page load
         */
        autoCheckConnection: function() {
            var self = this;
            var config = this.getConfig();

            // Only auto-check if credentials are configured
            if (!config.isConfigured) {
                this.updateStatusCard('error', config.i18n.notConfigured || 'Not configured');
                return;
            }

            $.ajax({
                url: config.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''),
                type: 'POST',
                data: {
                    action: 'zbooks_test_connection',
                    nonce: config.nonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        self.updateStatusCard('success', config.i18n.connected || 'Connected');
                    } else {
                        self.updateStatusCard('error', config.i18n.failed || 'Failed', response.data.message);
                    }
                },
                error: function(xhr) {
                    var errorMsg = config.i18n.networkError || 'Network error';
                    if (xhr.status === 403) {
                        errorMsg = config.i18n.sessionExpired || 'Session expired';
                    } else if (xhr.status >= 500) {
                        errorMsg = config.i18n.serverError || 'Server error';
                    }
                    self.updateStatusCard('error', config.i18n.error || 'Error', errorMsg);
                }
            });
        },

        /**
         * Update the connection status card
         *
         * @param {string} status - 'success' or 'error'
         * @param {string} text - Status text to display
         * @param {string} errorDetails - Optional error details
         */
        updateStatusCard: function(status, text, errorDetails) {
            var $card = $('#zbooks-card-connection');
            var $icon = $card.find('.status-icon');
            var $value = $card.find('.status-value');
            var $iconSpan = $icon.find('.dashicons');
            var $errorBox = $('#zbooks-error-details');
            var $errorMessage = $('#zbooks-error-message');

            // Update icon
            $icon.removeClass('is-loading is-success is-error');
            $iconSpan.removeClass('dashicons-update dashicons-yes-alt dashicons-warning');

            if (status === 'success') {
                $icon.addClass('is-success');
                $iconSpan.addClass('dashicons-yes-alt');
            } else {
                $icon.addClass('is-error');
                $iconSpan.addClass('dashicons-warning');
            }

            // Update value
            $value.removeClass('is-loading is-success is-error');
            $value.addClass('is-' + status);
            $value.text(text);

            // Show/hide error details
            if (status === 'error' && errorDetails) {
                $errorMessage.text(errorDetails);
                $errorBox.show();
            } else {
                $errorBox.hide();
            }
        },

        /**
         * Get configuration
         */
        getConfig: function() {
            // Try module-specific config first
            if (typeof ZbooksConnectionConfig !== 'undefined') {
                return {
                    isConfigured: ZbooksConnectionConfig.isConfigured,
                    nonce: ZbooksConnectionConfig.nonce,
                    ajaxUrl: typeof ajaxurl !== 'undefined' ? ajaxurl : '',
                    i18n: ZbooksConnectionConfig.i18n || {}
                };
            }

            // Fall back to common config
            var common = window.ZbooksCommon ? window.ZbooksCommon.config : {};
            return {
                isConfigured: true, // Assume configured if no specific config
                nonce: common.nonce || '',
                ajaxUrl: common.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''),
                i18n: common.i18n || {}
            };
        },

        /**
         * Handle test connection button click
         */
        handleTestConnection: function(e) {
            e.preventDefault();

            var self = this;
            var $button = $(e.currentTarget);
            var $container = $button.closest('.zbooks-test-connection');
            var $spinner = $container.find('.spinner');
            var $result = $container.find('.zbooks-connection-result');
            var config = this.getConfig();

            if ($button.hasClass('zbooks-btn-loading')) {
                return;
            }

            // Set loading state on button
            $button.addClass('zbooks-btn-loading').prop('disabled', true);
            if ($spinner.length) {
                $spinner.addClass('is-active');
            }
            if ($result.length) {
                $result.removeClass('zbooks-connection-result--success zbooks-connection-result--error').hide();
            }

            // Set loading state on status card
            var $card = $('#zbooks-card-connection');
            var $cardIcon = $card.find('.status-icon');
            var $cardValue = $card.find('.status-value');
            var $cardIconSpan = $cardIcon.find('.dashicons');

            $cardIcon.removeClass('is-success is-error').addClass('is-loading');
            $cardIconSpan.removeClass('dashicons-yes-alt dashicons-warning').addClass('dashicons-update');
            $cardValue.removeClass('is-success is-error').addClass('is-loading').text(config.i18n.checking || 'Checking...');

            $.ajax({
                url: config.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''),
                type: 'POST',
                data: {
                    action: 'zbooks_test_connection',
                    nonce: config.nonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        self.updateStatusCard('success', config.i18n.connected || 'Connected');
                        if ($result.length) {
                            $result
                                .addClass('zbooks-connection-result--success')
                                .html('<span class="dashicons dashicons-yes-alt"></span> Connected successfully')
                                .show();
                        }
                    } else {
                        self.updateStatusCard('error', config.i18n.failed || 'Failed', response.data.message);
                        if ($result.length) {
                            $result
                                .addClass('zbooks-connection-result--error')
                                .html('<span class="dashicons dashicons-warning"></span> ' + (response.data.message || 'Connection failed'))
                                .show();
                        }
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = config.i18n.networkError || 'Network error';
                    if (xhr.status === 403) {
                        errorMsg = config.i18n.sessionExpired || 'Session expired';
                    }
                    self.updateStatusCard('error', config.i18n.error || 'Error', errorMsg + ': ' + error);
                    if ($result.length) {
                        $result
                            .addClass('zbooks-connection-result--error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + errorMsg + ': ' + error)
                            .show();
                    }
                },
                complete: function() {
                    $button.removeClass('zbooks-btn-loading').prop('disabled', false);
                    if ($spinner.length) {
                        $spinner.removeClass('is-active');
                    }
                }
            });
        }
    };

    // Note: Module is initialized via ZBooks.registerModule() above
    // and also by admin.js initLegacyModules() for backward compatibility
    // Direct initialization removed to prevent double-init

})(jQuery);
