/**
 * Zbooks Reconciliation Module
 *
 * Handles reconciliation report running, viewing, and management.
 * Includes date range reconciliation, report viewing, CSV export, and report deletion.
 *
 * @package    Zbooks
 * @author     talas9
 * @since      1.0.0
 */

(function($) {
    'use strict';

    /**
     * Reconciliation Module
     * Handles reconciliation report functionality
     */
    window.ZbooksReconciliation = {
        nonce: '',
        initialized: false,

        /**
         * Initialize the reconciliation module
         */
        init: function() {
            // Prevent double initialization
            if (this.initialized) {
                return;
            }

            // Check if we're on the reconciliation page
            if (!$('#zbooks-run-reconciliation').length && !$('#zbooks-recon-frequency').length) {
                return;
            }

            // Get reconciliation nonce from main zbooks object
            this.nonce = typeof zbooks !== 'undefined' ? zbooks.reconciliation_nonce : '';
            this.bindEvents();
            this.initialized = true;
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};

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
                    error: function(xhr) {
                        var msg = self.getAjaxErrorMessage(xhr, i18n.reconciliation_failed || 'Reconciliation failed.');
                        alert(msg);
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
                    error: function(xhr) {
                        var msg = self.getAjaxErrorMessage(xhr, i18n.failed_to_delete_report || 'Failed to delete report.');
                        alert(msg);
                        $btn.prop('disabled', false);
                    }
                });
            });

            // Delete all reports
            $(document).on('click', '.zbooks-delete-all-reports', function() {
                if (!confirm(i18n.confirm_delete_all_reports || 'Are you sure you want to delete ALL reports? This cannot be undone.')) {
                    return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zbooks_delete_all_reports',
                        nonce: self.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || (i18n.failed_to_delete_reports || 'Failed to delete reports.'));
                            $btn.prop('disabled', false);
                        }
                    },
                    error: function(xhr) {
                        var msg = self.getAjaxErrorMessage(xhr, i18n.failed_to_delete_reports || 'Failed to delete reports.');
                        alert(msg);
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
                    error: function(xhr) {
                        var msg = self.getAjaxErrorMessage(xhr, i18n.failed_to_load_report || 'Failed to load report.');
                        alert(msg);
                        $btn.prop('disabled', false);
                    }
                });
            });

            // Single order sync from discrepancy row
            $(document).on('click', '.zbooks-recon-sync', function() {
                var $btn = $(this);
                var orderId = $btn.data('order-id');
                var $row = $btn.closest('tr');

                $btn.prop('disabled', true).text(i18n.syncing || 'Syncing...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zbooks_reconciliation_sync',
                        nonce: self.nonce,
                        order_id: orderId
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.addClass('zbooks-recon-sync-success');
                            $btn.replaceWith('<span class="zbooks-sync-done">' +
                                (i18n.synced || 'Synced') + '</span>');
                            self.updateMissingCount(-1);
                        } else {
                            $btn.prop('disabled', false)
                                .text(i18n.retry || 'Retry')
                                .addClass('zbooks-recon-sync-error');
                            alert(response.data.message || (i18n.sync_failed || 'Sync failed'));
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text(i18n.sync || 'Sync');
                        alert(i18n.sync_failed || 'Sync failed');
                    }
                });
            });

            // Bulk sync all missing orders
            $(document).on('click', '.zbooks-sync-all-missing', function() {
                var $btn = $(this);

                if (!confirm(i18n.confirm_bulk_sync || 'Sync all missing orders to Zoho Books?')) {
                    return;
                }

                $btn.prop('disabled', true);
                var originalText = $btn.text();
                $btn.text(i18n.syncing || 'Syncing...');

                // Collect order IDs from sync buttons
                var orderIds = [];
                $('.zbooks-recon-sync').each(function() {
                    orderIds.push($(this).data('order-id'));
                });

                if (orderIds.length === 0) {
                    alert(i18n.no_orders_to_sync || 'No orders to sync');
                    $btn.prop('disabled', false).text(originalText);
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zbooks_reconciliation_bulk_sync',
                        nonce: self.nonce,
                        order_ids: orderIds
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert(response.data.message || (i18n.bulk_sync_failed || 'Bulk sync failed'));
                            $btn.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        alert(i18n.bulk_sync_failed || 'Bulk sync failed');
                        $btn.prop('disabled', false).text(originalText);
                    }
                });
            });
        },

        /**
         * Update the missing orders count in summary cards
         *
         * @param {number} delta Change in count (negative to decrease)
         */
        updateMissingCount: function(delta) {
            var $card = $('.zbooks-card-danger .zbooks-card-value, .zbooks-card.zbooks-card-danger .zbooks-card-value');
            if ($card.length) {
                var current = parseInt($card.text(), 10) || 0;
                var newVal = Math.max(0, current + delta);
                $card.text(newVal);

                if (newVal === 0) {
                    $card.closest('.zbooks-card').removeClass('zbooks-card-danger').addClass('zbooks-card-neutral');
                }
            }
        },

        /**
         * Show report modal with reconciliation details
         *
         * @param {Object} data Report data
         */
        showReportModal: function(data) {
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};
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
        },

        /**
         * Parse AJAX error and return user-friendly message
         *
         * @param {Object} xhr XMLHttpRequest object
         * @param {string} defaultMsg Default message
         * @return {string} Error message
         */
        getAjaxErrorMessage: function(xhr, defaultMsg) {
            // Use ZBooks utility if available
            if (window.ZBooks && typeof window.ZBooks.getAjaxErrorMessage === 'function') {
                return window.ZBooks.getAjaxErrorMessage(xhr, defaultMsg);
            }

            // Fallback error parsing
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                return xhr.responseJSON.data.message;
            }
            if (xhr.responseText) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.data && response.data.message) {
                        return response.data.message;
                    }
                } catch (e) {
                    // Not JSON
                }
            }
            return defaultMsg;
        }
    };

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        ZbooksReconciliation.init();
    });

    // Register with ZBooks module system if available
    if (window.ZBooks && typeof window.ZBooks.registerModule === 'function') {
        window.ZBooks.registerModule('reconciliation', function() {
            ZbooksReconciliation.init();
        });
    }

})(jQuery);
