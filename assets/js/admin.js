/**
 * Zbooks Admin JavaScript
 *
 * Handles AJAX sync operations, bulk sync with progress, test connection,
 * and UI interactions for the WordPress admin interface.
 *
 * @package    Zbooks
 * @author     talas9
 * @link       https://github.com/talas9/zbooks-for-woocommerce
 * @since      1.0.0
 */

(function($) {
    'use strict';

    /**
     * Zbooks Admin Module
     *
     * Main module for handling all admin JavaScript functionality.
     */
    var ZbooksAdmin = {

        /**
         * Configuration and state
         */
        config: {
            ajaxUrl: typeof zbooks !== 'undefined' ? zbooks.ajax_url : ajaxurl,
            nonce: typeof zbooks !== 'undefined' ? zbooks.nonce : '',
            i18n: typeof zbooks !== 'undefined' ? zbooks.i18n : {}
        },

        /**
         * Bulk sync state
         */
        bulkState: {
            isProcessing: false,
            queue: [],
            processed: 0,
            succeeded: 0,
            failed: 0,
            total: 0
        },

        /**
         * Initialize the module
         *
         * @return {void}
         */
        init: function() {
            this.bindEvents();
            this.initSelectAll();
        },

        /**
         * Bind all event handlers
         *
         * @return {void}
         */
        bindEvents: function() {
            // Manual sync button in meta box
            $(document).on('click', '.zbooks-sync-btn', this.handleManualSync.bind(this));

            // Apply payment button
            $(document).on('click', '.zbooks-apply-payment-btn', this.handleApplyPayment.bind(this));

            // Test connection button
            $(document).on('click', '.zbooks-test-connection-btn', this.handleTestConnection.bind(this));

            // Bulk sync button (for selected orders)
            $(document).on('click', '#zbooks-sync-selected', this.handleBulkSync.bind(this));

            // Bulk sync date range button
            $(document).on('click', '#zbooks-start-bulk-sync', this.handleBulkSyncDateRange.bind(this));

            // Cancel bulk sync
            $(document).on('click', '.zbooks-cancel-bulk-btn', this.handleCancelBulkSync.bind(this));

            // Select all checkbox
            $(document).on('change', '.zbooks-select-all', this.handleSelectAll.bind(this));

            // Individual checkboxes
            $(document).on('change', '.zbooks-item-checkbox', this.updateSelectedCount.bind(this));

            // Dismiss messages
            $(document).on('click', '.zbooks-message-dismiss', this.dismissMessage.bind(this));
        },

        /**
         * Initialize select all checkbox state
         *
         * @return {void}
         */
        initSelectAll: function() {
            this.updateSelectedCount();
        },

        /**
         * Handle manual sync button click
         *
         * @param {Event} e Click event
         * @return {void}
         */
        handleManualSync: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var orderId = $button.data('order-id');
            var asDraft = $button.data('draft') === true || $button.data('draft') === 'true';

            if (!orderId || $button.hasClass('zbooks-btn-loading')) {
                return;
            }

            this.syncOrder(orderId, asDraft, $button);
        },

        /**
         * Handle apply payment button click
         *
         * @param {Event} e Click event
         * @return {void}
         */
        handleApplyPayment: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var orderId = $button.data('order-id');

            if (!orderId || $button.hasClass('zbooks-btn-loading')) {
                return;
            }

            this.applyPayment(orderId, $button);
        },

        /**
         * Sync a single order
         *
         * @param {number}  orderId Order ID to sync
         * @param {boolean} asDraft Whether to sync as draft
         * @param {jQuery}  $button Button element
         * @return {void}
         */
        syncOrder: function(orderId, asDraft, $button) {
            var self = this;
            var $container = $button.closest('.zbooks-meta-box');
            var $result = $container.find('.zbooks-sync-result');

            // Set loading state
            $button.addClass('zbooks-btn-loading').prop('disabled', true);
            $result.html('<span class="spinner is-active" style="float:none; margin:0;"></span> ' + (self.config.i18n.syncing || 'Syncing...'));

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_manual_sync',
                    order_id: orderId,
                    as_draft: asDraft ? 'true' : 'false',
                    nonce: self.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span class="dashicons dashicons-yes" style="color:green;"></span> ' + (response.data.message || self.config.i18n.sync_success || 'Sync successful!'));
                        // Reload to show updated data
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.html('<span class="dashicons dashicons-warning" style="color:red;"></span> ' + (response.data.message || self.config.i18n.sync_error || 'Sync failed'));
                    }
                },
                error: function(xhr, status, error) {
                    $result.html('<span class="dashicons dashicons-warning" style="color:red;"></span> Network error: ' + error);
                },
                complete: function() {
                    $button.removeClass('zbooks-btn-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Apply payment to an order
         *
         * @param {number} orderId Order ID
         * @param {jQuery} $button Button element
         * @return {void}
         */
        applyPayment: function(orderId, $button) {
            var self = this;
            var $container = $button.closest('.zbooks-meta-box');
            var $result = $container.find('.zbooks-sync-result');

            // Set loading state
            $button.addClass('zbooks-btn-loading').prop('disabled', true);
            $result.html('<span class="spinner is-active" style="float:none; margin:0;"></span> Applying payment...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_apply_payment',
                    order_id: orderId,
                    nonce: self.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span class="dashicons dashicons-yes" style="color:green;"></span> ' + (response.data.message || 'Payment applied!'));
                        // Reload to show updated data
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.html('<span class="dashicons dashicons-warning" style="color:red;"></span> ' + (response.data.message || 'Failed to apply payment'));
                    }
                },
                error: function(xhr, status, error) {
                    $result.html('<span class="dashicons dashicons-warning" style="color:red;"></span> Network error: ' + error);
                },
                complete: function() {
                    $button.removeClass('zbooks-btn-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Sync a single post (legacy - for bulk sync compatibility)
         *
         * @param {number} postId  Post ID to sync
         * @param {jQuery} $button Button element
         * @return {void}
         */
        syncPost: function(postId, $button) {
            var self = this;
            var $container = $button.closest('.zbooks-meta-box, .zbooks-bulk-row');
            var $spinner = $container.find('.spinner');
            var $status = $container.find('.zbooks-status');
            var $message = $container.find('.zbooks-sync-message');

            // Set loading state
            $button.addClass('zbooks-btn-loading').prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_manual_sync',
                    order_id: postId,
                    as_draft: 'false',
                    nonce: self.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showSyncSuccess($container, response.data);
                        self.updateStatusBadge($status, 'synced');
                    } else {
                        self.showSyncError($container, response.data.message || 'Sync failed');
                        self.updateStatusBadge($status, 'failed');
                    }
                },
                error: function(xhr, status, error) {
                    self.showSyncError($container, 'Network error: ' + error);
                    self.updateStatusBadge($status, 'failed');
                },
                complete: function() {
                    $button.removeClass('zbooks-btn-loading').prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        },

        /**
         * Show sync success message
         *
         * @param {jQuery} $container Container element
         * @param {Object} data       Response data
         * @return {void}
         */
        showSyncSuccess: function($container, data) {
            var message = data.message || 'Successfully synced to Zbooks';
            this.showMessage($container, 'success', message);

            // Update last sync time if present
            var $lastSync = $container.find('.zbooks-last-sync');
            if ($lastSync.length && data.synced_at) {
                $lastSync.text('Last synced: ' + data.synced_at);
            }

            // Update sync ID if present
            var $syncId = $container.find('.zbooks-sync-id');
            if ($syncId.length && data.zbooks_id) {
                $syncId.text(data.zbooks_id);
            }
        },

        /**
         * Show sync error message
         *
         * @param {jQuery} $container Container element
         * @param {string} message    Error message
         * @return {void}
         */
        showSyncError: function($container, message) {
            this.showMessage($container, 'error', message);
        },

        /**
         * Update status badge
         *
         * @param {jQuery} $status Status element
         * @param {string} status  New status (pending, synced, failed, draft)
         * @return {void}
         */
        updateStatusBadge: function($status, status) {
            if (!$status.length) {
                return;
            }

            var statusLabels = {
                pending: 'Pending',
                synced: 'Synced',
                failed: 'Failed',
                draft: 'Draft'
            };

            $status
                .removeClass('zbooks-status-pending zbooks-status-synced zbooks-status-failed zbooks-status-draft')
                .addClass('zbooks-status-' + status)
                .text(statusLabels[status] || status);
        },

        /**
         * Handle test connection button click
         *
         * @param {Event} e Click event
         * @return {void}
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

            var self = this;

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_test_connection',
                    nonce: self.config.nonce
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
        },

        /**
         * Handle select all checkbox
         *
         * @param {Event} e Change event
         * @return {void}
         */
        handleSelectAll: function(e) {
            var isChecked = $(e.currentTarget).is(':checked');
            $('.zbooks-item-checkbox').prop('checked', isChecked);
            this.updateSelectedCount();
        },

        /**
         * Update selected items count
         *
         * @return {void}
         */
        updateSelectedCount: function() {
            var $checkboxes = $('.zbooks-item-checkbox');
            var $checked = $checkboxes.filter(':checked');
            var $selectAll = $('.zbooks-select-all');
            var $countDisplay = $('.zbooks-selected-count');
            var $bulkBtn = $('.zbooks-bulk-sync-btn');

            // Update select all checkbox state
            if ($checkboxes.length > 0) {
                $selectAll.prop('checked', $checked.length === $checkboxes.length);
                $selectAll.prop('indeterminate', $checked.length > 0 && $checked.length < $checkboxes.length);
            }

            // Update count display
            if ($countDisplay.length) {
                $countDisplay.text($checked.length + ' item(s) selected');
            }

            // Enable/disable bulk button
            if ($bulkBtn.length) {
                $bulkBtn.prop('disabled', $checked.length === 0);
            }
        },

        /**
         * Handle bulk sync button click (for selected orders)
         *
         * @param {Event} e Click event
         * @return {void}
         */
        handleBulkSync: function(e) {
            e.preventDefault();

            var $checked = $('.zbooks-item-checkbox:checked');

            if ($checked.length === 0 || this.bulkState.isProcessing) {
                return;
            }

            // Collect post IDs
            var postIds = [];
            $checked.each(function() {
                postIds.push($(this).val());
            });

            // Initialize bulk state
            this.bulkState = {
                isProcessing: true,
                queue: postIds,
                processed: 0,
                succeeded: 0,
                failed: 0,
                total: postIds.length
            };

            // Show progress UI
            this.showBulkProgress();

            // Start processing
            this.processNextBulkItem();
        },

        /**
         * Handle bulk sync date range form submission
         *
         * @param {Event} e Submit event
         * @return {void}
         */
        handleBulkSyncDateRange: function(e) {
            e.preventDefault();

            if (this.bulkState.isProcessing) {
                return;
            }

            var self = this;
            var $button = $(e.currentTarget);
            var $form = $('#zbooks-bulk-sync-form');
            var $progress = $('#zbooks-bulk-sync-progress');

            var dateFrom = $form.find('#zbooks_date_from').val();
            var dateTo = $form.find('#zbooks_date_to').val();
            var asDraft = $form.find('input[name="as_draft"]:checked').val() === 'true';

            if (!dateFrom || !dateTo) {
                alert('Please select a date range.');
                return;
            }

            // Disable button and show progress
            $button.prop('disabled', true).text('Fetching orders...');
            $progress.show();
            $progress.find('.zbooks-progress-text').text('Fetching orders in date range...');

            // First, get orders in the date range
            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_get_orders_by_date',
                    date_from: dateFrom,
                    date_to: dateTo,
                    nonce: self.config.nonce
                },
                success: function(response) {
                    if (response.success && response.data.orders.length > 0) {
                        var orderIds = response.data.orders.map(function(order) {
                            return order.id;
                        });

                        // Initialize bulk state
                        self.bulkState = {
                            isProcessing: true,
                            queue: orderIds,
                            processed: 0,
                            succeeded: 0,
                            failed: 0,
                            total: orderIds.length,
                            asDraft: asDraft
                        };

                        // Update progress UI
                        $progress.find('.zbooks-progress-text').text('Syncing 0 / ' + orderIds.length + ' orders...');

                        // Add cancel button if not present
                        if (!$progress.find('.zbooks-cancel-bulk-btn').length) {
                            $progress.append('<button type="button" class="button zbooks-cancel-bulk-btn">Cancel</button>');
                        }

                        // Start processing
                        self.processNextBulkItemDateRange($progress, $button);
                    } else if (response.success && response.data.orders.length === 0) {
                        $progress.find('.zbooks-progress-text').text('No orders found in the selected date range.');
                        $button.prop('disabled', false).text('Start Bulk Sync');
                    } else {
                        $progress.find('.zbooks-progress-text').text('Error: ' + (response.data.message || 'Failed to fetch orders'));
                        $button.prop('disabled', false).text('Start Bulk Sync');
                    }
                },
                error: function(xhr, status, error) {
                    $progress.find('.zbooks-progress-text').text('Network error: ' + error);
                    $button.prop('disabled', false).text('Start Bulk Sync');
                }
            });
        },

        /**
         * Process next item in date range bulk sync
         *
         * @param {jQuery} $progress Progress container
         * @param {jQuery} $button Submit button
         * @return {void}
         */
        processNextBulkItemDateRange: function($progress, $button) {
            var self = this;
            var state = this.bulkState;

            if (!state.isProcessing || state.queue.length === 0) {
                // Complete
                var statusText = 'Completed: ' + state.succeeded + ' succeeded, ' + state.failed + ' failed.';
                $progress.find('.zbooks-progress-text').text(statusText);
                $progress.find('.zbooks-cancel-bulk-btn').remove();
                $button.prop('disabled', false).text('Start Bulk Sync');
                state.isProcessing = false;

                // Reload page to update stats
                setTimeout(function() {
                    location.reload();
                }, 2000);
                return;
            }

            var orderId = state.queue.shift();

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_manual_sync',
                    order_id: orderId,
                    as_draft: state.asDraft ? 'true' : 'false',
                    nonce: self.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        state.succeeded++;
                    } else {
                        state.failed++;
                    }
                },
                error: function() {
                    state.failed++;
                },
                complete: function() {
                    state.processed++;
                    var percent = Math.round((state.processed / state.total) * 100);
                    $progress.find('.zbooks-progress-text').text(
                        'Syncing ' + state.processed + ' / ' + state.total + ' orders (' + percent + '%)...'
                    );
                    $progress.find('.zbooks-progress-fill').css('width', percent + '%');
                    self.processNextBulkItemDateRange($progress, $button);
                }
            });
        },

        /**
         * Show bulk progress UI
         *
         * @return {void}
         */
        showBulkProgress: function() {
            var $container = $('.zbooks-progress-container');

            if (!$container.length) {
                $container = $(
                    '<div class="zbooks-progress-container">' +
                        '<div class="zbooks-progress-header">' +
                            '<span class="zbooks-progress-title">Syncing posts...</span>' +
                            '<span class="zbooks-progress-count">0 / ' + this.bulkState.total + '</span>' +
                        '</div>' +
                        '<div class="zbooks-progress-bar">' +
                            '<div class="zbooks-progress-fill" style="width: 0%"></div>' +
                            '<span class="zbooks-progress-percentage">0%</span>' +
                        '</div>' +
                        '<div class="zbooks-progress-status is-processing">Processing...</div>' +
                        '<button type="button" class="button zbooks-cancel-bulk-btn">Cancel</button>' +
                    '</div>'
                );
                $('.zbooks-bulk-actions').after($container);
            }

            // Disable bulk actions
            $('.zbooks-bulk-sync-btn').prop('disabled', true);
            $('.zbooks-select-all, .zbooks-item-checkbox').prop('disabled', true);
        },

        /**
         * Update bulk progress UI
         *
         * @return {void}
         */
        updateBulkProgress: function() {
            var state = this.bulkState;
            var percentage = Math.round((state.processed / state.total) * 100);

            $('.zbooks-progress-fill').css('width', percentage + '%');
            $('.zbooks-progress-percentage').text(percentage + '%');
            $('.zbooks-progress-count').text(state.processed + ' / ' + state.total);
        },

        /**
         * Process next item in bulk queue
         *
         * @return {void}
         */
        processNextBulkItem: function() {
            var self = this;
            var state = this.bulkState;

            if (!state.isProcessing || state.queue.length === 0) {
                this.completeBulkSync();
                return;
            }

            var postId = state.queue.shift();
            var $row = $('.zbooks-item-checkbox[value="' + postId + '"]').closest('tr');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_manual_sync',
                    order_id: postId,
                    as_draft: 'false',
                    nonce: self.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        state.succeeded++;
                        self.updateRowStatus($row, 'synced');
                    } else {
                        state.failed++;
                        self.updateRowStatus($row, 'failed');
                    }
                },
                error: function() {
                    state.failed++;
                    self.updateRowStatus($row, 'failed');
                },
                complete: function() {
                    state.processed++;
                    self.updateBulkProgress();
                    self.processNextBulkItem();
                }
            });
        },

        /**
         * Update row status in bulk table
         *
         * @param {jQuery} $row   Table row
         * @param {string} status New status
         * @return {void}
         */
        updateRowStatus: function($row, status) {
            var $status = $row.find('.zbooks-status');
            this.updateStatusBadge($status, status);
        },

        /**
         * Complete bulk sync process
         *
         * @return {void}
         */
        completeBulkSync: function() {
            var state = this.bulkState;
            var $container = $('.zbooks-progress-container');

            // Update status
            var statusClass = state.failed > 0 ? 'is-error' : 'is-complete';
            var statusText = 'Completed: ' + state.succeeded + ' succeeded, ' + state.failed + ' failed';

            $container.find('.zbooks-progress-status')
                .removeClass('is-processing')
                .addClass(statusClass)
                .text(statusText);

            // Remove cancel button
            $container.find('.zbooks-cancel-bulk-btn').remove();

            // Re-enable controls
            $('.zbooks-bulk-sync-btn').prop('disabled', false);
            $('.zbooks-select-all, .zbooks-item-checkbox').prop('disabled', false);

            // Reset state
            this.bulkState.isProcessing = false;

            // Update stats if they exist
            this.updateStatsBoxes();
        },

        /**
         * Handle cancel bulk sync
         *
         * @param {Event} e Click event
         * @return {void}
         */
        handleCancelBulkSync: function(e) {
            e.preventDefault();

            this.bulkState.isProcessing = false;
            this.bulkState.queue = [];

            var $container = $('.zbooks-progress-container');
            $container.find('.zbooks-progress-status')
                .removeClass('is-processing')
                .addClass('is-error')
                .text('Cancelled by user');

            $container.find('.zbooks-cancel-bulk-btn').remove();

            // Re-enable controls
            $('.zbooks-bulk-sync-btn').prop('disabled', false);
            $('.zbooks-select-all, .zbooks-item-checkbox').prop('disabled', false);
        },

        /**
         * Update stats boxes after bulk sync
         *
         * @return {void}
         */
        updateStatsBoxes: function() {
            var self = this;

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_get_stats',
                    nonce: self.config.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        var data = response.data;
                        $('.zbooks-stat-box--total .zbooks-stat-number').text(data.total || 0);
                        $('.zbooks-stat-box--synced .zbooks-stat-number').text(data.synced || 0);
                        $('.zbooks-stat-box--pending .zbooks-stat-number').text(data.pending || 0);
                        $('.zbooks-stat-box--failed .zbooks-stat-number').text(data.failed || 0);
                    }
                }
            });
        },

        /**
         * Show a message in a container
         *
         * @param {jQuery} $container Container element
         * @param {string} type       Message type (success, error, warning, info)
         * @param {string} text       Message text
         * @return {void}
         */
        showMessage: function($container, type, text) {
            var $existingMessage = $container.find('.zbooks-message');
            $existingMessage.remove();

            var $message = $(
                '<div class="zbooks-message zbooks-message--' + type + '">' +
                    '<div class="zbooks-message-content">' +
                        '<div class="zbooks-message-text">' + this.escapeHtml(text) + '</div>' +
                    '</div>' +
                    '<button type="button" class="zbooks-message-dismiss">' +
                        '<span class="dashicons dashicons-dismiss"></span>' +
                    '</button>' +
                '</div>'
            );

            $container.find('.zbooks-meta-box-actions, .zbooks-bulk-actions').before($message);

            // Auto-dismiss after 5 seconds for success messages
            if (type === 'success') {
                setTimeout(function() {
                    $message.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
        },

        /**
         * Dismiss a message
         *
         * @param {Event} e Click event
         * @return {void}
         */
        dismissMessage: function(e) {
            e.preventDefault();
            $(e.currentTarget).closest('.zbooks-message').fadeOut(300, function() {
                $(this).remove();
            });
        },

        /**
         * Escape HTML entities
         *
         * @param {string} text Text to escape
         * @return {string} Escaped text
         */
        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    /**
     * Initialize on DOM ready
     */
    $(document).ready(function() {
        ZbooksAdmin.init();
    });

    // Expose to global scope for external access if needed
    window.ZbooksAdmin = ZbooksAdmin;

})(jQuery);
