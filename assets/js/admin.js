/**
 * Zbooks Admin JavaScript - Main Entry Point
 *
 * Provides shared utilities, legacy functionality, and lazy-loading module registry for ZBooks.
 * Modules are loaded on-demand based on the current page/tab.
 *
 * This file contains:
 * - Core utilities (AJAX error handling, status badges, messages)
 * - Lazy-loading module system
 * - Legacy functionality for pages without dedicated modules:
 *   - Bulk sync (date range and selected orders)
 *   - Order/Product meta boxes (manual sync, apply payment)
 *   - Test connection
 *   - Select all/bulk actions
 *
 * @package    Zbooks
 * @author     talas9
 * @link       https://github.com/talas9/zbooks-for-woocommerce
 * @since      1.0.0
 */

(function($) {
    'use strict';

    /**
     * Main ZBooks namespace with shared utilities and lazy-loading
     */
    window.ZBooks = {
        /**
         * Configuration and state
         */
        config: {
            ajaxUrl: typeof zbooks !== 'undefined' ? zbooks.ajax_url : ajaxurl,
            nonce: typeof zbooks !== 'undefined' ? zbooks.nonce : '',
            i18n: typeof zbooks !== 'undefined' ? zbooks.i18n : {},
            pluginUrl: typeof zbooksData !== 'undefined' ? zbooksData.pluginUrl : '',
            version: typeof zbooksData !== 'undefined' ? zbooksData.version : ''
        },

        /**
         * Module registry and loading state
         */
        modules: {},
        loadedModules: {},
        loadedStyles: {},

        /**
         * Bulk sync state
         */
        bulkState: {
            isProcessing: false,
            queue: [],
            processed: 0,
            succeeded: 0,
            failed: 0,
            total: 0,
            asDraft: false
        },

        /**
         * Parse AJAX error and return user-friendly message.
         *
         * @param {Object} xhr - The XMLHttpRequest object.
         * @param {string} defaultMsg - Default message if no specific error detected.
         * @return {string} User-friendly error message.
         */
        getAjaxErrorMessage: function(xhr, defaultMsg) {
            var i18n = this.config.i18n;

            // Check for nonce/authentication failure (WordPress returns -1 with 403).
            if (xhr.status === 403) {
                if (xhr.responseText === '-1' || xhr.responseText === '0') {
                    return i18n.session_expired || 'Session expired. Please refresh the page and try again.';
                }
                return i18n.permission_denied || 'Permission denied. You may not have access to this feature.';
            }

            // Check for server errors.
            if (xhr.status >= 500) {
                return i18n.server_error || 'Server error. Please check your server logs or try again later.';
            }

            // Try to parse JSON error response.
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.data && response.data.message) {
                    return response.data.message;
                }
            } catch (e) {
                // Not JSON, continue.
            }

            // Network connectivity issues.
            if (xhr.status === 0) {
                return i18n.network_error || 'Network error. Please check your internet connection.';
            }

            return defaultMsg + ' (HTTP ' + xhr.status + ')';
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
         * Register a module
         *
         * @param {string} name Module name
         * @param {Function} initFn Initialization function
         * @return {void}
         */
        registerModule: function(name, initFn) {
            this.modules[name] = initFn;
        },

        /**
         * Load module JS dynamically
         *
         * @param {string} name Module name
         * @param {Function} callback Optional callback after load
         * @return {void}
         */
        loadModule: function(name, callback) {
            var self = this;

            if (this.loadedModules[name]) {
                if (callback) callback();
                return;
            }

            var script = document.createElement('script');
            script.src = this.config.pluginUrl + 'assets/js/modules/' + name + '.js?ver=' + this.config.version;
            script.onload = function() {
                self.loadedModules[name] = true;
                if (self.modules[name]) {
                    self.modules[name]();
                }
                if (callback) callback();
            };
            script.onerror = function() {
                console.error('Failed to load module:', name);
            };
            document.head.appendChild(script);
        },

        /**
         * Load module CSS dynamically
         *
         * @param {string} name Module name
         * @return {void}
         */
        loadStyle: function(name) {
            if (this.loadedStyles[name]) return;

            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = this.config.pluginUrl + 'assets/css/modules/' + name + '.css?ver=' + this.config.version;
            document.head.appendChild(link);
            this.loadedStyles[name] = true;
        },

        /**
         * Initialize based on current page/tab
         *
         * @return {void}
         */
        init: function() {
            var self = this;

            // Bind common event handlers
            $(document).on('click', '.zbooks-message-dismiss', this.dismissMessage.bind(this));

            // Bind legacy event handlers (for pages without dedicated modules)
            this.bindLegacyEvents();

            // Detect current page/tab
            var $body = $('body');
            var currentPage = $body.data('zbooks-page');
            var $activeTab = $('.nav-tab-active');
            var currentTab = $activeTab.length ? $activeTab.data('tab') : null;

            // Load module for current page/tab
            this.loadTabModule(currentTab || currentPage);

            // Handle tab switching
            $('.nav-tab').on('click', function(e) {
                var tab = $(e.currentTarget).data('tab');
                self.loadTabModule(tab);
            });

            // Initialize legacy modules that are already loaded
            this.initLegacyModules();

            // Initialize select all functionality
            this.initSelectAll();
        },

        /**
         * Bind legacy event handlers for pages without dedicated modules
         *
         * @return {void}
         */
        bindLegacyEvents: function() {
            // NOTE: Order sync and payment buttons are now handled by order-sync.js module
            // Do NOT bind them here to avoid duplicate handlers and page reloads
            
            // Test connection button (still handled here as no dedicated module)
            $(document).on('click', '.zbooks-test-connection-btn', this.handleTestConnection.bind(this));

            // Bulk sync buttons are now handled by order-sync.js module
            // Do NOT bind them here to avoid duplicate handlers
            
            // NOTE: If order-sync.js is not loaded on a page, these won't work
            // but that's intentional - module should be loaded where needed
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
                        // NOTE: No reload needed - meta box stays updated
                        // If you see this function running, it means order-sync.js module is not loaded properly
                        console.warn('[ZBooks] LEGACY syncOrder function called - order-sync.js module should handle this');
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
                        $result.html('<span class="dashicons dashicons-yes" style="color:green;"></span> ' + (response.data.message || 'Payment applied successfully!'));
                        // NOTE: No reload needed - meta box stays updated
                        // If you see this function running, it means order-sync.js module is not loaded properly
                        console.warn('[ZBooks] LEGACY applyPayment function called - order-sync.js module should handle this');
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
         * Handle test connection button click
         *
         * @param {Event} e Click event
         * @return {void}
         */
        handleTestConnection: function(e) {
            e.preventDefault();

            var self = this;
            var $button = $(e.currentTarget);
            var $result = $('#zbooks-connection-result');

            if ($button.hasClass('zbooks-btn-loading')) {
                return;
            }

            // Set loading state
            $button.addClass('zbooks-btn-loading').prop('disabled', true);
            $result.html('<span class="spinner is-active" style="float:none; margin:0;"></span> Testing connection...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_test_connection',
                    nonce: self.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html(
                            '<div class="notice notice-success inline"><p>' +
                            '<span class="dashicons dashicons-yes"></span> ' +
                            (response.data.message || 'Connection successful!') +
                            '</p></div>'
                        );
                    } else {
                        $result.html(
                            '<div class="notice notice-error inline"><p>' +
                            '<span class="dashicons dashicons-warning"></span> ' +
                            (response.data.message || 'Connection failed') +
                            '</p></div>'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = self.getAjaxErrorMessage(xhr, 'Network error');
                    $result.html(
                        '<div class="notice notice-error inline"><p>' +
                        '<span class="dashicons dashicons-warning"></span> ' +
                        errorMsg +
                        '</p></div>'
                    );
                },
                complete: function() {
                    $button.removeClass('zbooks-btn-loading').prop('disabled', false);
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
            var $checkbox = $(e.currentTarget);
            var isChecked = $checkbox.prop('checked');

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
            var $bulkBtn = $('.zbooks-bulk-sync-btn, #zbooks-sync-selected');

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
                total: postIds.length,
                asDraft: false
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
            var orderStatus = $form.find('#zbooks_order_status').val(); // Array of selected statuses

            if (!dateFrom || !dateTo) {
                alert('Please select a date range.');
                return;
            }

            // Hide selected orders progress box if showing
            $('.zbooks-progress-container').hide();
            
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
                    order_status: orderStatus, // Pass order status filter
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

                // NOTE: No reload needed - table stays updated
                // If you see this function running, it means order-sync.js module is not loaded properly
                console.warn('[ZBooks] LEGACY processNextBulkItem function called - order-sync.js module should handle this');
                
                // Update stats boxes dynamically instead of reloading
                if (typeof self.updateStatsBoxes === 'function') {
                    self.updateStatsBoxes();
                }
                return;
            }

            var orderId = state.queue.shift();

            // Show current order being synced
            var currentNum = state.processed + 1;
            $progress.find('.zbooks-progress-text').text(
                'Syncing order #' + orderId + ' (' + currentNum + ' / ' + state.total + ')...'
            );

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
                        'Synced ' + state.processed + ' / ' + state.total + ' orders (' + percent + '%)...'
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
            // Hide date-range progress box if showing
            $('#zbooks-bulk-sync-progress').hide();
            
            var $container = $('.zbooks-progress-container');

            if (!$container.length) {
                $container = $(
                    '<div class="zbooks-progress-container">' +
                        '<div class="zbooks-progress-header">' +
                            '<span class="zbooks-progress-title">Syncing orders...</span>' +
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
            } else {
                // Reset existing container
                $container.find('.zbooks-progress-title').text('Syncing orders...');
                $container.find('.zbooks-progress-count').text('0 / ' + this.bulkState.total);
                $container.find('.zbooks-progress-fill').css('width', '0%');
                $container.find('.zbooks-progress-percentage').text('0%');
                $container.find('.zbooks-progress-status').removeClass('is-complete is-error').addClass('is-processing').text('Processing...');
                
                // Add cancel button if not present
                if (!$container.find('.zbooks-cancel-bulk-btn').length) {
                    $container.append('<button type="button" class="button zbooks-cancel-bulk-btn">Cancel</button>');
                }
                
                $container.show();
            }

            // Disable bulk actions
            $('.zbooks-bulk-sync-btn, #zbooks-sync-selected').prop('disabled', true);
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

            // Show syncing status
            var $status = $row.find('.zbooks-status, .order-status');
            if ($status.length) {
                $status.addClass('zbooks-syncing').text('Syncing...');
            }

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
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to sync order';
                        self.updateRowStatus($row, 'failed', errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    state.failed++;
                    var errorMsg = 'Network error: ' + error;
                    self.updateRowStatus($row, 'failed', errorMsg);
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
         * @param {string} errorMsg Optional error message for failed status
         * @return {void}
         */
        updateRowStatus: function($row, status, errorMsg) {
            var $status = $row.find('.zbooks-status, .order-status');
            if ($status.length) {
                $status.removeClass('zbooks-syncing');
                this.updateStatusBadge($status, status);
                
                // Add error tooltip for failed status
                if (status === 'failed' && errorMsg) {
                    $status.attr('title', errorMsg).css('cursor', 'help');
                }
            }
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
            $('.zbooks-bulk-sync-btn, #zbooks-sync-selected').prop('disabled', false);
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
            $('.zbooks-bulk-sync-btn, #zbooks-sync-selected').prop('disabled', false);
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
         * Load module when tab is clicked or page loads
         *
         * @param {string} tab Tab identifier
         * @return {void}
         */
        loadTabModule: function(tab) {
            if (!tab) return;

            var moduleMap = {
                'products': 'product-mapping',
                'orders': 'order-sync',
                'connection': 'connection-tab',
                'payments': 'payments',
                'custom_fields': 'custom-fields',
                'reconciliation': 'reconciliation',
                'notifications': 'notifications',
                'logs': 'log-viewer'
            };

            // Additional modules to load for specific tabs
            var additionalModules = {
                'orders': ['orders-tab']
            };

            var moduleName = moduleMap[tab];
            if (moduleName) {
                this.loadModule(moduleName);
                this.loadStyle(moduleName);
            }

            // Load any additional modules for this tab
            if (additionalModules[tab]) {
                var self = this;
                additionalModules[tab].forEach(function(extraModule) {
                    self.loadModule(extraModule);
                });
            }
        },

        /**
         * Initialize legacy modules that are already loaded
         * This provides backward compatibility during transition
         *
         * @return {void}
         */
        initLegacyModules: function() {
            // Initialize modules that are already loaded via PHP enqueue
            if (typeof window.ZbooksOrderSync !== 'undefined' && typeof window.ZbooksOrderSync.init === 'function') {
                window.ZbooksOrderSync.init();
            }
            if (typeof window.ZbooksProductMapping !== 'undefined' && typeof window.ZbooksProductMapping.init === 'function') {
                window.ZbooksProductMapping.init();
            }
            if (typeof window.ZbooksConnectionTab !== 'undefined' && typeof window.ZbooksConnectionTab.init === 'function') {
                window.ZbooksConnectionTab.init();
            }
            if (typeof window.ZbooksPaymentMapping !== 'undefined' && typeof window.ZbooksPaymentMapping.init === 'function') {
                window.ZbooksPaymentMapping.init();
            }
            if (typeof window.ZbooksFieldMapping !== 'undefined' && typeof window.ZbooksFieldMapping.init === 'function') {
                window.ZbooksFieldMapping.init();
            }
            if (typeof window.ZbooksLogViewer !== 'undefined' && typeof window.ZbooksLogViewer.init === 'function') {
                window.ZbooksLogViewer.init();
            }
            if (typeof window.ZbooksReconciliation !== 'undefined' && typeof window.ZbooksReconciliation.init === 'function') {
                window.ZbooksReconciliation.init();
            }
            if (typeof window.ZbooksNotifications !== 'undefined' && typeof window.ZbooksNotifications.init === 'function') {
                window.ZbooksNotifications.init();
            }
            if (typeof window.ZbooksOrdersTab !== 'undefined' && typeof window.ZbooksOrdersTab.init === 'function') {
                window.ZbooksOrdersTab.init();
            }
        }
    };

    // Backward compatibility: Alias ZbooksCommon to ZBooks
    window.ZbooksCommon = window.ZBooks;

    // Initialize on document ready
    $(document).ready(function() {
        ZBooks.init();
    });

})(jQuery);
