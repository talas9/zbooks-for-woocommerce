/**
 * Zbooks Product Mapping Module
 *
 * Handles bulk product creation and linking on the Products tab.
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
        window.ZBooks.registerModule('product-mapping', function() {
            if (window.ZbooksProductMapping) {
                window.ZbooksProductMapping.init();
            }
        });
    }

    /**
     * Product Mapping Module
     * Handles bulk product creation and linking on the Products tab
     */
    window.ZbooksProductMapping = {
        nonce: '',
        mappingInProgress: false,
        initialized: false,

        init: function() {
            // Prevent double initialization
            if (this.initialized) {
                return;
            }

            this.initialized = true;

            // Check if we're on the product mapping page OR product edit page
            var isProductsTab = $('#zbooks-select-all-products').length || $('.zbooks-product-checkbox').length;
            var isProductEditPage = $('.zbooks-product-meta-box').length;

            if (!isProductsTab && !isProductEditPage) {
                return;
            }

            this.nonce = typeof zbooks_mapping !== 'undefined' ? zbooks_mapping.nonce : '';

            if (isProductsTab) {
                this.bindEvents();
                this.initSelect2();
            }

            if (isProductEditPage) {
                this.bindMetaBoxEvents();
            }
        },

        /**
         * Update product totals in the UI
         *
         * @param {Object} totals Object containing total, mapped, and unmapped counts
         */
        updateTotals: function(totals) {
            if (!totals) return;
            
            // Update the totals display
            $('.zbooks-total-products').text(totals.total);
            $('.zbooks-mapped-products').text(totals.mapped);
            $('.zbooks-unmapped-products').text(totals.unmapped);
            
            // Optional: Add visual feedback (flash animation)
            $('.zbooks-product-totals').addClass('updated');
            setTimeout(function() {
                $('.zbooks-product-totals').removeClass('updated');
            }, 1000);
        },

        bindEvents: function() {
            var self = this;

            // Update selected count
            function updateSelectedCount() {
                var count = $('.zbooks-product-checkbox:checked').length;
                var $countSpan = $('#zbooks-selected-count');
                var $bulkBtn = $('#zbooks-bulk-create');
                var i18n = window.ZbooksCommon ? window.ZbooksCommon.config.i18n : {};

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
                var i18n = window.ZbooksCommon ? window.ZbooksCommon.config.i18n : {};

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
                var i18n = window.ZbooksCommon ? window.ZbooksCommon.config.i18n : {};

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

            // Save mapping (link to existing) - AJAX without page reload
            $(document).on('click', '.zbooks-save-mapping', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');
                var $row = $btn.closest('tr');
                var $select = $row.find('.zbooks-zoho-item-select');
                var zohoItemId = $select.val();
                var i18n = window.ZbooksCommon ? window.ZbooksCommon.config.i18n : {};

                // Skip if button is disabled (already linked)
                if ($btn.prop('disabled')) {
                    return;
                }

                if (!zohoItemId) {
                    alert(i18n.select_zoho_item || 'Please select a Zoho item to link.');
                    return;
                }

                // Check for SKU mismatch before linking
                var selectedOption = $select.find('option:selected');
                var itemSku = selectedOption.data('sku') || '';
                var productSku = $select.data('product-sku') || '';
                
                // Compare SKUs (case-insensitive, trim whitespace)
                if (productSku && itemSku && productSku.trim().toLowerCase() !== itemSku.trim().toLowerCase()) {
                    var confirmMsg = 'You are trying to link a product to an item with a different SKU:\n\n' +
                        'Product SKU: ' + productSku + '\n' +
                        'Item SKU: ' + itemSku + '\n\n' +
                        'Are you sure you want to proceed?';
                    
                    if (!confirm(confirmMsg)) {
                        return; // Cancel the link operation
                    }
                }

                $btn.prop('disabled', true).text(i18n.linking || 'Linking...');

                $.post(ajaxurl, {
                    action: 'zbooks_link_product',
                    nonce: self.nonce,
                    product_id: productId,
                    item_id: zohoItemId
                }, function(response) {
                    if (response.success) {
                        // Update totals
                        self.updateTotals(response.data.totals);
                        
                        // Replace the entire actions cell with Linked and Unlink buttons only
                        var $actionsCell = $row.find('td:last');
                        $actionsCell.html(
                            '<button type="button" class="button button-small button-disabled" disabled>' +
                                (i18n.linked || 'Linked') + '</button> ' +
                            '<button type="button" class="button button-small zbooks-remove-mapping" data-product-id="' + productId + '">' +
                                (i18n.unlink || 'Unlink') + '</button>'
                        );
                        
                        // Update checkbox to checkmark
                        var $checkbox = $row.find('.zbooks-product-checkbox');
                        if ($checkbox.length) {
                            $checkbox.replaceWith('<span class="dashicons dashicons-yes" style="color: #00a32a;" title="' +
                                (i18n.mapped || 'Mapped') + '"></span>');
                        }
                        
                        // Show success message
                        var $statusSpan = $('#zbooks-action-status');
                        $statusSpan.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
                        setTimeout(function() {
                            $statusSpan.html('');
                        }, 3000);
                    } else {
                        $btn.prop('disabled', false).text(i18n.link || 'Link');
                        alert(response.data.message || 'Error linking product');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text(i18n.link || 'Link');
                    alert('Network error. Please try again.');
                });
            });

            // Remove mapping (unlink) - AJAX without page reload
            $(document).on('click', '.zbooks-remove-mapping', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');
                var $row = $btn.closest('tr');
                var i18n = window.ZbooksCommon ? window.ZbooksCommon.config.i18n : {};

                if (!confirm(i18n.confirm_unlink_product || 'Unlink this product from Zoho?')) {
                    return;
                }

                $btn.prop('disabled', true).text(i18n.unlinking || 'Unlinking...');

                $.post(ajaxurl, {
                    action: 'zbooks_unlink_product',
                    nonce: self.nonce,
                    product_id: productId
                }, function(response) {
                    if (response.success) {
                        // Update totals
                        self.updateTotals(response.data.totals);
                        
                        // Replace the action buttons with Create and Link buttons
                        var $actionsCell = $row.find('td:last');
                        $actionsCell.html(
                            '<button type="button" class="button button-small zbooks-create-single" data-product-id="' + productId + '">' +
                                (i18n.create || 'Create') + '</button> ' +
                            '<button type="button" class="button button-small zbooks-save-mapping" data-product-id="' + productId + '">' +
                                (i18n.link || 'Link') + '</button>'
                        );
                        
                        // Reset dropdown to "Not Mapped"
                        var $select = $row.find('.zbooks-zoho-item-select');
                        $select.val('').trigger('change');
                        
                        // Update checkmark to checkbox
                        var $checkmark = $row.find('.dashicons-yes');
                        if ($checkmark.length) {
                            $checkmark.replaceWith('<input type="checkbox" class="zbooks-product-checkbox" value="' + productId + '">');
                        }
                        
                        // Show success message
                        var $statusSpan = $('#zbooks-action-status');
                        $statusSpan.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
                        setTimeout(function() {
                            $statusSpan.html('');
                        }, 3000);
                    } else {
                        $btn.prop('disabled', false).text(i18n.unlink || 'Unlink');
                        alert(response.data.message || 'Error unlinking product');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text(i18n.unlink || 'Unlink');
                    alert('Network error. Please try again.');
                });
            });

            // Auto-map by SKU button
            $('#zbooks-auto-map').on('click', function() {
                var $btn = $(this);
                var $status = $('#zbooks-action-status');
                var i18n = window.ZbooksCommon ? window.ZbooksCommon.config.i18n : {};

                if ($btn.prop('disabled')) {
                    return;
                }

                if (!confirm(i18n.confirm_auto_map || 'Automatically map products to Zoho items by matching SKU?')) {
                    return;
                }

                self.autoMapProductsIndividually($btn, $status, i18n);
            });

            // Refresh Zoho items button
            $('#zbooks-refresh-items').on('click', function() {
                var $btn = $(this);
                var $status = $('#zbooks-action-status');
                var i18n = window.ZbooksCommon ? window.ZbooksCommon.config.i18n : {};

                if ($btn.prop('disabled')) {
                    return;
                }

                $btn.prop('disabled', true).text(i18n.refreshing || 'Refreshing...');
                $status.text(i18n.fetching_zoho_items || 'Fetching Zoho items...');

                $.post(ajaxurl, {
                    action: 'zbooks_fetch_zoho_items',
                    nonce: self.nonce
                }, function(response) {
                    $btn.prop('disabled', false).text(i18n.refresh_zoho_items || 'Refresh Zoho Items');
                    if (response.success) {
                        $status.text(response.data.message || (i18n.items_refreshed || 'Items refreshed!'));
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        $status.text(response.data.message || (i18n.refresh_failed || 'Refresh failed'));
                    }
                }).fail(function(xhr) {
                    $btn.prop('disabled', false).text(i18n.refresh_zoho_items || 'Refresh Zoho Items');
                    var errorMsg = window.ZbooksCommon ? 
                        window.ZbooksCommon.getAjaxErrorMessage(xhr, i18n.refresh_failed || 'Refresh failed') :
                        'Refresh failed';
                    $status.text(errorMsg);
                });
            });
        },

        /**
         * Auto-map products individually via AJAX without page reload
         *
         * @param {jQuery} $btn    The auto-map button
         * @param {jQuery} $status The status display element
         * @param {Object} i18n    Internationalization strings
         */
        autoMapProductsIndividually: function($btn, $status, i18n) {
            var self = this;

            // Set flag to prevent navigation
            self.mappingInProgress = true;

            // Add beforeunload event listener
            $(window).on('beforeunload.zbooks-mapping', function(e) {
                if (self.mappingInProgress) {
                    var message = i18n.mapping_leave_warning ||
                        'Mapping is in progress. Are you sure you want to leave? Unmapped products will not be processed.';
                    e.preventDefault();
                    e.returnValue = message;
                    return message;
                }
            });

            // Find all unmapped product rows (those with empty select values)
            var $unmappedRows = $('tr[data-product-id]').filter(function() {
                var $row = $(this);
                var $select = $row.find('.zbooks-zoho-item-select');
                var currentValue = $select.val();
                // Only include rows that are not currently mapped
                return !currentValue || currentValue === '';
            });

            if ($unmappedRows.length === 0) {
                $status.html('<span style="color: #dba617;">⚠ ' + (i18n.no_unmapped_products || 'No unmapped products found.') + '</span>');
                return;
            }

            var total = $unmappedRows.length;
            var mapped = 0;
            var failed = 0;
            var currentIndex = 0;

            // Disable button during processing
            $btn.prop('disabled', true).text(i18n.mapping || 'Mapping...');

            /**
             * Process a single product
             */
            function processSingleProduct() {
                if (currentIndex >= $unmappedRows.length) {
                    // All done - show summary
                    $btn.prop('disabled', false).text(i18n.auto_map_by_sku || 'Auto-Map by SKU');
                    
                    // Clear mapping in progress flag and remove warning
                    self.mappingInProgress = false;
                    $(window).off('beforeunload.zbooks-mapping');
                    
                    var summaryMsg = (i18n.mapping_complete || 'Mapping complete!') + ' ';
                    summaryMsg += (i18n.mapped || 'Mapped') + ': ' + mapped + ', ';
                    summaryMsg += (i18n.failed || 'Failed') + ': ' + failed;
                    
                    if (mapped > 0) {
                        $status.html('<span style="color: #00a32a;">✓ ' + summaryMsg + '</span>');
                    } else {
                        $status.html('<span style="color: #dba617;">⚠ ' + summaryMsg + '</span>');
                    }

                    return;
                }

                var $row = $($unmappedRows[currentIndex]);
                var productId = $row.data('product-id');
                var $select = $row.find('.zbooks-zoho-item-select');
                var $checkbox = $row.find('.zbooks-product-checkbox');

                // Update progress
                var progressMsg = (i18n.mapping_product || 'Mapping product') + ' ' + (currentIndex + 1) + ' ' + (i18n.of || 'of') + ' ' + total + '...';
                $status.html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span>' + progressMsg);

                // Grey out the row and add spinner
                $row.addClass('zbooks-mapping-in-progress');
                var $productNameCell = $row.find('td:nth-child(3)');
                $productNameCell.append('<span class="zbooks-mapping-spinner"></span>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zbooks_auto_map_single_product',
                        product_id: productId,
                        nonce: self.nonce
                    },
                    success: function(response) {
                        // Remove grey-out and spinner
                        $row.removeClass('zbooks-mapping-in-progress');
                        $row.find('.zbooks-mapping-spinner').remove();
                        
                        if (response.success && response.data) {
                            // Successfully mapped - update the UI
                            mapped++;
                            
                            // Update totals after each product is mapped
                            self.updateTotals(response.data.totals);
                            
                            // Update the select dropdown
                            var itemId = response.data.item_id;
                            var itemName = response.data.item_name;
                            var itemSku = response.data.item_sku || '';
                            
                            // Check if option exists, if not add it
                            var $option = $select.find('option[value="' + itemId + '"]');
                            if ($option.length === 0) {
                                var optionText = itemName;
                                if (itemSku) {
                                    optionText += ' (' + itemSku + ')';
                                }
                                $select.append('<option value="' + itemId + '">' + optionText + '</option>');
                            }
                            
                            // Set the selected value
                            $select.val(itemId).trigger('change');
                            
                            // Replace checkbox with success icon
                            $checkbox.replaceWith('<span class="dashicons dashicons-yes" style="color: #00a32a;" title="' + (i18n.mapped || 'Mapped') + '"></span>');
                            
                            // Update row background to success
                            $row.css('background-color', '#d4edda');
                            
                            // Update the action buttons
                            var $actionsCell = $row.find('td:last');
                            $actionsCell.html(
                                '<button type="button" class="button button-small zbooks-save-mapping" data-product-id="' + productId + '" disabled style="opacity: 0.5; cursor: not-allowed;">' +
                                (i18n.linked || 'Linked') +
                                '</button> ' +
                                '<button type="button" class="button button-small zbooks-remove-mapping" data-product-id="' + productId + '">' +
                                (i18n.unlink || 'Unlink') +
                                '</button>'
                            );
                        } else {
                            // Failed to map
                            failed++;
                            $row.css('background-color', '#f8d7da');
                        }
                    },
                    error: function() {
                        // Remove grey-out and spinner
                        $row.removeClass('zbooks-mapping-in-progress');
                        $row.find('.zbooks-mapping-spinner').remove();

                        failed++;
                        $row.css('background-color', '#f8d7da');
                    },
                    complete: function() {
                        // Move to next product after a short delay
                        currentIndex++;
                        setTimeout(processSingleProduct, 300);
                    }
                });
            }

            // Start processing
            processSingleProduct();
        },

        /**
         * Initialize Select2 on Zoho item dropdowns
         */
        initSelect2: function() {
            var self = this;

            // Check if Select2 is available
            if (typeof $.fn.select2 === 'undefined') {
                return;
            }

            // Initialize Select2 on each dropdown
            $('.zbooks-zoho-item-select').each(function() {
                var $select = $(this);
                var productId = $select.data('product-id');
                var productName = $select.data('product-name') || '';
                var productSku = $select.data('product-sku') || '';

                // Check if already initialized to prevent duplicates
                if ($select.hasClass('select2-hidden-accessible')) {
                    return;
                }

                $select.select2({
                    placeholder: '-- Not Mapped --',
                    allowClear: true,
                    width: '100%',
                    ajax: {
                        url: ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            return {
                                action: 'zbooks_search_zoho_items',
                                nonce: self.nonce,
                                q: params.term || '',
                                product_id: productId,
                                product_name: productName,
                                product_sku: productSku,
                                page: params.page || 1
                            };
                        },
                        processResults: function(response) {
                            if (response.success && response.data) {
                                return {
                                    results: response.data.results,
                                    pagination: response.data.pagination
                                };
                            }
                            return {
                                results: []
                            };
                        },
                        cache: true
                    },
                    minimumInputLength: 0,
                    templateResult: function(item) {
                        if (item.loading) {
                            return item.text;
                        }
                        return item.text;
                    },
                    templateSelection: function(item) {
                        return item.text || item.id;
                    }
                });
            });
        },

        /**
         * ========================================================================
         * PRODUCT META BOX FUNCTIONALITY (for individual product edit pages)
         * ========================================================================
         */

        /**
         * Bind events for product meta box (product edit page)
         */
        bindMetaBoxEvents: function() {
            var self = this;

            // Get product-specific nonce
            this.productNonce = typeof zbooks_product !== 'undefined' ? zbooks_product.nonce : this.nonce;

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

                self.syncProductToZoho(productId, $btn, $result);
            });

            // Unlink button
            $(document).on('click', '.zbooks-unlink-btn', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');
                var $result = $('.zbooks-product-result');

                self.unlinkProduct(productId, $btn, $result);
            });
        },

        /**
         * Create Zoho item from product
         */
        createZohoItem: function(productId, trackInventory, $btn, $result) {
            var self = this;
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};

            $btn.prop('disabled', true).text(i18n.creating || 'Creating...');
            $result.html('');

            $.post(ajaxurl, {
                action: 'zbooks_create_zoho_item',
                nonce: self.productNonce || self.nonce,
                product_id: productId,
                track_inventory: trackInventory ? '1' : '0'
            }, function(response) {
                if (response.success) {
                    $result.html('<span style="color:green;">' + response.data.message + '</span>');
                    
                    // Update meta box display without reload
                    if (response.data && response.data.item_id) {
                        self.updateProductMetaBox(response.data);
                    }
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

        /**
         * Show inventory tracking error dialog with retry option
         */
        showInventoryErrorDialog: function(errorMessage, productId, $btn, $result) {
            var self = this;
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};

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
                $('.zbooks-track-inventory').prop('checked', false);
                self.createZohoItem(productId, false, $btn, $result);
            });

            // Handle cancel
            $result.find('.zbooks-cancel-create').on('click', function() {
                $result.html('');
            });
        },

        /**
         * Show duplicate item error dialog
         */
        showDuplicateErrorDialog: function(errorMessage, productId, searchTerm, $btn, $result) {
            var self = this;
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};

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

        /**
         * Search for existing Zoho items and show selection dialog
         */
        searchAndShowItems: function(productId, searchTerm, $btn, $result) {
            var self = this;
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};

            $result.html('<p style="color:#666;"><span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>' + (i18n.searching || 'Searching...') + '</p>');

            $.post(ajaxurl, {
                action: 'zbooks_search_and_link_item',
                nonce: self.productNonce || self.nonce,
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

        /**
         * Show item selection dialog
         */
        showItemSelectionDialog: function(items, productId, $btn, $result) {
            var self = this;
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};

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

        /**
         * Link selected Zoho item to product
         */
        linkItemToProduct: function(productId, itemId, $result) {
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};
            
            $result.html('<p style="color:#666;"><span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>' + (i18n.linking || 'Linking...') + '</p>');

            $.post(ajaxurl, {
                action: 'zbooks_save_mapping',
                nonce: this.nonce,
                product_id: productId,
                zoho_item_id: itemId
            }, function(response) {
                if (response.success) {
                    $result.html('<span style="color:green;">' + (i18n.item_linked_success || 'Item linked successfully!') + '</span>');
                    
                    // Update meta box display without reload
                    // The response doesn't include full item data, so we'd need to fetch it or just show success
                    // For now, just update the status
                    var $metaBox = $('.zbooks-product-meta-box');
                    if ($metaBox.length) {
                        var $statusSpan = $metaBox.find('.zbooks-status');
                        if ($statusSpan.length) {
                            $statusSpan.removeClass('zbooks-status-none').addClass('zbooks-status-synced').text('Linked');
                        }
                    }
                } else {
                    $result.html('<span style="color:red;">' + (response.data.message || (i18n.failed_to_link || 'Failed to link item.')) + '</span>');
                }
            }).fail(function() {
                $result.html('<span style="color:red;">' + (i18n.network_error_linking || 'Network error while linking.') + '</span>');
            });
        },

        /**
         * Sync product to Zoho (update existing item)
         */
        syncProductToZoho: function(productId, $btn, $result) {
            var self = this;
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};

            $btn.prop('disabled', true).text(i18n.updating || 'Updating...');
            $result.html('');

            $.post(ajaxurl, {
                action: 'zbooks_sync_product_to_zoho',
                nonce: self.productNonce || self.nonce,
                product_id: productId
            }, function(response) {
                $btn.prop('disabled', false).text(i18n.update_in_zoho || 'Update in Zoho');

                if (response.success) {
                    $result.html('<span style="color:green;">' + response.data.message + '</span>');

                    // Update product meta box display (no reload needed)
                    self.updateProductMetaBox(response.data);
                } else {
                    var errorMsg = response.data ? response.data.message : 'Error updating item';
                    $result.html('<span style="color:red;">' + errorMsg + '</span>');
                }
            }).fail(function(xhr) {
                $btn.prop('disabled', false).text(i18n.update_in_zoho || 'Update in Zoho');
                
                var errorMsg = 'Network error';
                if (xhr.status === 403) {
                    errorMsg = 'Permission denied or session expired';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server error';
                } else if (xhr.status === 0) {
                    errorMsg = 'Connection failed';
                }
                
                $result.html('<span style="color:red;">' + errorMsg + '</span>');
            });
        },

        /**
         * Update product meta box display with sync results
         */
        updateProductMetaBox: function(data) {
            try {
                var $metaBox = $('.zbooks-product-meta-box');
                if (!$metaBox.length) {
                    return;
                }
                
                // Update status badge
                var $statusSpan = $metaBox.find('.zbooks-status');
                if ($statusSpan.length) {
                    $statusSpan
                        .removeClass('zbooks-status-none')
                        .addClass('zbooks-status-synced')
                        .text('Linked');
                }
                
                // Update Zoho Item ID link
                if (data.item_id && data.item_url) {
                    var $itemIdPara = $metaBox.find('p:contains("Zoho Item ID:")');
                    if ($itemIdPara.length) {
                        $itemIdPara.html('<strong>Zoho Item ID:</strong> <a href="' + data.item_url + '" target="_blank">' + data.item_id + '</a>');
                    }
                }
                
                // Update Name
                if (data.item_name) {
                    var $namePara = $metaBox.find('p:contains("Name:")');
                    if ($namePara.length) {
                        $namePara.html('<strong>Name:</strong> ' + data.item_name);
                    }
                }
                
                // Update SKU
                if (data.item_sku) {
                    var $skuPara = $metaBox.find('p:contains("SKU:")');
                    if ($skuPara.length) {
                        $skuPara.html('<strong>SKU:</strong> ' + data.item_sku);
                    }
                }
                
                // Update Rate
                if (data.item_rate) {
                    var $ratePara = $metaBox.find('p:contains("Rate:")');
                    if ($ratePara.length) {
                        $ratePara.html('<strong>Rate:</strong> ' + data.item_rate);
                    }
                }
                
                // Update Item Status
                if (data.item_status) {
                    var $statusPara = $metaBox.find('p:contains("Item Status:")');
                    if ($statusPara.length) {
                        $statusPara.html('<strong>Item Status:</strong> ' + data.item_status);
                    }
                }
            } catch (error) {
                // Silent failure for UI updates
            }
        },

        /**
         * Unlink product from Zoho item
         */
        unlinkProduct: function(productId, $btn, $result) {
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};

            if (!confirm(i18n.confirm_unlink || 'Remove the link to this Zoho item? This will not delete the item in Zoho.')) {
                return;
            }

            $btn.prop('disabled', true);

            $.post(ajaxurl, {
                action: 'zbooks_remove_mapping',
                nonce: this.nonce,
                product_id: productId
            }, function(response) {
                if (response.success) {
                    $result.html('<span style="color:green;">Item unlinked successfully</span>');
                    
                    // Update meta box status to "Not synced"
                    var $metaBox = $('.zbooks-product-meta-box');
                    if ($metaBox.length) {
                        var $statusSpan = $metaBox.find('.zbooks-status');
                        if ($statusSpan.length) {
                            $statusSpan.removeClass('zbooks-status-synced').addClass('zbooks-status-none').text('Not synced');
                        }
                        // Hide linked item details
                        $metaBox.find('p:contains("Zoho Item ID:"), p:contains("Name:"), p:contains("SKU:"), p:contains("Rate:"), p:contains("Item Status:")').hide();
                    }
                } else {
                    $btn.prop('disabled', false);
                    $result.html('<span style="color:red;">' + (response.data.message || 'Error unlinking') + '</span>');
                }
            });
        }
    };

    // Note: Module is initialized via ZBooks.registerModule() above
    // and also by admin.js initLegacyModules() for backward compatibility
    // Direct initialization removed to prevent double-init

})(jQuery);
