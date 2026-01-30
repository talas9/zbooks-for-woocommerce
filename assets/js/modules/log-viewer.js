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

        /**
         * Initialize the log viewer module
         */
        init: function() {
            // Check if we're on the log viewer page
            this.$modal = $('#zbooks-log-modal');
            if (!this.$modal.length) {
                return;
            }

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
