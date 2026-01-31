/**
 * Orders Tab Settings Validation Module
 *
 * Validates trigger mappings and shows warnings for illogical configurations.
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
        window.ZBooks.registerModule('orders-tab', function() {
            if (window.ZbooksOrdersTab) {
                window.ZbooksOrdersTab.init();
            }
        });
    }

    /**
     * Orders Tab Module
     * Validates trigger configurations and shows warnings
     */
    window.ZbooksOrdersTab = {
        initialized: false,

        // WooCommerce order status hierarchy (lower index = earlier in flow)
        statusOrder: {
            'pending': 0,
            'on-hold': 1,
            'processing': 2,
            'completed': 3,
            'refunded': 4,
            'cancelled': 5,
            'failed': 6
        },

        /**
         * Initialize the module
         */
        init: function() {
            // Prevent double initialization
            if (this.initialized) {
                return;
            }

            // Only run on orders settings tab
            if (!$('select[name="zbooks_sync_triggers[sync_draft]"]').length) {
                return;
            }

            this.initialized = true;
            this.bindEvents();
            this.validate(); // Initial validation on page load
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Validate on any trigger dropdown change
            $(document).on('change', 'select[name^="zbooks_sync_triggers"]', function() {
                self.validate();
            });

            // Restore defaults button
            $(document).on('click', '.zbooks-restore-defaults', function(e) {
                e.preventDefault();
                self.restoreDefaults($(this));
            });
        },

        /**
         * Restore default trigger mappings
         *
         * @param {jQuery} $button The restore button element
         */
        restoreDefaults: function($button) {
            var defaults = $button.data('defaults');

            if (!defaults) {
                return;
            }

            // Apply each default value to its corresponding select
            $.each(defaults, function(trigger, status) {
                $('select[name="zbooks_sync_triggers[' + trigger + ']"]').val(status);
            });

            // Re-validate after restoring
            this.validate();

            // Show brief confirmation
            var originalText = $button.text();
            $button.text('Restored!');
            setTimeout(function() {
                $button.text(originalText);
            }, 1500);
        },

        /**
         * Run validation on trigger configuration
         */
        validate: function() {
            var warnings = [];

            var syncDraft = $('select[name="zbooks_sync_triggers[sync_draft]"]').val();
            var syncSubmit = $('select[name="zbooks_sync_triggers[sync_submit]"]').val();
            var applyPayment = $('select[name="zbooks_sync_triggers[apply_payment]"]').val();

            // Validation 1: sync_draft should not come AFTER sync_submit
            if (syncDraft && syncSubmit && this.getStatusIndex(syncDraft) > this.getStatusIndex(syncSubmit)) {
                warnings.push(
                    'Draft invoice is set to trigger AFTER invoice submission. ' +
                    'Typically, drafts should be created at an earlier status.'
                );
            }

            // Validation 2: apply_payment should not be BEFORE sync_submit
            if (applyPayment && syncSubmit && this.getStatusIndex(applyPayment) < this.getStatusIndex(syncSubmit)) {
                warnings.push(
                    'Payment is set to apply BEFORE the invoice is submitted. ' +
                    'Payment should typically occur at or after invoice submission.'
                );
            }

            // Validation 3: Same status for conflicting actions (draft and submit)
            if (syncDraft && syncSubmit && syncDraft === syncSubmit) {
                warnings.push(
                    'Draft creation and invoice submission use the same status ("' +
                    this.getStatusLabel(syncDraft) + '"). Invoice will be submitted directly.'
                );
            }

            this.showWarnings(warnings);
        },

        /**
         * Get numeric index for a status (lower = earlier in workflow)
         *
         * @param {string} status The WooCommerce order status
         * @return {number} Status index
         */
        getStatusIndex: function(status) {
            return this.statusOrder[status] !== undefined ? this.statusOrder[status] : 999;
        },

        /**
         * Get human-readable label for a status
         *
         * @param {string} status The WooCommerce order status
         * @return {string} Status label
         */
        getStatusLabel: function(status) {
            var $option = $('select[name="zbooks_sync_triggers[sync_draft]"] option[value="' + status + '"]');
            return $option.length ? $option.text() : status;
        },

        /**
         * Display validation warnings
         *
         * @param {Array} warnings Array of warning messages
         */
        showWarnings: function(warnings) {
            // Remove existing warnings container
            $('#zbooks-trigger-warnings').remove();

            if (warnings.length === 0) {
                return;
            }

            var html = '<div id="zbooks-trigger-warnings" class="zbooks-trigger-warnings">';
            html += '<p class="zbooks-trigger-warnings-title">';
            html += '<span class="dashicons dashicons-warning"></span> ';
            html += 'Configuration Warnings';
            html += '</p>';
            html += '<ul>';

            warnings.forEach(function(warning) {
                html += '<li>' + warning + '</li>';
            });

            html += '</ul>';
            html += '<p class="zbooks-trigger-warnings-note">These are warnings only. You can still save your settings.</p>';
            html += '</div>';

            // Insert before the submit button
            var $submitBtn = $('input[type="submit"], .submit input[type="submit"]');
            if ($submitBtn.length) {
                $submitBtn.first().closest('p, .submit').before(html);
            } else {
                // Fallback: append after the last info box
                $('.zbooks-info-box').last().after(html);
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        setTimeout(function() {
            if (window.ZbooksOrdersTab && !window.ZbooksOrdersTab.initialized) {
                window.ZbooksOrdersTab.init();
            }
        }, 100);
    });

})(jQuery);
