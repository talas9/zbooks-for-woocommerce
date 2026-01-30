/**
 * Zbooks Notifications Tab Module
 *
 * Handles test email sending and email template preview.
 *
 * @package    Zbooks
 * @author     talas9
 * @link       https://github.com/talas9/zbooks-for-woocommerce
 * @since      1.0.14
 */

(function($) {
    'use strict';

    // Register module with main app for lazy loading
    if (window.ZBooks && typeof window.ZBooks.registerModule === 'function') {
        window.ZBooks.registerModule('notifications', function() {
            if (window.ZbooksNotifications) {
                window.ZbooksNotifications.init();
            }
        });
    }

    /**
     * Notifications Module
     * Handles email preview and test email functionality
     */
    window.ZbooksNotifications = {
        initialized: false,

        /**
         * Initialize the module
         */
        init: function() {
            // Prevent double initialization
            if (this.initialized) {
                return;
            }

            // Check if we're on the notifications tab
            if (!$('#zbooks_send_test_email').length && !$('#zbooks_preview_type').length) {
                return;
            }

            this.initialized = true;
            this.bindEvents();
            this.loadPreview();
            this.initDeliveryMode();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Test email button
            $(document).on('click', '#zbooks_send_test_email', function(e) {
                self.handleSendTestEmail(e);
            });

            // Preview type change
            $(document).on('change', '#zbooks_preview_type', function() {
                self.loadPreview();
            });

            // Delivery mode toggle for digest interval visibility
            $(document).on('change', 'input[name="zbooks_notification_settings[delivery_mode]"]', function() {
                self.handleDeliveryModeChange();
            });
        },

        /**
         * Handle test email button click
         */
        handleSendTestEmail: function(e) {
            e.preventDefault();

            var $button = $('#zbooks_send_test_email');
            var $result = $('#zbooks_test_email_result');
            var $type = $('#zbooks_test_email_type');

            if ($button.prop('disabled')) {
                return;
            }

            // Set loading state
            $button.prop('disabled', true).text('Sending...');
            $result.removeClass('error').text('');

            $.ajax({
                url: typeof ajaxurl !== 'undefined' ? ajaxurl : '',
                type: 'POST',
                data: {
                    action: 'zbooks_send_test_email',
                    nonce: this.getNonce(),
                    type: $type.val()
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('error').text(response.data.message || 'Test email sent successfully!');
                    } else {
                        $result.addClass('error').text(response.data.message || 'Failed to send test email');
                    }
                },
                error: function(xhr) {
                    var message = 'Network error';
                    if (xhr.status === 403) {
                        message = 'Permission denied. Please refresh the page and try again.';
                    } else if (xhr.status >= 500) {
                        message = 'Server error. Please try again later.';
                    }
                    $result.addClass('error').text(message);
                },
                complete: function() {
                    $button.prop('disabled', false).text('Send Test Email');
                }
            });
        },

        /**
         * Load email preview into iframe
         */
        loadPreview: function() {
            var $iframe = $('#zbooks_preview_frame');
            var $select = $('#zbooks_preview_type');

            if (!$iframe.length || !$select.length) {
                return;
            }

            var type = $select.val() || 'error';

            $.ajax({
                url: typeof ajaxurl !== 'undefined' ? ajaxurl : '',
                type: 'POST',
                data: {
                    action: 'zbooks_preview_email_template',
                    nonce: this.getNonce(),
                    type: type
                },
                success: function(response) {
                    if (response.success && response.data.html) {
                        var iframeDoc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;
                        iframeDoc.open();
                        iframeDoc.write(response.data.html);
                        iframeDoc.close();
                    }
                },
                error: function() {
                    var iframeDoc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write('<div style="padding: 20px; color: #dc3232;">Failed to load preview. Please refresh the page and try again.</div>');
                    iframeDoc.close();
                }
            });
        },

        /**
         * Initialize delivery mode visibility
         */
        initDeliveryMode: function() {
            this.handleDeliveryModeChange();
        },

        /**
         * Handle delivery mode radio button change
         */
        handleDeliveryModeChange: function() {
            var mode = $('input[name="zbooks_notification_settings[delivery_mode]"]:checked').val();
            var $intervalRow = $('#zbooks_digest_interval_row');

            if (!$intervalRow.length) {
                return;
            }

            if (mode === 'digest') {
                $intervalRow.slideDown();
            } else {
                $intervalRow.slideUp();
            }
        },

        /**
         * Get nonce for AJAX requests
         */
        getNonce: function() {
            // Try from localized config first
            if (typeof ZbooksNotificationsConfig !== 'undefined' && ZbooksNotificationsConfig.nonce) {
                return ZbooksNotificationsConfig.nonce;
            }

            // Try from main zbooks config
            if (typeof zbooks !== 'undefined' && zbooks.nonce) {
                return zbooks.nonce;
            }

            // Fallback to hidden field
            var $nonceField = $('input[name="zbooks_notification_nonce"]');
            if ($nonceField.length) {
                return $nonceField.val();
            }

            return '';
        }
    };

    // Auto-initialize when document ready (for backward compatibility)
    $(document).ready(function() {
        // Small delay to allow other modules to register
        setTimeout(function() {
            if (window.ZbooksNotifications && !window.ZbooksNotifications.initialized) {
                window.ZbooksNotifications.init();
            }
        }, 100);
    });

})(jQuery);
