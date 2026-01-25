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

            // Initialize feature-specific modules
            this.ProductMetaBox.init();
            this.LogViewer.init();
            this.ProductMapping.init();
            this.PaymentMapping.init();
            this.FieldMapping.init();
            this.SettingsPage.init();
            this.Reconciliation.init();
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
        },

        /**
         * Product Meta Box Module
         * Handles product-level Zoho item creation, linking, syncing, and unlinking
         */
        ProductMetaBox: {
            nonce: '',

            init: function() {
                // Check if we're on a product edit page with the meta box
                if (!$('.zbooks-product-meta-box').length) {
                    return;
                }

                // Get nonce from localized data
                this.nonce = typeof zbooks_product !== 'undefined' ? zbooks_product.nonce : '';

                this.bindEvents();
            },

            bindEvents: function() {
                var self = this;

                // Create item button
                $(document).on('click', '.zbooks-create-item-btn', function() {
                    var $btn = $(this);
                    var productId = $btn.data('product-id');
                    var $result = $('.zbooks-product-result');
                    var trackInventory = $('.zbooks-track-inventory').is(':checked');

                    self.createZohoItem(productId, trackInventory, $btn, $result);
                });

                // Link existing button
                $(document).on('click', '.zbooks-link-existing-btn', function() {
                    var $btn = $(this);
                    var productId = $btn.data('product-id');
                    var $result = $('.zbooks-product-result');
                    var $createBtn = $('.zbooks-create-item-btn');

                    self.searchAndShowItems(productId, '', $createBtn, $result);
                });

                // Sync product button
                $(document).on('click', '.zbooks-sync-product-btn', function() {
                    var $btn = $(this);
                    var productId = $btn.data('product-id');
                    var $result = $('.zbooks-product-result');

                    self.syncProduct(productId, $btn, $result);
                });

                // Unlink button
                $(document).on('click', '.zbooks-unlink-btn', function() {
                    var $btn = $(this);
                    var productId = $btn.data('product-id');
                    var $result = $('.zbooks-product-result');

                    self.unlinkProduct(productId, $btn, $result);
                });
            },

            createZohoItem: function(productId, trackInventory, $btn, $result) {
                var self = this;
                var i18n = ZbooksAdmin.config.i18n;

                $btn.prop('disabled', true).text(i18n.creating || 'Creating...');
                $result.html('');

                $.post(ajaxurl, {
                    action: 'zbooks_create_zoho_item',
                    nonce: self.nonce,
                    product_id: productId,
                    track_inventory: trackInventory ? '1' : '0'
                }, function(response) {
                    if (response.success) {
                        $result.html('<span style="color:green;">' + response.data.message + '</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $btn.prop('disabled', false).text(i18n.create_in_zoho || 'Create in Zoho');

                        // Check if this is a duplicate/already exists error
                        if (response.data && response.data.can_link_existing) {
                            self.showDuplicateErrorDialog(response.data.message, productId, response.data.search_term || '', $btn, $result);
                        }
                        // Check if this is an inventory-related error with retry option
                        else if (response.data && response.data.can_retry_without_tracking) {
                            self.showInventoryErrorDialog(response.data.message, productId, $btn, $result);
                        } else {
                            $result.html('<span style="color:red;">' + (response.data.message || 'Error creating item') + '</span>');
                        }
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text(i18n.create_in_zoho || 'Create in Zoho');
                    $result.html('<span style="color:red;">Network error</span>');
                });
            },

            showInventoryErrorDialog: function(errorMessage, productId, $btn, $result) {
                var self = this;
                var i18n = ZbooksAdmin.config.i18n;

                var dialogHtml = '<div class="zbooks-inventory-error-dialog" style="background:#fff3cd;border:1px solid #ffc107;padding:12px;border-radius:4px;margin-top:10px;">' +
                    '<p style="margin:0 0 10px;color:#856404;"><strong>' + (i18n.inventory_tracking_error || 'Inventory Tracking Error') + '</strong></p>' +
                    '<p style="margin:0 0 10px;color:#856404;font-size:12px;">' + errorMessage + '</p>' +
                    '<p style="margin:0 0 10px;color:#856404;font-size:12px;">' + (i18n.inventory_feature_note || 'This feature requires Zoho Inventory integration with your Zoho Books subscription.') + '</p>' +
                    '<p style="margin:0;">' +
                    '<button type="button" class="button zbooks-retry-without-tracking" style="margin-right:5px;">' + (i18n.create_without_inventory || 'Create without inventory tracking') + '</button>' +
                    '<button type="button" class="button zbooks-cancel-create">' + (i18n.cancel || 'Cancel') + '</button>' +
                    '</p></div>';

                $result.html(dialogHtml);

                // Handle retry without tracking
                $result.find('.zbooks-retry-without-tracking').on('click', function() {
                    // Uncheck the inventory tracking checkbox
                    $('.zbooks-track-inventory').prop('checked', false);
                    self.createZohoItem(productId, false, $btn, $result);
                });

                // Handle cancel
                $result.find('.zbooks-cancel-create').on('click', function() {
                    $result.html('');
                });
            },

            showDuplicateErrorDialog: function(errorMessage, productId, searchTerm, $btn, $result) {
                var self = this;
                var i18n = ZbooksAdmin.config.i18n;

                var dialogHtml = '<div class="zbooks-duplicate-error-dialog" style="background:#f8d7da;border:1px solid #f5c6cb;padding:12px;border-radius:4px;margin-top:10px;">' +
                    '<p style="margin:0 0 10px;color:#721c24;"><strong>' + (i18n.item_already_exists || 'Item Already Exists') + '</strong></p>' +
                    '<p style="margin:0 0 10px;color:#721c24;font-size:12px;">' + errorMessage + '</p>' +
                    '<p style="margin:0 0 10px;color:#721c24;font-size:12px;">' + (i18n.search_and_link_prompt || 'Would you like to search for the existing item and link it to this product?') + '</p>' +
                    '<p style="margin:0;">' +
                    '<button type="button" class="button button-primary zbooks-search-existing" style="margin-right:5px;">' + (i18n.search_link_existing || 'Search & Link Existing') + '</button>' +
                    '<button type="button" class="button zbooks-cancel-create">' + (i18n.cancel || 'Cancel') + '</button>' +
                    '</p></div>';

                $result.html(dialogHtml);

                // Handle search existing
                $result.find('.zbooks-search-existing').on('click', function() {
                    self.searchAndShowItems(productId, searchTerm, $btn, $result);
                });

                // Handle cancel
                $result.find('.zbooks-cancel-create').on('click', function() {
                    $result.html('');
                });
            },

            searchAndShowItems: function(productId, searchTerm, $btn, $result) {
                var self = this;
                var i18n = ZbooksAdmin.config.i18n;

                $result.html('<p style="color:#666;"><span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>' + (i18n.searching || 'Searching...') + '</p>');

                $.post(ajaxurl, {
                    action: 'zbooks_search_and_link_item',
                    nonce: self.nonce,
                    product_id: productId,
                    search_term: searchTerm
                }, function(response) {
                    if (response.success && response.data.items && response.data.items.length > 0) {
                        self.showItemSelectionDialog(response.data.items, productId, $btn, $result);
                    } else {
                        $result.html('<span style="color:red;">' + (response.data.message || (i18n.no_items_found || 'No items found.')) + '</span>');
                    }
                }).fail(function() {
                    $result.html('<span style="color:red;">' + (i18n.search_failed || 'Search failed. Please try again.') + '</span>');
                });
            },

            showItemSelectionDialog: function(items, productId, $btn, $result) {
                var self = this;
                var i18n = ZbooksAdmin.config.i18n;

                var dialogHtml = '<div class="zbooks-item-selection" style="background:#e7f3ff;border:1px solid #b3d7ff;padding:12px;border-radius:4px;margin-top:10px;">' +
                    '<p style="margin:0 0 10px;color:#004085;"><strong>' + (i18n.select_item_to_link || 'Select Item to Link') + '</strong></p>' +
                    '<div style="max-height:200px;overflow-y:auto;margin-bottom:10px;">';

                items.forEach(function(item, index) {
                    var itemInfo = item.name;
                    if (item.sku) {
                        itemInfo += ' (SKU: ' + item.sku + ')';
                    }
                    if (item.rate) {
                        itemInfo += ' - ' + item.rate;
                    }

                    dialogHtml += '<label style="display:block;padding:8px;margin:4px 0;background:#fff;border:1px solid #ddd;border-radius:3px;cursor:pointer;">' +
                        '<input type="radio" name="zbooks_select_item" value="' + item.item_id + '" ' + (index === 0 ? 'checked' : '') + ' style="margin-right:8px;">' +
                        '<span>' + itemInfo + '</span>' +
                        '</label>';
                });

                dialogHtml += '</div>' +
                    '<p style="margin:0;">' +
                    '<button type="button" class="button button-primary zbooks-link-selected" style="margin-right:5px;">' + (i18n.link_selected || 'Link Selected') + '</button>' +
                    '<button type="button" class="button zbooks-cancel-create">' + (i18n.cancel || 'Cancel') + '</button>' +
                    '</p></div>';

                $result.html(dialogHtml);

                // Handle link selected
                $result.find('.zbooks-link-selected').on('click', function() {
                    var selectedItemId = $result.find('input[name="zbooks_select_item"]:checked').val();
                    if (selectedItemId) {
                        self.linkItemToProduct(productId, selectedItemId, $result);
                    }
                });

                // Handle cancel
                $result.find('.zbooks-cancel-create').on('click', function() {
                    $result.html('');
                });
            },

            linkItemToProduct: function(productId, itemId, $result) {
                var i18n = ZbooksAdmin.config.i18n;
                var mappingNonce = typeof zbooks_mapping !== 'undefined' ? zbooks_mapping.nonce : this.nonce;

                $result.html('<p style="color:#666;"><span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>' + (i18n.linking || 'Linking...') + '</p>');

                $.post(ajaxurl, {
                    action: 'zbooks_save_mapping',
                    nonce: mappingNonce,
                    product_id: productId,
                    zoho_item_id: itemId
                }, function(response) {
                    if (response.success) {
                        $result.html('<span style="color:green;">' + (i18n.item_linked_success || 'Item linked successfully!') + '</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.html('<span style="color:red;">' + (response.data.message || (i18n.failed_to_link || 'Failed to link item.')) + '</span>');
                    }
                }).fail(function() {
                    $result.html('<span style="color:red;">' + (i18n.network_error_linking || 'Network error while linking.') + '</span>');
                });
            },

            syncProduct: function(productId, $btn, $result) {
                var self = this;
                var i18n = ZbooksAdmin.config.i18n;

                $btn.prop('disabled', true).text(i18n.updating || 'Updating...');
                $result.html('');

                $.post(ajaxurl, {
                    action: 'zbooks_sync_product_to_zoho',
                    nonce: self.nonce,
                    product_id: productId
                }, function(response) {
                    $btn.prop('disabled', false).text(i18n.update_in_zoho || 'Update in Zoho');
                    if (response.success) {
                        $result.html('<span style="color:green;">' + response.data.message + '</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.html('<span style="color:red;">' + (response.data.message || 'Error updating item') + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text(i18n.update_in_zoho || 'Update in Zoho');
                    $result.html('<span style="color:red;">Network error</span>');
                });
            },

            unlinkProduct: function(productId, $btn, $result) {
                var i18n = ZbooksAdmin.config.i18n;

                if (!confirm(i18n.confirm_unlink || 'Remove the link to this Zoho item? This will not delete the item in Zoho.')) {
                    return;
                }

                var mappingNonce = typeof zbooks_mapping !== 'undefined' ? zbooks_mapping.nonce : this.nonce;

                $btn.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'zbooks_remove_mapping',
                    nonce: mappingNonce,
                    product_id: productId
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        $btn.prop('disabled', false);
                        $result.html('<span style="color:red;">' + (response.data.message || 'Error unlinking') + '</span>');
                    }
                });
            }
        },

        /**
         * Log Viewer Module
         * Handles log viewing modal, JSON copy, refresh, and clear operations
         */
        LogViewer: {
            $modal: null,
            currentEntry: null,

            init: function() {
                // Check if we're on the log viewer page
                this.$modal = $('#zbooks-log-modal');
                if (!this.$modal.length) {
                    return;
                }

                this.bindEvents();
            },

            bindEvents: function() {
                var self = this;

                // Open modal on button click
                $(document).on('click', '.zbooks-view-details', function(e) {
                    e.stopPropagation();
                    var $row = $(this).closest('tr');
                    self.showLogDetails($row.data('entry'));
                });

                // Open modal on row double-click
                $(document).on('dblclick', '.zbooks-log-row', function() {
                    self.showLogDetails($(this).data('entry'));
                });

                // Close modal
                $(document).on('click', '.zbooks-modal-close, .zbooks-modal-overlay', function() {
                    self.$modal.fadeOut(200);
                });

                // Close on escape key
                $(document).on('keydown', function(e) {
                    if (e.key === 'Escape' && self.$modal.is(':visible')) {
                        self.$modal.fadeOut(200);
                    }
                });

                // Copy JSON to clipboard
                $(document).on('click', '.zbooks-copy-json', function() {
                    self.copyJsonToClipboard();
                });
            },

            showLogDetails: function(entry) {
                if (!entry) return;
                this.currentEntry = entry;

                $('#zbooks-modal-timestamp').text(entry.timestamp);
                $('#zbooks-modal-level').html(
                    '<span class="zbooks-log-level zbooks-level-' + entry.level.toLowerCase() + '">' +
                    entry.level + '</span>'
                );
                $('#zbooks-modal-message').text(entry.message);

                if (entry.context && Object.keys(entry.context).length > 0) {
                    $('#zbooks-modal-context').text(JSON.stringify(entry.context, null, 2));
                    $('#zbooks-modal-context-row').show();
                } else {
                    $('#zbooks-modal-context-row').hide();
                }

                this.$modal.fadeIn(200);
            },

            copyJsonToClipboard: function() {
                if (!this.currentEntry) return;
                var i18n = ZbooksAdmin.config.i18n;

                var jsonText = JSON.stringify(this.currentEntry, null, 2);
                navigator.clipboard.writeText(jsonText).then(function() {
                    var $btn = $('.zbooks-copy-json');
                    var originalText = $btn.text();
                    $btn.text(i18n.copied || 'Copied!');
                    setTimeout(function() {
                        $btn.text(originalText);
                    }, 1500);
                });
            }
        },

        /**
         * Product Mapping Module
         * Handles bulk product creation and linking on the Products tab
         */
        ProductMapping: {
            nonce: '',

            init: function() {
                // Check if we're on the product mapping page
                if (!$('#zbooks-select-all-products').length && !$('.zbooks-product-checkbox').length) {
                    return;
                }

                this.nonce = typeof zbooks_mapping !== 'undefined' ? zbooks_mapping.nonce : '';
                this.bindEvents();
            },

            bindEvents: function() {
                var self = this;

                // Update selected count
                function updateSelectedCount() {
                    var count = $('.zbooks-product-checkbox:checked').length;
                    var $countSpan = $('#zbooks-selected-count');
                    var $bulkBtn = $('#zbooks-bulk-create');
                    var i18n = ZbooksAdmin.config.i18n;

                    if (count > 0) {
                        $countSpan.text(count + ' ' + (i18n.selected || 'selected'));
                        $bulkBtn.prop('disabled', false);
                    } else {
                        $countSpan.text('');
                        $bulkBtn.prop('disabled', true);
                    }
                }

                // Select all checkbox
                $('#zbooks-select-all-products').on('change', function() {
                    $('.zbooks-product-checkbox').prop('checked', $(this).is(':checked'));
                    updateSelectedCount();
                });

                // Individual checkbox
                $('.zbooks-product-checkbox').on('change', updateSelectedCount);

                // Single create button
                $(document).on('click', '.zbooks-create-single', function() {
                    var $btn = $(this);
                    var productId = $btn.data('product-id');
                    var $status = $('#zbooks-action-status');
                    var i18n = ZbooksAdmin.config.i18n;

                    $btn.prop('disabled', true).text(i18n.creating || 'Creating...');

                    $.post(ajaxurl, {
                        action: 'zbooks_bulk_create_items',
                        nonce: self.nonce,
                        product_ids: [productId]
                    }, function(response) {
                        if (response.success) {
                            $status.text(i18n.created || 'Created!');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            $btn.prop('disabled', false).text(i18n.create || 'Create');
                            $status.text(response.data.message || 'Error creating item');
                        }
                    });
                });

                // Bulk create button
                $('#zbooks-bulk-create').on('click', function() {
                    var $btn = $(this);
                    var $status = $('#zbooks-action-status');
                    var productIds = [];
                    var i18n = ZbooksAdmin.config.i18n;

                    $('.zbooks-product-checkbox:checked').each(function() {
                        productIds.push($(this).val());
                    });

                    if (productIds.length === 0) {
                        return;
                    }

                    if (!confirm((i18n.create || 'Create') + ' ' + productIds.length + ' ' + (i18n.items_in_zoho_books || 'items in Zoho Books?'))) {
                        return;
                    }

                    $btn.prop('disabled', true).text(i18n.creating || 'Creating...');
                    $status.text(i18n.creating_items_in_zoho || 'Creating items in Zoho...');

                    $.post(ajaxurl, {
                        action: 'zbooks_bulk_create_items',
                        nonce: self.nonce,
                        product_ids: productIds
                    }, function(response) {
                        $btn.prop('disabled', false).text(i18n.create_selected_in_zoho || 'Create Selected in Zoho');
                        if (response.success) {
                            $status.text(response.data.message);
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            $status.text(response.data.message || 'Error creating items');
                        }
                    });
                });

                // Save mapping (link to existing)
                $(document).on('click', '.zbooks-save-mapping', function() {
                    var $btn = $(this);
                    var productId = $btn.data('product-id');
                    var zohoItemId = $('select[data-product-id="' + productId + '"]').val();
                    var i18n = ZbooksAdmin.config.i18n;

                    if (!zohoItemId) {
                        alert(i18n.select_zoho_item || 'Please select a Zoho item to link.');
                        return;
                    }

                    $btn.prop('disabled', true).text(i18n.linking || 'Linking...');

                    $.post(ajaxurl, {
                        action: 'zbooks_save_mapping',
                        nonce: self.nonce,
                        product_id: productId,
                        zoho_item_id: zohoItemId
                    }, function(response) {
                        $btn.prop('disabled', false).text(i18n.link || 'Link');
                        if (response.success) {
                            $btn.text(i18n.linked || 'Linked!');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            alert(response.data.message || 'Error saving mapping');
                        }
                    });
                });

                // Remove mapping (unlink)
                $(document).on('click', '.zbooks-remove-mapping', function() {
                    var $btn = $(this);
                    var productId = $btn.data('product-id');
                    var i18n = ZbooksAdmin.config.i18n;

                    if (!confirm(i18n.confirm_unlink_product || 'Unlink this product from Zoho?')) {
                        return;
                    }

                    $btn.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'zbooks_remove_mapping',
                        nonce: self.nonce,
                        product_id: productId
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            $btn.prop('disabled', false);
                            alert(response.data.message || 'Error removing mapping');
                        }
                    });
                });
            }
        },

        /**
         * Payment Mapping Module
         * Handles payment gateway to Zoho account mapping
         */
        PaymentMapping: {
            nonce: '',

            init: function() {
                // Check if we're on the payment mapping page
                if (!$('.zbooks-account-select').length) {
                    return;
                }

                this.nonce = typeof zbooks_refresh_accounts !== 'undefined' ? zbooks_refresh_accounts.nonce : '';
                this.bindEvents();
                this.initAccountNames();
            },

            bindEvents: function() {
                var self = this;
                var i18n = ZbooksAdmin.config.i18n;

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

            initAccountNames: function() {
                // Initialize account names on page load (for existing selections)
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
        },

        /**
         * Field Mapping Module
         * Handles custom field mapping for customers, invoices, and credit notes
         */
        FieldMapping: {
            customerIndex: 0,
            invoiceIndex: 0,
            creditnoteIndex: 0,

            init: function() {
                // Check if we're on the field mapping page
                if (!$('#zbooks-customer-mappings').length) {
                    return;
                }

                // Initialize indices from existing mapping counts
                this.customerIndex = $('#zbooks-customer-mappings .zbooks-mapping-row').length;
                this.invoiceIndex = $('#zbooks-invoice-mappings .zbooks-mapping-row').length;
                this.creditnoteIndex = $('#zbooks-creditnote-mappings .zbooks-mapping-row').length;

                this.bindEvents();
            },

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

            saveMappings: function() {
                var $btn = $('#zbooks-save-field-mappings');
                var $spinner = $('#zbooks-mapping-spinner');
                var i18n = ZbooksAdmin.config.i18n;

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
                    url: ZbooksAdmin.config.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'zbooks_save_field_mappings',
                        nonce: ZbooksAdmin.config.nonce,
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

            refreshZohoFields: function() {
                var $btn = $('#zbooks-refresh-zoho-fields');
                var $spinner = $('#zbooks-mapping-spinner');
                var i18n = ZbooksAdmin.config.i18n;

                $btn.prop('disabled', true);
                $spinner.addClass('is-active');

                $.ajax({
                    url: ZbooksAdmin.config.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'zbooks_fetch_zoho_custom_fields',
                        nonce: ZbooksAdmin.config.nonce
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
        },

        /**
         * Settings Page Module
         * Handles invoice numbering warning toggle
         */
        SettingsPage: {
            init: function() {
                // Check if we're on the settings page with the reference number checkbox
                if (!$('#zbooks_use_reference_number').length) {
                    return;
                }

                this.bindEvents();
            },

            bindEvents: function() {
                var i18n = ZbooksAdmin.config.i18n;

                $('#zbooks_use_reference_number').on('change', function() {
                    var $warning = $('#zbooks_invoice_number_warning');
                    if ($(this).is(':checked')) {
                        $warning.slideUp(200);
                    } else {
                        $warning.slideDown(200);
                        if (!confirm(i18n.invoice_number_warning || 'Warning: Using order numbers as invoice numbers may create gaps in your invoice sequence, which can cause issues during tax audits. Are you sure you want to disable this option?')) {
                            $(this).prop('checked', true);
                            $warning.hide();
                        }
                    }
                });
            }
        },

        /**
         * Reconciliation Module
         * Handles reconciliation report running, viewing, and management
         */
        Reconciliation: {
            nonce: '',

            init: function() {
                // Check if we're on the reconciliation page
                if (!$('#zbooks-run-reconciliation').length && !$('#zbooks-recon-frequency').length) {
                    return;
                }

                this.nonce = typeof zbooks_reconciliation !== 'undefined' ? zbooks_reconciliation.nonce : '';
                this.bindEvents();
            },

            bindEvents: function() {
                var self = this;
                var i18n = ZbooksAdmin.config.i18n;

                // Toggle frequency options
                $('#zbooks-recon-frequency').on('change', function() {
                    var frequency = $(this).val();
                    $('.zbooks-weekly-option, .zbooks-monthly-option').hide();
                    if (frequency === 'weekly') {
                        $('.zbooks-weekly-option').show();
                    } else if (frequency === 'monthly') {
                        $('.zbooks-monthly-option').show();
                    }
                });

                // Run reconciliation
                $('#zbooks-run-reconciliation').on('click', function() {
                    var $btn = $(this);
                    var $progress = $('#zbooks-reconciliation-progress');
                    var startDate = $('#zbooks-recon-start').val();
                    var endDate = $('#zbooks-recon-end').val();

                    if (!startDate || !endDate) {
                        alert(i18n.select_both_dates || 'Please select both start and end dates.');
                        return;
                    }

                    if (startDate > endDate) {
                        alert(i18n.start_before_end || 'Start date must be before end date.');
                        return;
                    }

                    $btn.prop('disabled', true);
                    $progress.show();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'zbooks_run_reconciliation',
                            nonce: self.nonce,
                            start_date: startDate,
                            end_date: endDate
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert(response.data.message || (i18n.reconciliation_failed || 'Reconciliation failed.'));
                                $btn.prop('disabled', false);
                                $progress.hide();
                            }
                        },
                        error: function() {
                            alert(i18n.network_error_try_again || 'Network error. Please try again.');
                            $btn.prop('disabled', false);
                            $progress.hide();
                        }
                    });
                });

                // Delete report
                $(document).on('click', '.zbooks-delete-report', function() {
                    if (!confirm(i18n.confirm_delete_report || 'Are you sure you want to delete this report?')) {
                        return;
                    }

                    var $btn = $(this);
                    var reportId = $btn.data('report-id');

                    $btn.prop('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'zbooks_delete_report',
                            nonce: self.nonce,
                            report_id: reportId
                        },
                        success: function(response) {
                            if (response.success) {
                                $btn.closest('tr').fadeOut(function() {
                                    $(this).remove();
                                });
                            } else {
                                alert(response.data.message || (i18n.failed_to_delete_report || 'Failed to delete report.'));
                                $btn.prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert(i18n.network_error_try_again || 'Network error. Please try again.');
                            $btn.prop('disabled', false);
                        }
                    });
                });

                // Export CSV
                $(document).on('click', '.zbooks-export-csv', function() {
                    var reportId = $(this).data('report-id');
                    var url = ajaxurl + '?action=zbooks_export_report_csv&nonce=' + self.nonce + '&report_id=' + reportId;
                    window.location.href = url;
                });

                // View report
                $(document).on('click', '.zbooks-view-report', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var reportId = $btn.data('report-id');

                    $btn.prop('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'zbooks_view_report',
                            nonce: self.nonce,
                            report_id: reportId
                        },
                        success: function(response) {
                            $btn.prop('disabled', false);
                            if (response.success) {
                                self.showReportModal(response.data);
                            } else {
                                alert(response.data.message || (i18n.failed_to_load_report || 'Failed to load report.'));
                            }
                        },
                        error: function() {
                            alert(i18n.network_error_try_again || 'Network error. Please try again.');
                            $btn.prop('disabled', false);
                        }
                    });
                });
            },

            showReportModal: function(data) {
                var i18n = ZbooksAdmin.config.i18n;
                var summary = data.summary || {};
                var paymentIssues = (summary.payment_mismatches || 0) + (summary.refund_mismatches || 0);
                var statusIssues = summary.status_mismatches || 0;

                var modalHtml = '<div id="zbooks-report-modal" class="zbooks-modal">' +
                    '<div class="zbooks-modal-content">' +
                    '<span class="zbooks-modal-close">&times;</span>' +
                    '<h2>' + (i18n.reconciliation_report || 'Reconciliation Report') + '</h2>' +
                    '<p><strong>' + (i18n.period || 'Period:') + '</strong> ' + data.period_start + ' - ' + data.period_end + '</p>' +
                    '<p><strong>' + (i18n.generated || 'Generated:') + '</strong> ' + data.generated_at + '</p>' +
                    '<p><strong>' + (i18n.status || 'Status:') + '</strong> <span class="zbooks-status zbooks-status-' + data.status + '">' + data.status.charAt(0).toUpperCase() + data.status.slice(1) + '</span></p>' +
                    (data.error ? '<p class="error"><strong>' + (i18n.error || 'Error:') + '</strong> ' + data.error + '</p>' : '') +
                    '<div class="zbooks-modal-summary">' +
                    '<h3>' + (i18n.summary || 'Summary') + '</h3>' +
                    '<div class="summary-grid">' +
                    '<div class="summary-item neutral"><span class="value">' + (summary.total_wc_orders || 0) + '</span><span class="label">' + (i18n.wc_orders || 'WC Orders') + '</span><span class="desc">' + (i18n.orders_in_period || 'Orders in period') + '</span></div>' +
                    '<div class="summary-item neutral"><span class="value">' + (summary.total_zoho_invoices || 0) + '</span><span class="label">' + (i18n.zoho_invoices || 'Zoho Invoices') + '</span><span class="desc">' + (i18n.invoices_in_period || 'Invoices in period') + '</span></div>' +
                    '<div class="summary-item success"><span class="value">' + (summary.matched_count || 0) + '</span><span class="label">' + (i18n.matched || 'Matched') + '</span><span class="desc">' + (i18n.orders_synced_correctly || 'Orders synced correctly') + '</span></div>' +
                    '<div class="summary-item ' + ((summary.missing_in_zoho || 0) > 0 ? 'danger' : 'neutral') + '"><span class="value">' + (summary.missing_in_zoho || 0) + '</span><span class="label">' + (i18n.missing_in_zoho || 'Missing in Zoho') + '</span><span class="desc">' + (i18n.orders_without_invoices || 'Orders without invoices') + '</span></div>' +
                    '<div class="summary-item ' + ((summary.amount_mismatches || 0) > 0 ? 'warning' : 'neutral') + '"><span class="value">' + (summary.amount_mismatches || 0) + '</span><span class="label">' + (i18n.amount_mismatches || 'Amount Mismatches') + '</span><span class="desc">' + (i18n.totals_dont_match || "Totals don't match") + '</span></div>' +
                    '<div class="summary-item ' + (paymentIssues > 0 ? 'warning' : 'neutral') + '"><span class="value">' + paymentIssues + '</span><span class="label">' + (i18n.payment_issues || 'Payment Issues') + '</span><span class="desc">' + (i18n.payment_or_refund_mismatch || 'Payment or refund mismatch') + '</span></div>' +
                    '<div class="summary-item ' + (statusIssues > 0 ? 'info' : 'neutral') + '"><span class="value">' + statusIssues + '</span><span class="label">' + (i18n.status_mismatches || 'Status Mismatches') + '</span><span class="desc">' + (i18n.invoice_status_differs || 'Invoice status differs') + '</span></div>' +
                    '<div class="summary-item neutral"><span class="value">' + (summary.missing_in_wc || 0) + '</span><span class="label">' + (i18n.missing_in_wc || 'Missing in WC') + '</span><span class="desc">' + (i18n.invoices_without_orders || 'Invoices without orders') + '</span></div>' +
                    '</div></div>' +
                    '<div class="zbooks-modal-discrepancies">' +
                    '<h3>' + (i18n.discrepancies || 'Discrepancies') + ' (' + data.discrepancy_count + ')</h3>' +
                    data.discrepancies_html +
                    '</div>' +
                    '</div></div>';

                // Remove existing modal
                $('#zbooks-report-modal').remove();

                // Add modal to body
                $('body').append(modalHtml);

                // Show modal
                $('#zbooks-report-modal').fadeIn();

                // Close on X click
                $('.zbooks-modal-close').on('click', function() {
                    $('#zbooks-report-modal').fadeOut(function() {
                        $(this).remove();
                    });
                });

                // Close on outside click
                $('#zbooks-report-modal').on('click', function(e) {
                    if ($(e.target).is('.zbooks-modal')) {
                        $(this).fadeOut(function() {
                            $(this).remove();
                        });
                    }
                });
            }
        }
    };

    /**
     * Global functions for log viewer buttons (called from HTML onclick)
     */
    window.zbooksRefreshLogs = function() {
        location.reload();
    };

    window.zbooksClearOldLogs = function() {
        var i18n = ZbooksAdmin.config.i18n;
        var confirmMsg = i18n.confirm_clear_old_logs || 'Delete old log files?';

        if (!confirm(confirmMsg)) {
            return;
        }

        var nonce = typeof zbooks_logs !== 'undefined' ? zbooks_logs.clear_logs_nonce : '';

        jQuery.post(ajaxurl, {
            action: 'zbooks_clear_logs',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message || 'Error clearing logs');
            }
        });
    };

    window.zbooksClearAllLogs = function() {
        var i18n = ZbooksAdmin.config.i18n;

        if (!confirm(i18n.confirm_clear_all_logs || 'Delete ALL log files? This cannot be undone.')) {
            return;
        }

        var nonce = typeof zbooks_logs !== 'undefined' ? zbooks_logs.clear_all_logs_nonce : '';

        jQuery.post(ajaxurl, {
            action: 'zbooks_clear_all_logs',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message || 'Error clearing logs');
            }
        });
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
