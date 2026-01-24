<?php
/**
 * Log viewer admin page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Logger\SyncLogger;

defined('ABSPATH') || exit;

/**
 * Admin page for viewing sync logs.
 */
class LogViewer {

    /**
     * Logger instance.
     *
     * @var SyncLogger
     */
    private SyncLogger $logger;

    /**
     * Constructor.
     *
     * @param SyncLogger $logger Logger instance.
     */
    public function __construct(SyncLogger $logger) {
        $this->logger = $logger;
        $this->register_hooks();
    }

    /**
     * Register hooks.
     */
    private function register_hooks(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('wp_ajax_zbooks_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_zbooks_clear_logs', [$this, 'ajax_clear_logs']);
    }

    /**
     * Add submenu page under ZBooks menu.
     */
    public function add_menu_page(): void {
        add_submenu_page(
            'zbooks',
            __('Logs', 'zbooks-for-woocommerce'),
            __('Logs', 'zbooks-for-woocommerce'),
            'manage_woocommerce',
            'zbooks-logs',
            [$this, 'render_page']
        );
    }

    /**
     * Render the log viewer page.
     */
    public function render_page(): void {
        $files = $this->logger->get_log_files();
        $selected_date = isset($_GET['date']) ? sanitize_text_field(wp_unslash($_GET['date'])) : '';
        $selected_level = isset($_GET['level']) ? sanitize_text_field(wp_unslash($_GET['level'])) : '';

        if (empty($selected_date) && !empty($files)) {
            $selected_date = $files[0]['date'];
        }

        $entries = [];
        $stats = [];
        if (!empty($selected_date)) {
            $entries = $this->logger->read_log($selected_date, 200, $selected_level);
            $stats = $this->logger->get_stats($selected_date);
        }

        wp_enqueue_style('zbooks-admin');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ZBooks Sync Logs', 'zbooks-for-woocommerce'); ?></h1>

            <div class="zbooks-log-controls">
                <form method="get" action="" style="display: inline-flex; gap: 10px; align-items: center;">
                    <input type="hidden" name="page" value="zbooks-logs">

                    <label for="date"><?php esc_html_e('Date:', 'zbooks-for-woocommerce'); ?></label>
                    <select name="date" id="date">
                        <?php if (empty($files)) : ?>
                            <option value=""><?php esc_html_e('No logs available', 'zbooks-for-woocommerce'); ?></option>
                        <?php else : ?>
                            <?php foreach ($files as $file) : ?>
                                <option value="<?php echo esc_attr($file['date']); ?>" <?php selected($selected_date, $file['date']); ?>>
                                    <?php echo esc_html($file['date']); ?>
                                    (<?php echo esc_html(size_format($file['size'])); ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>

                    <label for="level"><?php esc_html_e('Level:', 'zbooks-for-woocommerce'); ?></label>
                    <select name="level" id="level">
                        <option value=""><?php esc_html_e('All', 'zbooks-for-woocommerce'); ?></option>
                        <option value="ERROR" <?php selected($selected_level, 'ERROR'); ?>>
                            <?php esc_html_e('Errors', 'zbooks-for-woocommerce'); ?>
                        </option>
                        <option value="WARNING" <?php selected($selected_level, 'WARNING'); ?>>
                            <?php esc_html_e('Warnings', 'zbooks-for-woocommerce'); ?>
                        </option>
                        <option value="INFO" <?php selected($selected_level, 'INFO'); ?>>
                            <?php esc_html_e('Info', 'zbooks-for-woocommerce'); ?>
                        </option>
                        <option value="DEBUG" <?php selected($selected_level, 'DEBUG'); ?>>
                            <?php esc_html_e('Debug', 'zbooks-for-woocommerce'); ?>
                        </option>
                    </select>

                    <button type="submit" class="button"><?php esc_html_e('Filter', 'zbooks-for-woocommerce'); ?></button>

                    <button type="button" class="button" onclick="zbooksRefreshLogs()">
                        <?php esc_html_e('Refresh', 'zbooks-for-woocommerce'); ?>
                    </button>
                </form>

                <form method="post" action="" style="display: inline; margin-left: 20px;">
                    <?php wp_nonce_field('zbooks_clear_logs', 'zbooks_nonce'); ?>
                    <button type="button" class="button" onclick="zbooksClearLogs()">
                        <?php
                        $retention_days = absint( $this->logger->get_retention_days() );
                        printf(
                            /* translators: %d: number of days */
                            esc_html__( 'Clear Old Logs (%d+ days)', 'zbooks-for-woocommerce' ),
                            $retention_days
                        );
                        ?>
                    </button>
                </form>
            </div>

            <?php if (!empty($stats)) : ?>
                <div class="zbooks-log-stats" style="margin: 15px 0; padding: 10px; background: #f0f0f1; display: inline-flex; gap: 20px;">
                    <span>
                        <strong><?php esc_html_e('Total:', 'zbooks-for-woocommerce'); ?></strong>
                        <?php echo esc_html($stats['total']); ?>
                    </span>
                    <span style="color: #d63638;">
                        <strong><?php esc_html_e('Errors:', 'zbooks-for-woocommerce'); ?></strong>
                        <?php echo esc_html($stats['ERROR']); ?>
                    </span>
                    <span style="color: #dba617;">
                        <strong><?php esc_html_e('Warnings:', 'zbooks-for-woocommerce'); ?></strong>
                        <?php echo esc_html($stats['WARNING']); ?>
                    </span>
                    <span style="color: #00a32a;">
                        <strong><?php esc_html_e('Info:', 'zbooks-for-woocommerce'); ?></strong>
                        <?php echo esc_html($stats['INFO']); ?>
                    </span>
                </div>
            <?php endif; ?>

            <table class="widefat fixed striped" id="zbooks-log-table">
                <thead>
                    <tr>
                        <th style="width: 160px;"><?php esc_html_e('Timestamp', 'zbooks-for-woocommerce'); ?></th>
                        <th style="width: 80px;"><?php esc_html_e('Level', 'zbooks-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Message', 'zbooks-for-woocommerce'); ?></th>
                        <th style="width: 100px;"><?php esc_html_e('Details', 'zbooks-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)) : ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No log entries found.', 'zbooks-for-woocommerce'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($entries as $index => $entry) : ?>
                            <tr class="zbooks-log-<?php echo esc_attr(strtolower($entry['level'])); ?> zbooks-log-row"
                                data-entry="<?php echo esc_attr(wp_json_encode($entry)); ?>">
                                <td><?php echo esc_html($entry['timestamp']); ?></td>
                                <td>
                                    <span class="zbooks-log-level zbooks-level-<?php echo esc_attr(strtolower($entry['level'])); ?>">
                                        <?php echo esc_html($entry['level']); ?>
                                    </span>
                                </td>
                                <td class="zbooks-log-message"><?php echo esc_html($entry['message']); ?></td>
                                <td>
                                    <button type="button" class="button button-small zbooks-view-details">
                                        <?php esc_html_e('View', 'zbooks-for-woocommerce'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Log Details Modal -->
        <div id="zbooks-log-modal" class="zbooks-modal" style="display: none;">
            <div class="zbooks-modal-overlay"></div>
            <div class="zbooks-modal-content">
                <div class="zbooks-modal-header">
                    <h2><?php esc_html_e('Log Entry Details', 'zbooks-for-woocommerce'); ?></h2>
                    <button type="button" class="zbooks-modal-close">&times;</button>
                </div>
                <div class="zbooks-modal-body">
                    <div class="zbooks-detail-row">
                        <label><?php esc_html_e('Timestamp:', 'zbooks-for-woocommerce'); ?></label>
                        <span id="zbooks-modal-timestamp"></span>
                    </div>
                    <div class="zbooks-detail-row">
                        <label><?php esc_html_e('Level:', 'zbooks-for-woocommerce'); ?></label>
                        <span id="zbooks-modal-level"></span>
                    </div>
                    <div class="zbooks-detail-row">
                        <label><?php esc_html_e('Message:', 'zbooks-for-woocommerce'); ?></label>
                        <div id="zbooks-modal-message"></div>
                    </div>
                    <div class="zbooks-detail-row" id="zbooks-modal-context-row">
                        <label><?php esc_html_e('Context / Details:', 'zbooks-for-woocommerce'); ?></label>
                        <pre id="zbooks-modal-context"></pre>
                    </div>
                </div>
                <div class="zbooks-modal-footer">
                    <button type="button" class="button zbooks-copy-json">
                        <?php esc_html_e('Copy JSON', 'zbooks-for-woocommerce'); ?>
                    </button>
                    <button type="button" class="button button-primary zbooks-modal-close">
                        <?php esc_html_e('Close', 'zbooks-for-woocommerce'); ?>
                    </button>
                </div>
            </div>
        </div>

        <style>
            .zbooks-log-level {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
            }
            .zbooks-level-error {
                background: #d63638;
                color: #fff;
            }
            .zbooks-level-warning {
                background: #dba617;
                color: #fff;
            }
            .zbooks-level-info {
                background: #00a32a;
                color: #fff;
            }
            .zbooks-level-debug {
                background: #72aee6;
                color: #fff;
            }
            .zbooks-log-error td {
                background: #fcf0f1 !important;
            }
            .zbooks-log-warning td {
                background: #fcf9e8 !important;
            }
            .zbooks-log-row {
                cursor: pointer;
            }
            .zbooks-log-row:hover td {
                background: #f0f6fc !important;
            }
            .zbooks-log-message {
                max-width: 400px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            /* Modal Styles */
            .zbooks-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 100000;
            }
            .zbooks-modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
            }
            .zbooks-modal-content {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 5px 30px rgba(0, 0, 0, 0.3);
                width: 90%;
                max-width: 700px;
                max-height: 85vh;
                display: flex;
                flex-direction: column;
            }
            .zbooks-modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px 20px;
                border-bottom: 1px solid #dcdcde;
            }
            .zbooks-modal-header h2 {
                margin: 0;
                font-size: 18px;
            }
            .zbooks-modal-close {
                background: none;
                border: none;
                font-size: 28px;
                cursor: pointer;
                color: #666;
                line-height: 1;
                padding: 0 5px;
            }
            .zbooks-modal-close:hover {
                color: #d63638;
            }
            .zbooks-modal-body {
                padding: 20px;
                overflow-y: auto;
                flex: 1;
            }
            .zbooks-detail-row {
                margin-bottom: 15px;
            }
            .zbooks-detail-row label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
                color: #1d2327;
            }
            .zbooks-detail-row span,
            .zbooks-detail-row div {
                color: #50575e;
            }
            #zbooks-modal-message {
                background: #f6f7f7;
                padding: 10px 15px;
                border-radius: 4px;
                word-break: break-word;
            }
            #zbooks-modal-context {
                background: #1d2327;
                color: #50fa7b;
                padding: 15px;
                border-radius: 4px;
                overflow-x: auto;
                font-size: 12px;
                line-height: 1.5;
                margin: 0;
                max-height: 300px;
                overflow-y: auto;
            }
            .zbooks-modal-footer {
                padding: 15px 20px;
                border-top: 1px solid #dcdcde;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                var $modal = $('#zbooks-log-modal');
                var currentEntry = null;

                // Open modal on row click or button click
                $('.zbooks-view-details').on('click', function(e) {
                    e.stopPropagation();
                    var $row = $(this).closest('tr');
                    showLogDetails($row.data('entry'));
                });

                $('.zbooks-log-row').on('dblclick', function() {
                    showLogDetails($(this).data('entry'));
                });

                function showLogDetails(entry) {
                    if (!entry) return;
                    currentEntry = entry;

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

                    $modal.fadeIn(200);
                }

                // Close modal
                $('.zbooks-modal-close, .zbooks-modal-overlay').on('click', function() {
                    $modal.fadeOut(200);
                });

                // Close on escape key
                $(document).on('keydown', function(e) {
                    if (e.key === 'Escape' && $modal.is(':visible')) {
                        $modal.fadeOut(200);
                    }
                });

                // Copy JSON to clipboard
                $('.zbooks-copy-json').on('click', function() {
                    if (!currentEntry) return;

                    var jsonText = JSON.stringify(currentEntry, null, 2);
                    navigator.clipboard.writeText(jsonText).then(function() {
                        var $btn = $('.zbooks-copy-json');
                        var originalText = $btn.text();
                        $btn.text('<?php echo esc_js(__('Copied!', 'zbooks-for-woocommerce')); ?>');
                        setTimeout(function() {
                            $btn.text(originalText);
                        }, 1500);
                    });
                });
            });

            function zbooksRefreshLogs() {
                location.reload();
            }

            function zbooksClearLogs() {
                <?php
                $log_retention_days = absint( $this->logger->get_retention_days() );
                $confirm_message    = sprintf(
                    /* translators: %d: number of days for log retention */
                    esc_html__( 'Delete log files older than %d days?', 'zbooks-for-woocommerce' ),
                    $log_retention_days
                );
                ?>
                if (!confirm('<?php echo esc_js($confirm_message); ?>')) {
                    return;
                }

                jQuery.post(ajaxurl, {
                    action: 'zbooks_clear_logs',
                    nonce: '<?php echo esc_js(wp_create_nonce('zbooks_clear_logs')); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || 'Error clearing logs');
                    }
                });
            }
        </script>
        <?php
    }

    /**
     * AJAX handler for getting logs.
     */
    public function ajax_get_logs(): void {
        check_ajax_referer('zbooks_get_logs', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : gmdate('Y-m-d');
        $level = isset($_POST['level']) ? sanitize_text_field(wp_unslash($_POST['level'])) : '';
        $limit = isset($_POST['limit']) ? absint(wp_unslash($_POST['limit'])) : 100;

        $entries = $this->logger->read_log($date, $limit, $level);
        $stats = $this->logger->get_stats($date);

        wp_send_json_success([
            'entries' => $entries,
            'stats' => $stats,
        ]);
    }

    /**
     * AJAX handler for clearing old logs.
     */
    public function ajax_clear_logs(): void {
        check_ajax_referer('zbooks_clear_logs', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $deleted = $this->logger->clear_old_logs($this->logger->get_retention_days());

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of files deleted */
                __('Deleted %d old log file(s).', 'zbooks-for-woocommerce'),
                $deleted
            ),
            'deleted' => $deleted,
        ]);
    }
}
