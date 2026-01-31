/**
 * Zbooks Log Viewer Module
 *
 * Handles log viewing functionality including modal display,
 * log detail viewing, and JSON copying.
 *
 * @package    Zbooks
 * @author     talas9
 * @since      1.0.0
 */

(function($) {
    'use strict';

    /**
     * Log Viewer Module
     * Handles log viewer page functionality
     */
    window.ZbooksLogViewer = {
        $modal: null,
        currentEntry: null,
        initialized: false,

        /**
         * Initialize the log viewer module
         */
        init: function() {
            // Prevent double initialization
            if (this.initialized) {
                return;
            }

            // Check if we're on the log viewer page
            this.$modal = $('#zbooks-log-modal');
            if (!this.$modal.length) {
                return;
            }

            this.initialized = true;
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
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

            // Refresh logs button
            $(document).on('click', '.zbooks-refresh-logs', function(e) {
                e.preventDefault();
                self.refreshLogs();
            });

            // Clear old logs button
            $(document).on('click', '.zbooks-clear-old-logs', function(e) {
                e.preventDefault();
                self.clearOldLogs();
            });

            // Clear all logs button
            $(document).on('click', '.zbooks-clear-all-logs', function(e) {
                e.preventDefault();
                self.clearAllLogs();
            });
        },

        /**
         * Get configuration
         */
        getConfig: function() {
            return typeof zbooksLogViewer !== 'undefined' ? zbooksLogViewer : {
                ajaxUrl: typeof ajaxurl !== 'undefined' ? ajaxurl : '',
                nonces: {},
                i18n: {}
            };
        },

        /**
         * Refresh the logs page
         */
        refreshLogs: function() {
            window.location.reload();
        },

        /**
         * Clear old logs
         */
        clearOldLogs: function() {
            var self = this;
            var config = this.getConfig();

            if (!confirm(config.i18n.confirm_clear || 'Are you sure you want to clear old logs?')) {
                return;
            }

            var $button = $('.zbooks-clear-old-logs');
            var originalText = $button.text();
            $button.prop('disabled', true).text(config.i18n.clearing || 'Clearing...');

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_clear_logs',
                    nonce: config.nonces.clear_logs || ''
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        self.refreshLogs();
                    } else {
                        alert(response.data.message || 'Failed to clear logs');
                    }
                },
                error: function() {
                    alert('Network error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Clear all logs
         */
        clearAllLogs: function() {
            var self = this;
            var config = this.getConfig();

            if (!confirm(config.i18n.confirm_clear_all || 'Are you sure you want to clear ALL logs? This cannot be undone.')) {
                return;
            }

            var $button = $('.zbooks-clear-all-logs');
            var originalText = $button.text();
            $button.prop('disabled', true).text(config.i18n.clearing || 'Clearing...');

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zbooks_clear_all_logs',
                    nonce: config.nonces.clear_all_logs || ''
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        self.refreshLogs();
                    } else {
                        alert(response.data.message || 'Failed to clear logs');
                    }
                },
                error: function() {
                    alert('Network error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Show log entry details in modal
         *
         * @param {Object} entry Log entry object
         */
        showLogDetails: function(entry) {
            if (!entry) return;
            this.currentEntry = entry;

            $('#zbooks-modal-timestamp').text(entry.timestamp);
            $('#zbooks-modal-level').html(
                '<span class="zbooks-log-level zbooks-level-' + entry.level.toLowerCase() + '">' +
                entry.level + '</span>'
            );
            $('#zbooks-modal-message').text(entry.message);

            // Check for request info in context.
            var hasRequestInfo = entry.context && (
                entry.context.method ||
                entry.context.endpoint ||
                entry.context.request_url
            );

            if (hasRequestInfo) {
                var requestInfo = '';
                if (entry.context.method) {
                    requestInfo += '<strong>' + entry.context.method + '</strong> ';
                }
                if (entry.context.endpoint) {
                    requestInfo += '<code>' + entry.context.endpoint + '</code>';
                }
                if (entry.context.request_url) {
                    requestInfo += '<br><small style="color: #666;">' + entry.context.request_url + '</small>';
                }
                if (entry.context.status_code) {
                    var statusColor = entry.context.status_code >= 400 ? '#d63638' : '#00a32a';
                    requestInfo += '<br><span style="color: ' + statusColor + ';">Status: ' + entry.context.status_code + '</span>';
                }
                $('#zbooks-modal-request').html(requestInfo);
                $('#zbooks-modal-request-row').show();
            } else {
                $('#zbooks-modal-request-row').hide();
            }

            if (entry.context && Object.keys(entry.context).length > 0) {
                $('#zbooks-modal-context').text(JSON.stringify(entry.context, null, 2));
                $('#zbooks-modal-context-row').show();
            } else {
                $('#zbooks-modal-context-row').hide();
            }

            this.$modal.fadeIn(200);
        },

        /**
         * Copy current log entry JSON to clipboard
         */
        copyJsonToClipboard: function() {
            if (!this.currentEntry) return;

            // Get i18n from ZBooks namespace
            var i18n = (window.ZBooks && window.ZBooks.config && window.ZBooks.config.i18n) || {};

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
    };

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        ZbooksLogViewer.init();
    });

    // Register with ZBooks module system if available
    if (window.ZBooks && typeof window.ZBooks.registerModule === 'function') {
        window.ZBooks.registerModule('log-viewer', function() {
            ZbooksLogViewer.init();
        });
    }

})(jQuery);
