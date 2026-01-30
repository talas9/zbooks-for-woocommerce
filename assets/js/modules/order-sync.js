/**
 * Zbooks Order Sync Module
 *
 * Handles manual sync, bulk sync, and order synchronization operations.
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
        window.ZBooks.registerModule('order-sync', function() {
            if (window.ZbooksOrderSync) {
                window.ZbooksOrderSync.init();
            }
        });
    }

    /**
     * Order Sync Module
     * Handles order synchronization with Zoho Books
     */
    window.ZbooksOrderSync = {
        
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
         */
        init: function() {
            console.log('[ZBooks] Order sync module initializing');
            
            // Clean up any stale loading states from previous page loads
            $('.zbooks-sync-btn, .zbooks-apply-payment-btn').removeClass('zbooks-btn-loading').prop('disabled', false);
            console.log('[ZBooks] Cleared any stale loading states');
            
            this.bindEvents();
            this.initSelectAll();
            console.log('[ZBooks] Order sync module initialized');
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Manual sync button in meta box
            $(document).on('click', '.zbooks-sync-btn', this.handleManualSync.bind(this));

            // Apply payment button
            $(document).on('click', '.zbooks-apply-payment-btn', this.handleApplyPayment.bind(this));

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
            
            // Intercept WooCommerce bulk action for orders list
            this.interceptBulkAction();
        },

        /**
         * Initialize select all checkbox state
         */
        initSelectAll: function() {
            this.updateSelectedCount();
        },

        /**
         * Handle manual sync button click
         */
        handleManualSync: function(e) {
            e.preventDefault();
            console.log('[ZBooks] Manual sync button clicked');

            var $button = $(e.currentTarget);
            var orderId = $button.data('order-id');
            var asDraft = $button.data('draft') === true || $button.data('draft') === 'true';

            console.log('[ZBooks] Order ID:', orderId, 'Draft:', asDraft, 'Has loading class:', $button.hasClass('zbooks-btn-loading'));

            // Check for loading state first (prevent double-click)
            if ($button.hasClass('zbooks-btn-loading')) {
                console.warn('[ZBooks] Sync cancelled - already loading');
                return;
            }

            // Check for valid order ID
            if (!orderId) {
                console.error('[ZBooks] Sync cancelled - no order ID found in button data');
                return;
            }

            this.syncOrder(orderId, asDraft, $button);
        },

        /**
         * Handle apply payment button click
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
         */
        syncOrder: function(orderId, asDraft, $button) {
            var self = this;
            var $container = $button.closest('.zbooks-meta-box');
            var $result = $container.find('.zbooks-sync-result');
            var config = window.ZbooksCommon ? window.ZbooksCommon.config : {};
            var i18n = config.i18n || {};

            console.log('[ZBooks] Starting sync for order:', orderId, 'Draft:', asDraft);
            console.log('[ZBooks] AJAX URL:', config.ajaxUrl || ajaxurl);
            console.log('[ZBooks] Container found:', $container.length, 'Result element:', $result.length);

            // Set loading state
            $button.addClass('zbooks-btn-loading').prop('disabled', true);
            $result.html('<span class="spinner is-active" style="float:none; margin:0;"></span> ' + (i18n.syncing || 'Syncing...'));

            $.ajax({
                url: config.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'zbooks_manual_sync',
                    order_id: orderId,
                    as_draft: asDraft ? 'true' : 'false',
                    nonce: config.nonce || ''
                },
                success: function(response) {
                    console.log('[ZBooks] Sync AJAX success:', response);
                    if (response.success) {
                        $result.html('<span class="dashicons dashicons-yes" style="color:green;"></span> ' + (response.data.message || i18n.sync_success || 'Sync successful!'));
                        
                        // Update the meta box with new data (no page reload needed)
                        self.updateMetaBoxDisplay($container, response.data, false);
                    } else {
                        var errorMsg = response.data ? response.data.message : 'Unknown error';
                        console.error('[ZBooks] Sync failed:', errorMsg, response);
                        $result.html('<span class="dashicons dashicons-warning" style="color:red;"></span> ' + errorMsg);
                        
                        // Update status to failed
                        self.updateMetaBoxDisplay($container, response.data || {message: errorMsg}, true);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[ZBooks] Sync AJAX error:', {status: status, error: error, responseText: xhr.responseText, statusCode: xhr.status});
                    
                    var errorMsg = 'Network error: ' + error;
                    if (xhr.status === 403) {
                        errorMsg = 'Permission denied or session expired. Please refresh the page.';
                    } else if (xhr.status === 500) {
                        errorMsg = 'Server error. Please check error logs.';
                    } else if (xhr.status === 0) {
                        errorMsg = 'Connection failed. Please check your internet connection.';
                    }
                    
                    $result.html('<span class="dashicons dashicons-warning" style="color:red;"></span> ' + errorMsg);
                    self.updateMetaBoxDisplay($container, {message: errorMsg}, true);
                },
                complete: function() {
                    $button.removeClass('zbooks-btn-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Update meta box display with sync results (no page reload needed)
         * Handles both success and error states
         */
        updateMetaBoxDisplay: function($container, data, isError) {
            try {
                console.log('[ZBooks] Updating meta box display with:', data, 'Error:', isError);
                
                if (isError) {
                    // Handle error state
                    var $statusSpan = $container.find('.zbooks-status');
                    if ($statusSpan.length) {
                        $statusSpan
                            .attr('class', 'zbooks-status zbooks-status-failed')
                            .text('Failed');
                    }
                    
                    // Show error message if not already shown
                    var errorMsg = data.message || 'Sync failed';
                    console.error('[ZBooks] Sync error:', errorMsg);
                    return;
                }
                
                // Success state - update all fields
                
                // Update status badge
                var $statusSpan = $container.find('.zbooks-status');
                if ($statusSpan.length && data.status_label) {
                    $statusSpan
                        .attr('class', 'zbooks-status ' + data.status_class)
                        .text(data.status_label);
                    console.log('[ZBooks] Status updated to:', data.status_label);
                }
                
                // Update or add invoice info
                if (data.invoice_id) {
                    var invoiceHtml = '<strong>Invoice:</strong> ' +
                        '<a href="' + data.invoice_url + '" target="_blank">' +
                        (data.invoice_number || data.invoice_id) + '</a>';
                    
                    var $existingInvoice = $container.find('p:contains("Invoice:")');
                    if ($existingInvoice.length) {
                        $existingInvoice.html(invoiceHtml);
                    } else {
                        $container.find('p:first').after('<p>' + invoiceHtml + '</p>');
                    }
                    console.log('[ZBooks] Invoice updated:', data.invoice_id);
                }
                
                // Update or add contact info
                if (data.contact_id && data.contact_url) {
                    var contactHtml = '<strong>Contact:</strong> ' +
                        '<a href="' + data.contact_url + '" target="_blank">' +
                        (data.contact_name || data.contact_id) + '</a>';
                    
                    var $existingContact = $container.find('p:contains("Contact:")');
                    if ($existingContact.length) {
                        $existingContact.html(contactHtml);
                    } else {
                        var $invoicePara = $container.find('p:contains("Invoice:")');
                        if ($invoicePara.length) {
                            $invoicePara.after('<p>' + contactHtml + '</p>');
                        }
                    }
                    console.log('[ZBooks] Contact updated:', data.contact_id);
                }
                
                // Update invoice status if present
                if (data.invoice_status) {
                    var $statusPara = $container.find('p:contains("Invoice Status:")');
                    if ($statusPara.length) {
                        $statusPara.find('span').text(data.invoice_status);
                    } else {
                        $container.append('<p><strong>Invoice Status:</strong> <span>' + data.invoice_status + '</span></p>');
                    }
                    console.log('[ZBooks] Invoice status updated:', data.invoice_status);
                }
                
                // Update payment info if present
                if (data.payment_id) {
                    var paymentHtml = '<strong>Payment:</strong> ' + (data.payment_number || data.payment_id);
                    var $existingPayment = $container.find('p:contains("Payment:")');
                    if ($existingPayment.length) {
                        $existingPayment.html(paymentHtml);
                    } else {
                        $container.append('<p>' + paymentHtml + '</p>');
                    }
                    console.log('[ZBooks] Payment updated:', data.payment_id);
                }
                
                // Update "Last attempt:" timestamp
                if (data.last_attempt) {
                    var $lastAttempt = $container.find('p:contains("Last attempt:")');
                    if ($lastAttempt.length) {
                        $lastAttempt.html('<strong>Last attempt:</strong> ' + data.last_attempt);
                        console.log('[ZBooks] Last attempt updated:', data.last_attempt);
                    } else {
                        // Add it before the <hr> if it doesn't exist
                        var $hr = $container.find('hr');
                        if ($hr.length) {
                            $hr.before('<p><strong>Last attempt:</strong> ' + data.last_attempt + '</p>');
                            console.log('[ZBooks] Last attempt added:', data.last_attempt);
                        }
                    }
                }
                
                console.log('[ZBooks] Meta box updated successfully');
            } catch (error) {
                console.error('[ZBooks] Error updating meta box display:', error);
            }
        },

        /**
         * Apply payment to an order
         */
        applyPayment: function(orderId, $button) {
            var self = this;
            var $container = $button.closest('.zbooks-meta-box');
            var $result = $container.find('.zbooks-sync-result');
            var config = window.ZbooksCommon ? window.ZbooksCommon.config : {};

            // Set loading state
            $button.addClass('zbooks-btn-loading').prop('disabled', true);
            $result.html('<span class="spinner is-active" style="float:none; margin:0;"></span> Applying payment...');

            $.ajax({
                url: config.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'zbooks_apply_payment',
                    order_id: orderId,
                    nonce: config.nonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span class="dashicons dashicons-yes" style="color:green;"></span> ' + (response.data.message || 'Payment applied!'));
                        
                        // Update meta box if payment data is returned
                        if (response.data) {
                            self.updateMetaBoxDisplay($container, response.data);
                        }
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
         * Intercept WooCommerce bulk action form submission for orders list
         */
        interceptBulkAction: function() {
            var self = this;
            
            // Intercept both top and bottom bulk action forms
            $('#posts-filter, #wc-orders-filter').on('submit', function(e) {
                var $form = $(this);
                var action = $form.find('select[name="action"]').val();
                var action2 = $form.find('select[name="action2"]').val();
                
                // Check if "Sync to Zoho Books" bulk action is selected
                var selectedAction = action === 'zbooks_sync' ? action : (action2 === 'zbooks_sync' ? action2 : null);
                
                if (selectedAction === 'zbooks_sync') {
                    console.log('[ZBooks] Intercepting bulk sync action');
                    e.preventDefault();
                    
                    // Get selected order IDs
                    var orderIds = [];
                    $form.find('input[name="post[]"]:checked, input[name="id[]"]:checked').each(function() {
                        orderIds.push($(this).val());
                    });
                    
                    if (orderIds.length === 0) {
                        alert('Please select at least one order to sync.');
                        return false;
                    }
                    
                    console.log('[ZBooks] Selected order IDs:', orderIds);
                    self.startBulkActionSync(orderIds);
                    return false;
                }
            });
        },
        
        /**
         * Start bulk action sync with progress modal
         */
        startBulkActionSync: function(orderIds) {
            var self = this;
            
            console.log('[ZBooks] Starting bulk action sync for', orderIds.length, 'orders');
            
            // Create progress modal
            var modalHtml = '<div id="zbooks-bulk-action-modal" style="' +
                'position: fixed; top: 0; left: 0; right: 0; bottom: 0; ' +
                'background: rgba(0,0,0,0.7); z-index: 999999; display: flex; ' +
                'align-items: center; justify-content: center;">' +
                '<div style="background: white; padding: 30px; border-radius: 8px; ' +
                'min-width: 500px; max-width: 600px; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">' +
                    '<h2 style="margin-top: 0;">Syncing Orders to Zoho Books</h2>' +
                    '<div class="zbooks-bulk-progress">' +
                        '<div class="zbooks-progress-bar" style="background: #f0f0f0; height: 30px; border-radius: 4px; overflow: hidden; margin: 20px 0;">' +
                            '<div class="zbooks-progress-fill" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>' +
                        '</div>' +
                        '<div class="zbooks-progress-text" style="text-align: center; font-size: 14px; color: #666;">Preparing to sync...</div>' +
                        '<div class="zbooks-progress-details" style="margin-top: 20px; max-height: 200px; overflow-y: auto; font-size: 12px;"></div>' +
                    '</div>' +
                    '<div class="zbooks-bulk-actions" style="margin-top: 20px; text-align: right;">' +
                        '<button type="button" class="button zbooks-cancel-bulk-action-btn">Cancel</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
            
            $('body').append(modalHtml);
            
            // Bind cancel button
            $(document).on('click', '.zbooks-cancel-bulk-action-btn', function() {
                self.cancelBulkActionSync();
            });
            
            // Start syncing
            this.bulkActionState = {
                queue: orderIds.slice(),
                total: orderIds.length,
                current: 0,
                succeeded: 0,
                failed: 0,
                isProcessing: true,
                results: []
            };
            
            this.processBulkActionNext();
        },
        
        /**
         * Process next order in bulk action queue
         */
        processBulkActionNext: function() {
            var self = this;
            var state = this.bulkActionState;
            
            if (!state.isProcessing) {
                console.log('[ZBooks] Bulk action sync cancelled');
                return;
            }
            
            if (state.queue.length === 0) {
                // Complete
                console.log('[ZBooks] Bulk action sync complete');
                this.completeBulkActionSync();
                return;
            }
            
            var orderId = state.queue.shift();
            state.current++;
            
            // Update progress
            var percent = Math.round((state.current / state.total) * 100);
            $('#zbooks-bulk-action-modal .zbooks-progress-fill').css('width', percent + '%');
            $('#zbooks-bulk-action-modal .zbooks-progress-text').text(
                'Syncing order ' + state.current + ' of ' + state.total + '...'
            );
            
            // Add "processing" status to details
            $('#zbooks-bulk-action-modal .zbooks-progress-details').append(
                '<div class="order-' + orderId + '" style="padding: 5px; color: #666;">' +
                    'Order #' + orderId + ': <span class="status">Syncing...</span>' +
                '</div>'
            );
            
            // Sync the order
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'zbooks_manual_sync',
                    order_id: orderId,
                    as_draft: false,
                    nonce: self.nonce || (window.ZbooksCommon && window.ZbooksCommon.config ? window.ZbooksCommon.config.nonce : '')
                },
                success: function(response) {
                    if (response.success) {
                        state.succeeded++;
                        $('#zbooks-bulk-action-modal .order-' + orderId + ' .status')
                            .html('<span style="color: #46b450;">✓ Synced</span>');
                        state.results.push({
                            order_id: orderId,
                            success: true
                        });
                        
                        // Update Zoho Status column badge in orders table
                        self.updateZohoStatusBadge(orderId, response.data);
                    } else {
                        state.failed++;
                        var errorMsg = response.data ? response.data.message : 'Unknown error';
                        $('#zbooks-bulk-action-modal .order-' + orderId + ' .status')
                            .html('<span style="color: #d63638;">✗ Failed: ' + errorMsg + '</span>');
                        state.results.push({
                            order_id: orderId,
                            success: false,
                            error: errorMsg
                        });
                        
                        // Update Zoho Status column badge to show error
                        self.updateZohoStatusBadge(orderId, {status: 'error', error: errorMsg});
                    }
                },
                error: function(xhr, status, error) {
                    state.failed++;
                    $('#zbooks-bulk-action-modal .order-' + orderId + ' .status')
                        .html('<span style="color: #d63638;">✗ Error: ' + error + '</span>');
                    state.results.push({
                        order_id: orderId,
                        success: false,
                        error: error
                    });
                },
                complete: function() {
                    // Process next order
                    self.processBulkActionNext();
                }
            });
        },
        
        /**
         * Complete bulk action sync
         */
        completeBulkActionSync: function() {
            var state = this.bulkActionState;
            
            $('#zbooks-bulk-action-modal .zbooks-progress-text').html(
                '<strong style="color: #46b450;">Complete!</strong> ' +
                state.succeeded + ' synced, ' +
                state.failed + ' failed.'
            );
            
            $('#zbooks-bulk-action-modal .zbooks-cancel-bulk-action-btn')
                .text('Close')
                .removeClass('zbooks-cancel-bulk-action-btn')
                .addClass('zbooks-close-bulk-action-btn');
            
            $(document).on('click', '.zbooks-close-bulk-action-btn', function() {
                $('#zbooks-bulk-action-modal').fadeOut(300, function() {
                    $(this).remove();
                });
            });
            
            state.isProcessing = false;
        },
        
        /**
         * Cancel bulk action sync
         */
        cancelBulkActionSync: function() {
            if (!confirm('Are you sure you want to cancel the sync?')) {
                return;
            }
            
            this.bulkActionState.isProcessing = false;
            
            $('#zbooks-bulk-action-modal .zbooks-progress-text').html(
                '<strong style="color: #d63638;">Cancelled</strong>'
            );
            
            $('#zbooks-bulk-action-modal .zbooks-cancel-bulk-action-btn').text('Close');
            
            setTimeout(function() {
                $('#zbooks-bulk-action-modal').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 2000);
        },
        
        /**
         * Update Zoho Status badge in orders table
         */
        updateZohoStatusBadge: function(orderId, data) {
            try {
                var $badge = $('.zbooks-status-badge[data-order-id="' + orderId + '"]');
                if (!$badge.length) {
                    console.log('[ZBooks] Zoho status badge not found for order:', orderId);
                    return;
                }
                
                var statusConfig = this.getZohoStatusConfig(data);
                
                // Update badge
                $badge
                    .removeClass(function(index, className) {
                        return (className.match(/(^|\s)zbooks-status-\S+/g) || []).join(' ');
                    })
                    .addClass('zbooks-status-' + statusConfig.slug)
                    .css({
                        'background': statusConfig.bg,
                        'color': statusConfig.color,
                        'cursor': statusConfig.slug === 'error' ? 'help' : 'default'
                    })
                    .text(statusConfig.label);
                
                // Remove error tooltip if not an error
                if (statusConfig.slug !== 'error') {
                    $badge.removeAttr('title');
                } else if (data.error) {
                    // Add/update error tooltip if error exists
                    $badge.attr('title', 'Error: ' + data.error);
                }
                
                console.log('[ZBooks] Updated Zoho status badge for order', orderId, 'to:', statusConfig.label);
            } catch (error) {
                console.error('[ZBooks] Error updating Zoho status badge:', error);
            }
        },
        
        /**
         * Get Zoho status configuration based on sync data
         */
        getZohoStatusConfig: function(data) {
            // Error state
            if (data.status === 'error' || data.error) {
                return {
                    slug: 'error',
                    label: 'Error',
                    bg: '#d63638',
                    color: '#ffffff'
                };
            }
            
            // Check for refund/credit note
            if (data.has_refunds || data.refund_id) {
                return {
                    slug: 'refunded',
                    label: 'Refunded',
                    bg: '#d63638',
                    color: '#ffffff'
                };
            }
            
            // Check for payment
            if (data.payment_id) {
                return {
                    slug: 'paid',
                    label: 'Paid Invoice',
                    bg: '#00a32a',
                    color: '#ffffff'
                };
            }
            
            // Invoice exists but not paid = Draft Invoice
            if (data.invoice_id) {
                return {
                    slug: 'draft',
                    label: 'Draft Invoice',
                    bg: '#dba617',
                    color: '#ffffff'
                };
            }
            
            // Default: unsynced (shouldn't happen after successful sync)
            return {
                slug: 'unsynced',
                label: 'Unsynced',
                bg: '#f0f0f1',
                color: '#646970'
            };
        },
        
        /**
         * Handle select all checkbox
         */
        handleSelectAll: function(e) {
            var isChecked = $(e.currentTarget).is(':checked');
            $('.zbooks-item-checkbox').prop('checked', isChecked);
            this.updateSelectedCount();
        },

        /**
         * Update selected items count
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

            // Warn user if they try to navigate away
            this.setBulkSyncActive(true);

            // Show progress UI
            this.showBulkProgress();

            // Start processing
            this.processNextBulkItem();
        },

        /**
         * Handle bulk sync date range form submission
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
            var config = window.ZbooksCommon ? window.ZbooksCommon.config : {};

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
                url: config.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'zbooks_get_orders_by_date',
                    date_from: dateFrom,
                    date_to: dateTo,
                    nonce: config.nonce || ''
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

                        // Warn user if they try to navigate away
                        self.setBulkSyncActive(true);

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
         */
        processNextBulkItemDateRange: function($progress, $button) {
            var self = this;
            var state = this.bulkState;
            var config = window.ZbooksCommon ? window.ZbooksCommon.config : {};

            if (!state.isProcessing || state.queue.length === 0) {
                // Complete
                var statusText = 'Completed: ' + state.succeeded + ' succeeded, ' + state.failed + ' failed.';
                $progress.find('.zbooks-progress-text').text(statusText);
                $progress.find('.zbooks-cancel-bulk-btn').remove();
                $button.prop('disabled', false).text('Start Bulk Sync');
                state.isProcessing = false;

                // Remove beforeunload warning
                self.setBulkSyncActive(false);

                // Update stats without page reload
                if (typeof self.updateStatsBoxes === 'function') {
                    self.updateStatsBoxes();
                }
                
                console.log('[ZBooks] Bulk sync completed - stats updated without reload');
                return;
            }

            var orderId = state.queue.shift();

            $.ajax({
                url: config.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'zbooks_manual_sync',
                    order_id: orderId,
                    as_draft: state.asDraft ? 'true' : 'false',
                    nonce: config.nonce || ''
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
         */
        processNextBulkItem: function() {
            var self = this;
            var state = this.bulkState;
            var config = window.ZbooksCommon ? window.ZbooksCommon.config : {};

            if (!state.isProcessing || state.queue.length === 0) {
                this.completeBulkSync();
                return;
            }

            var postId = state.queue.shift();
            var $row = $('.zbooks-item-checkbox[value="' + postId + '"]').closest('tr');
            
            console.log('[ZBooks] Bulk sync processing order:', postId);

            // Show syncing status immediately
            var $status = $row.find('.zbooks-status, .order-status');
            if ($status.length) {
                $status.removeClass('zbooks-status-synced zbooks-status-failed zbooks-status-pending')
                    .addClass('zbooks-status-syncing')
                    .text('Syncing...');
            }

            $.ajax({
                url: config.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'zbooks_manual_sync',
                    order_id: postId,
                    as_draft: 'false',
                    nonce: config.nonce || ''
                },
                success: function(response) {
                    console.log('[ZBooks] Bulk sync response for order', postId, ':', response);
                    if (response.success) {
                        state.succeeded++;
                        self.updateRowStatus($row, response.data);
                    } else {
                        state.failed++;
                        console.error('[ZBooks] Bulk sync failed for order', postId, ':', response.data);
                        self.updateRowStatus($row, {status: 'failed', message: response.data ? response.data.message : 'Failed'});
                    }
                },
                error: function(xhr, status, error) {
                    state.failed++;
                    console.error('[ZBooks] Bulk sync AJAX error for order', postId, ':', {status: status, error: error, xhr: xhr});
                    self.updateRowStatus($row, {status: 'failed', message: 'Network error'});
                },
                complete: function() {
                    state.processed++;
                    self.updateBulkProgress();
                    self.processNextBulkItem();
                }
            });
        },

        /**
         * Update row status after sync with full data
         * @param {jQuery} $row The table row element
         * @param {Object|string} data Response data object or simple status string
         */
        updateRowStatus: function($row, data) {
            try {
                var $status = $row.find('.zbooks-status, .order-status');
                
                if (!$status.length) {
                    console.warn('[ZBooks] Status element not found in row');
                    return;
                }
                
                // Handle legacy string format for backward compatibility
                if (typeof data === 'string') {
                    $status.removeClass('zbooks-status-syncing zbooks-status-pending zbooks-status-failed zbooks-status-synced');
                    if (data === 'synced') {
                        $status.addClass('zbooks-status-synced').text('Synced');
                    } else if (data === 'failed') {
                        $status.addClass('zbooks-status-failed').text('Failed');
                    } else {
                        $status.text(data);
                    }
                    return;
                }
                
                // Handle full data object
                console.log('[ZBooks] Updating row with data:', data);
                
                // Update status badge
                $status.removeClass('zbooks-status-syncing zbooks-status-pending zbooks-status-failed zbooks-status-synced');
                if (data.status_class) {
                    $status.addClass(data.status_class);
                }
                if (data.status_label) {
                    $status.text(data.status_label);
                } else if (data.status === 'failed') {
                    $status.addClass('zbooks-status-failed').text('Failed');
                } else {
                    $status.addClass('zbooks-status-synced').text('Synced');
                }
                
                // Update invoice number column if present
                if (data.invoice_number && data.invoice_url) {
                    var $invoiceCell = $row.find('.column-invoice_number, td:contains("Invoice")').first();
                    if ($invoiceCell.length) {
                        $invoiceCell.html('<a href="' + data.invoice_url + '" target="_blank">' + data.invoice_number + '</a>');
                    }
                }
                
                // Update contact name if present
                if (data.contact_name && data.contact_url) {
                    var $contactCell = $row.find('.column-contact_name').first();
                    if ($contactCell.length) {
                        $contactCell.html('<a href="' + data.contact_url + '" target="_blank">' + data.contact_name + '</a>');
                    }
                }
                
                console.log('[ZBooks] Row updated successfully');
            } catch (error) {
                console.error('[ZBooks] Error updating row status:', error);
            }
        },

        /**
         * Complete bulk sync process
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

            // Remove beforeunload warning
            this.setBulkSyncActive(false);

            // Update stats if they exist
            this.updateStatsBoxes();
        },

        /**
         * Handle cancel bulk sync
         */
        handleCancelBulkSync: function(e) {
            e.preventDefault();

            this.bulkState.isProcessing = false;
            this.bulkState.queue = [];

            // Remove beforeunload warning
            this.setBulkSyncActive(false);

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
         */
        updateStatsBoxes: function() {
            var config = window.ZbooksCommon ? window.ZbooksCommon.config : {};

            $.ajax({
                url: config.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'zbooks_get_stats',
                    nonce: config.nonce || ''
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
         * Warn user when navigating away during bulk sync
         */
        handleBeforeUnload: function(e) {
            var i18n = window.ZbooksCommon ? window.ZbooksCommon.config.i18n : {};
            var message = i18n.bulk_sync_leave_warning ||
                'Bulk sync is in progress. Leaving this page will cancel the sync. Are you sure you want to leave?';
            e.preventDefault();
            e.returnValue = message;
            return message;
        },

        /**
         * Set or remove the beforeunload handler based on bulk sync state
         */
        setBulkSyncActive: function(isActive) {
            if (isActive) {
                $(window).on('beforeunload', this.handleBeforeUnload);
            } else {
                $(window).off('beforeunload', this.handleBeforeUnload);
            }
        }
    };

    // Note: Module is initialized via ZBooks.registerModule() above
    // and also by admin.js initLegacyModules() for backward compatibility
    // Direct initialization removed to prevent double-init

})(jQuery);
