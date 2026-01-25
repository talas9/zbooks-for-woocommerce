<?php
/**
 * Reconciliation admin page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Service\ReconciliationService;
use Zbooks\Repository\ReconciliationRepository;

defined('ABSPATH') || exit;

/**
 * Admin page for viewing and managing reconciliation reports.
 */
class ReconciliationPage {

    /**
     * Reconciliation service.
     *
     * @var ReconciliationService
     */
    private ReconciliationService $service;

    /**
     * Repository.
     *
     * @var ReconciliationRepository
     */
    private ReconciliationRepository $repository;

    /**
     * Constructor.
     *
     * @param ReconciliationService    $service    Reconciliation service.
     * @param ReconciliationRepository $repository Repository.
     */
    public function __construct(ReconciliationService $service, ReconciliationRepository $repository) {
        $this->service = $service;
        $this->repository = $repository;

        $this->register_hooks();
    }

    /**
     * Register hooks.
     */
    private function register_hooks(): void {
        // Priority 20 ensures this runs after SettingsPage (priority 10) creates the parent menu.
        add_action('admin_menu', [$this, 'add_menu_page'], 20);
        add_action('wp_ajax_zbooks_run_reconciliation', [$this, 'ajax_run_reconciliation']);
        add_action('wp_ajax_zbooks_delete_report', [$this, 'ajax_delete_report']);
        add_action('wp_ajax_zbooks_view_report', [$this, 'ajax_view_report']);
        add_action('wp_ajax_zbooks_export_report_csv', [$this, 'ajax_export_report_csv']);
    }

    /**
     * Add submenu page under ZBooks menu.
     */
    public function add_menu_page(): void {
        add_submenu_page(
            'zbooks',
            __('Reconciliation', 'zbooks-for-woocommerce'),
            __('Reconciliation', 'zbooks-for-woocommerce'),
            'manage_woocommerce',
            'zbooks-reconciliation',
            [$this, 'render_page'],
            1 // Position: right after Settings.
        );
    }

    /**
     * Render the standalone reconciliation page.
     * Shows Run Now section and Report History.
     */
    public function render_page(): void {
        // Clean up stale "running" reports that crashed or timed out.
        $this->repository->mark_stale_reports_failed();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter.
        $paged = isset($_GET['report_page']) ? max(1, absint(wp_unslash($_GET['report_page']))) : 1;
        $reports_data = $this->repository->get_paginated($paged, 10);
        $reports = $reports_data['reports'];
        $total_pages = $reports_data['pages'];

        $latest_report = $this->repository->get_latest();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Reconciliation', 'zbooks-for-woocommerce'); ?></h1>
            <p class="description">
                <?php esc_html_e('Compare WooCommerce orders with Zoho Books invoices to identify discrepancies.', 'zbooks-for-woocommerce'); ?>
            </p>

            <div id="zbooks-reconciliation-notices"></div>

            <!-- Run Reconciliation Section -->
            <div class="zbooks-run-reconciliation" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
                <h2><?php esc_html_e('Run Reconciliation', 'zbooks-for-woocommerce'); ?></h2>

                <p class="description">
                    <?php esc_html_e('Run reconciliation for a custom date range.', 'zbooks-for-woocommerce'); ?>
                </p>

                <div style="margin: 15px 0;">
                    <label style="margin-right: 20px;">
                        <?php esc_html_e('Start Date:', 'zbooks-for-woocommerce'); ?>
                        <input type="date" id="zbooks-recon-start"
                            value="<?php echo esc_attr(gmdate('Y-m-d', strtotime('-7 days'))); ?>"
                            max="<?php echo esc_attr(gmdate('Y-m-d')); ?>">
                    </label>
                    <label style="margin-right: 20px;">
                        <?php esc_html_e('End Date:', 'zbooks-for-woocommerce'); ?>
                        <input type="date" id="zbooks-recon-end"
                            value="<?php echo esc_attr(gmdate('Y-m-d', strtotime('-1 day'))); ?>"
                            max="<?php echo esc_attr(gmdate('Y-m-d')); ?>">
                    </label>
                    <button type="button" class="button button-primary" id="zbooks-run-reconciliation">
                        <?php esc_html_e('Run Now', 'zbooks-for-woocommerce'); ?>
                    </button>
                </div>

                <div id="zbooks-reconciliation-progress" style="display: none; margin-top: 15px;">
                    <span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
                    <span><?php esc_html_e('Running reconciliation...', 'zbooks-for-woocommerce'); ?></span>
                </div>
            </div>

            <!-- Latest Report Summary -->
            <?php if ($latest_report && $latest_report->get_status() === 'completed') : ?>
                <?php $summary = $latest_report->get_summary(); ?>
                <div class="zbooks-latest-report" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
                    <h2><?php esc_html_e('Latest Report Summary', 'zbooks-for-woocommerce'); ?></h2>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: Start date, 2: End date, 3: Generated date */
                            esc_html__('Period: %1$s to %2$s | Generated: %3$s', 'zbooks-for-woocommerce'),
                            esc_html($latest_report->get_period_start()->format('Y-m-d')),
                            esc_html($latest_report->get_period_end()->format('Y-m-d')),
                            esc_html($latest_report->get_generated_at()->format('Y-m-d H:i'))
                        );
                        ?>
                    </p>

                    <?php
                    $payment_issues = ($summary['payment_mismatches'] ?? 0) + ($summary['refund_mismatches'] ?? 0);
                    $status_issues = $summary['status_mismatches'] ?? 0;
                    ?>
                    <div class="zbooks-summary-cards">
                        <div class="zbooks-card zbooks-card-success">
                            <span class="zbooks-card-value"><?php echo esc_html($summary['matched_count'] ?? 0); ?></span>
                            <span class="zbooks-card-label"><?php esc_html_e('Matched', 'zbooks-for-woocommerce'); ?></span>
                            <span class="zbooks-card-desc"><?php esc_html_e('Orders synced correctly', 'zbooks-for-woocommerce'); ?></span>
                        </div>
                        <div class="zbooks-card <?php echo ($summary['missing_in_zoho'] ?? 0) > 0 ? 'zbooks-card-danger' : 'zbooks-card-neutral'; ?>">
                            <span class="zbooks-card-value"><?php echo esc_html($summary['missing_in_zoho'] ?? 0); ?></span>
                            <span class="zbooks-card-label"><?php esc_html_e('Missing in Zoho', 'zbooks-for-woocommerce'); ?></span>
                            <span class="zbooks-card-desc"><?php esc_html_e('Orders without invoices', 'zbooks-for-woocommerce'); ?></span>
                        </div>
                        <div class="zbooks-card <?php echo ($summary['amount_mismatches'] ?? 0) > 0 ? 'zbooks-card-warning' : 'zbooks-card-neutral'; ?>">
                            <span class="zbooks-card-value"><?php echo esc_html($summary['amount_mismatches'] ?? 0); ?></span>
                            <span class="zbooks-card-label"><?php esc_html_e('Amount Mismatches', 'zbooks-for-woocommerce'); ?></span>
                            <span class="zbooks-card-desc"><?php esc_html_e('Totals don\'t match', 'zbooks-for-woocommerce'); ?></span>
                        </div>
                        <div class="zbooks-card <?php echo $payment_issues > 0 ? 'zbooks-card-warning' : 'zbooks-card-neutral'; ?>">
                            <span class="zbooks-card-value"><?php echo esc_html($payment_issues); ?></span>
                            <span class="zbooks-card-label"><?php esc_html_e('Payment Issues', 'zbooks-for-woocommerce'); ?></span>
                            <span class="zbooks-card-desc"><?php esc_html_e('Payment or refund mismatch', 'zbooks-for-woocommerce'); ?></span>
                        </div>
                        <div class="zbooks-card <?php echo $status_issues > 0 ? 'zbooks-card-info' : 'zbooks-card-neutral'; ?>">
                            <span class="zbooks-card-value"><?php echo esc_html($status_issues); ?></span>
                            <span class="zbooks-card-label"><?php esc_html_e('Status Mismatches', 'zbooks-for-woocommerce'); ?></span>
                            <span class="zbooks-card-desc"><?php esc_html_e('Invoice status differs', 'zbooks-for-woocommerce'); ?></span>
                        </div>
                        <div class="zbooks-card zbooks-card-neutral">
                            <span class="zbooks-card-value"><?php echo wp_kses_post(wc_price($summary['amount_difference'] ?? 0)); ?></span>
                            <span class="zbooks-card-label"><?php esc_html_e('Total Difference', 'zbooks-for-woocommerce'); ?></span>
                            <span class="zbooks-card-desc"><?php esc_html_e('Sum of all discrepancies', 'zbooks-for-woocommerce'); ?></span>
                        </div>
                    </div>

                    <?php if ($latest_report->has_discrepancies()) : ?>
                        <div style="margin-top: 20px;">
                            <h3><?php esc_html_e('Recent Discrepancies', 'zbooks-for-woocommerce'); ?></h3>
                            <table class="widefat striped" style="margin-top: 10px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Type', 'zbooks-for-woocommerce'); ?></th>
                                        <th><?php esc_html_e('Order', 'zbooks-for-woocommerce'); ?></th>
                                        <th><?php esc_html_e('Date', 'zbooks-for-woocommerce'); ?></th>
                                        <th><?php esc_html_e('Details', 'zbooks-for-woocommerce'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($latest_report->get_discrepancies(), 0, 5) as $discrepancy) : ?>
                                        <tr>
                                            <td>
                                                <span class="zbooks-badge zbooks-badge-<?php echo esc_attr($discrepancy['type']); ?>">
                                                    <?php echo esc_html(ucwords(str_replace('_', ' ', $discrepancy['type']))); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($discrepancy['order_id'])) : ?>
                                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $discrepancy['order_id'] . '&action=edit')); ?>">
                                                        #<?php echo esc_html($discrepancy['order_number'] ?? $discrepancy['order_id']); ?>
                                                    </a>
                                                <?php elseif (!empty($discrepancy['invoice_number'])) : ?>
                                                    <?php echo esc_html($discrepancy['invoice_number']); ?>
                                                <?php else : ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html($discrepancy['order_date'] ?? $discrepancy['invoice_date'] ?? '—'); ?></td>
                                            <td><?php echo wp_kses_post($discrepancy['message']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (count($latest_report->get_discrepancies()) > 5) : ?>
                                <p>
                                    <a href="#" class="zbooks-view-report" data-report-id="<?php echo esc_attr($latest_report->get_id()); ?>" style="text-decoration: none;">
                                        <?php
                                        printf(
                                            /* translators: %d: Number of additional discrepancies */
                                            esc_html__('+ %d more discrepancies — View All →', 'zbooks-for-woocommerce'),
                                            count($latest_report->get_discrepancies()) - 5
                                        );
                                        ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Report History -->
            <div class="zbooks-report-history" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
                <h2><?php esc_html_e('Report History', 'zbooks-for-woocommerce'); ?></h2>

                <?php if (empty($reports)) : ?>
                    <p class="description"><?php esc_html_e('No reconciliation reports yet.', 'zbooks-for-woocommerce'); ?></p>
                <?php else : ?>
                    <table class="widefat striped" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Date', 'zbooks-for-woocommerce'); ?></th>
                                <th><?php esc_html_e('Period', 'zbooks-for-woocommerce'); ?></th>
                                <th><?php esc_html_e('Status', 'zbooks-for-woocommerce'); ?></th>
                                <th><?php esc_html_e('Matched', 'zbooks-for-woocommerce'); ?></th>
                                <th><?php esc_html_e('Discrepancies', 'zbooks-for-woocommerce'); ?></th>
                                <th><?php esc_html_e('Difference', 'zbooks-for-woocommerce'); ?></th>
                                <th><?php esc_html_e('Actions', 'zbooks-for-woocommerce'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $report) : ?>
                                <?php $rpt_summary = $report->get_summary(); ?>
                                <tr data-report-id="<?php echo esc_attr($report->get_id()); ?>">
                                    <td><?php echo esc_html($report->get_generated_at()->format('Y-m-d H:i')); ?></td>
                                    <td>
                                        <?php
                                        echo esc_html(
                                            $report->get_period_start()->format('M j') . ' - ' .
                                            $report->get_period_end()->format('M j, Y')
                                        );
                                        ?>
                                    </td>
                                    <td>
                                        <span class="zbooks-status zbooks-status-<?php echo esc_attr($report->get_status()); ?>">
                                            <?php echo esc_html(ucfirst($report->get_status())); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($rpt_summary['matched_count'] ?? 0); ?></td>
                                    <td>
                                        <?php
                                        $total_disc = ($rpt_summary['missing_in_zoho'] ?? 0) + ($rpt_summary['amount_mismatches'] ?? 0);
                                        echo esc_html($total_disc);
                                        ?>
                                    </td>
                                    <td><?php echo wp_kses_post(wc_price($rpt_summary['amount_difference'] ?? 0)); ?></td>
                                    <td>
                                        <button type="button" class="button button-small zbooks-view-report"
                                            data-report-id="<?php echo esc_attr($report->get_id()); ?>">
                                            <?php esc_html_e('View', 'zbooks-for-woocommerce'); ?>
                                        </button>
                                        <button type="button" class="button button-small zbooks-export-csv"
                                            data-report-id="<?php echo esc_attr($report->get_id()); ?>">
                                            <?php esc_html_e('CSV', 'zbooks-for-woocommerce'); ?>
                                        </button>
                                        <button type="button" class="button button-small zbooks-delete-report"
                                            data-report-id="<?php echo esc_attr($report->get_id()); ?>">
                                            <?php esc_html_e('Delete', 'zbooks-for-woocommerce'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($total_pages > 1) : ?>
                        <div class="tablenav" style="margin-top: 15px;">
                            <div class="tablenav-pages">
                                <?php
                                $base_url = admin_url('admin.php?page=zbooks-reconciliation');
                                echo wp_kses_post(paginate_links([
                                    'base' => add_query_arg('report_page', '%#%', $base_url),
                                    'format' => '',
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                    'total' => $total_pages,
                                    'current' => $paged,
                                ]));
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div><!-- .wrap -->

        <?php $this->render_styles_and_scripts(); ?>
        <?php
    }

    /**
     * Render the reconciliation settings content.
     * Called by SettingsPage for the Reconciliation tab.
     */
    public function render_content(): void {
        $settings = $this->service->get_settings();

        // Handle form submission for settings.
        if (isset($_POST['zbooks_reconciliation_settings_nonce'])) {
            $this->handle_settings_save();
            $settings = $this->service->get_settings(); // Reload settings.
        }
        ?>
        <div class="zbooks-reconciliation-tab">
            <h2><?php esc_html_e('Reconciliation Settings', 'zbooks-for-woocommerce'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure automatic reconciliation schedule and notifications.', 'zbooks-for-woocommerce'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=zbooks-reconciliation')); ?>">
                    <?php esc_html_e('Go to Reconciliation Reports →', 'zbooks-for-woocommerce'); ?>
                </a>
            </p>

            <div id="zbooks-reconciliation-notices"></div>

            <!-- Settings Section -->
            <div class="zbooks-reconciliation-settings" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
                <form method="post" action="">
                    <?php wp_nonce_field('zbooks_save_reconciliation_settings', 'zbooks_reconciliation_settings_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Automatic Reconciliation', 'zbooks-for-woocommerce'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="reconciliation[enabled]" value="1"
                                        <?php checked(!empty($settings['enabled'])); ?>>
                                    <?php esc_html_e('Run reconciliation automatically on schedule', 'zbooks-for-woocommerce'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When disabled, you can still run reconciliation manually from the Reconciliation page.', 'zbooks-for-woocommerce'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Schedule Frequency', 'zbooks-for-woocommerce'); ?></th>
                            <td>
                                <select name="reconciliation[frequency]" id="zbooks-recon-frequency">
                                    <option value="daily" <?php selected($settings['frequency'] ?? 'weekly', 'daily'); ?>>
                                        <?php esc_html_e('Daily', 'zbooks-for-woocommerce'); ?>
                                    </option>
                                    <option value="weekly" <?php selected($settings['frequency'] ?? 'weekly', 'weekly'); ?>>
                                        <?php esc_html_e('Weekly', 'zbooks-for-woocommerce'); ?>
                                    </option>
                                    <option value="monthly" <?php selected($settings['frequency'] ?? 'weekly', 'monthly'); ?>>
                                        <?php esc_html_e('Monthly', 'zbooks-for-woocommerce'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr class="zbooks-weekly-option" <?php echo ($settings['frequency'] ?? 'weekly') !== 'weekly' ? 'style="display:none;"' : ''; ?>>
                            <th scope="row"><?php esc_html_e('Day of Week', 'zbooks-for-woocommerce'); ?></th>
                            <td>
                                <select name="reconciliation[day_of_week]">
                                    <?php
                                    $days = [
                                        0 => __('Sunday', 'zbooks-for-woocommerce'),
                                        1 => __('Monday', 'zbooks-for-woocommerce'),
                                        2 => __('Tuesday', 'zbooks-for-woocommerce'),
                                        3 => __('Wednesday', 'zbooks-for-woocommerce'),
                                        4 => __('Thursday', 'zbooks-for-woocommerce'),
                                        5 => __('Friday', 'zbooks-for-woocommerce'),
                                        6 => __('Saturday', 'zbooks-for-woocommerce'),
                                    ];
                                    foreach ($days as $value => $label) :
                                    ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['day_of_week'] ?? 1, $value); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr class="zbooks-monthly-option" <?php echo ($settings['frequency'] ?? 'weekly') !== 'monthly' ? 'style="display:none;"' : ''; ?>>
                            <th scope="row"><?php esc_html_e('Day of Month', 'zbooks-for-woocommerce'); ?></th>
                            <td>
                                <select name="reconciliation[day_of_month]">
                                    <?php for ($i = 1; $i <= 28; $i++) : ?>
                                        <option value="<?php echo esc_attr($i); ?>" <?php selected($settings['day_of_month'] ?? 1, $i); ?>>
                                            <?php echo esc_html($i); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Max 28 to ensure compatibility with all months.', 'zbooks-for-woocommerce'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Amount Tolerance', 'zbooks-for-woocommerce'); ?></th>
                            <td>
                                <input type="number" name="reconciliation[amount_tolerance]" step="0.01" min="0" max="10"
                                    value="<?php echo esc_attr($settings['amount_tolerance'] ?? 0.05); ?>"
                                    style="width: 80px;">
                                <p class="description">
                                    <?php esc_html_e('Ignore amount differences smaller than this value (for rounding differences).', 'zbooks-for-woocommerce'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Email Notifications', 'zbooks-for-woocommerce'); ?></th>
                            <td>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="checkbox" name="reconciliation[email_enabled]" value="1"
                                        <?php checked(!empty($settings['email_enabled'])); ?>>
                                    <?php esc_html_e('Send email report after reconciliation', 'zbooks-for-woocommerce'); ?>
                                </label>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="checkbox" name="reconciliation[email_on_discrepancy_only]" value="1"
                                        <?php checked($settings['email_on_discrepancy_only'] ?? true); ?>>
                                    <?php esc_html_e('Only send email when discrepancies are found', 'zbooks-for-woocommerce'); ?>
                                </label>
                                <input type="email" name="reconciliation[email_address]"
                                    value="<?php echo esc_attr($settings['email_address'] ?? get_option('admin_email')); ?>"
                                    style="width: 300px;"
                                    placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save Settings', 'zbooks-for-woocommerce'), 'primary', 'save_reconciliation_settings'); ?>
                </form>
            </div>
        </div><!-- .zbooks-reconciliation-tab -->

        <script>
        jQuery(document).ready(function($) {
            // Toggle frequency options.
            $('#zbooks-recon-frequency').on('change', function() {
                var frequency = $(this).val();
                $('.zbooks-weekly-option, .zbooks-monthly-option').hide();
                if (frequency === 'weekly') {
                    $('.zbooks-weekly-option').show();
                } else if (frequency === 'monthly') {
                    $('.zbooks-monthly-option').show();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render styles and scripts for the reconciliation page.
     */
    private function render_styles_and_scripts(): void {
        ?>
        <style>
            .zbooks-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .zbooks-badge-missing_in_zoho { background: #ffeaea; color: #dc3545; }
            .zbooks-badge-missing_in_wc { background: #fff3cd; color: #856404; }
            .zbooks-badge-amount_mismatch { background: #fff3cd; color: #856404; }
            .zbooks-badge-payment_mismatch { background: #fff3cd; color: #856404; }
            .zbooks-badge-refund_mismatch { background: #fff3cd; color: #856404; }
            .zbooks-badge-status_mismatch { background: #e7f3ff; color: #0056b3; }
            .zbooks-status { font-weight: 500; }
            .zbooks-status-completed { color: #28a745; }
            .zbooks-status-failed { color: #dc3545; }
            .zbooks-status-running { color: #ffc107; }
            .zbooks-status-pending { color: #6c757d; }

            /* Summary cards */
            .zbooks-summary-cards {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 16px;
                margin-top: 20px;
            }
            .zbooks-card {
                background: #fff;
                padding: 20px 16px;
                border-radius: 12px;
                text-align: center;
                border: 1px solid #e0e0e0;
                transition: all 0.3s ease;
                cursor: default;
            }
            .zbooks-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            }
            .zbooks-card-value {
                display: block;
                font-size: 32px;
                font-weight: 700;
                line-height: 1;
                margin-bottom: 8px;
            }
            .zbooks-card-label {
                display: block;
                font-size: 12px;
                color: #666;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                font-weight: 500;
            }
            .zbooks-card-desc {
                display: block;
                font-size: 11px;
                color: #999;
                margin-top: 6px;
                font-style: italic;
            }
            .zbooks-card-neutral {
                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                border-color: #dee2e6;
            }
            .zbooks-card-neutral .zbooks-card-value { color: #495057; }
            .zbooks-card-success {
                background: linear-gradient(135deg, #d4edda 0%, #b8e0c4 100%);
                border-color: #a3d9b1;
            }
            .zbooks-card-success .zbooks-card-value { color: #155724; }
            .zbooks-card-success:hover { border-color: #28a745; }
            .zbooks-card-danger {
                background: linear-gradient(135deg, #f8d7da 0%, #f1aeb5 100%);
                border-color: #f1aeb5;
            }
            .zbooks-card-danger .zbooks-card-value { color: #721c24; }
            .zbooks-card-danger:hover { border-color: #dc3545; }
            .zbooks-card-warning {
                background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
                border-color: #ffc107;
            }
            .zbooks-card-warning .zbooks-card-value { color: #856404; }
            .zbooks-card-warning:hover { border-color: #e0a800; }
            .zbooks-card-info {
                background: linear-gradient(135deg, #cfe2ff 0%, #9ec5fe 100%);
                border-color: #9ec5fe;
            }
            .zbooks-card-info .zbooks-card-value { color: #084298; }
            .zbooks-card-info:hover { border-color: #0d6efd; }

            /* Modal styles */
            .zbooks-modal {
                display: none;
                position: fixed;
                z-index: 100000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                overflow: auto;
                background-color: rgba(0,0,0,0.5);
            }
            .zbooks-modal-content {
                background-color: #fff;
                margin: 5% auto;
                padding: 20px 30px;
                border: 1px solid #ccd0d4;
                width: 80%;
                max-width: 900px;
                max-height: 80vh;
                overflow-y: auto;
                border-radius: 4px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            }
            .zbooks-modal-close {
                color: #666;
                float: right;
                font-size: 28px;
                font-weight: bold;
                cursor: pointer;
                line-height: 1;
            }
            .zbooks-modal-close:hover { color: #000; }
            .zbooks-modal h2 { margin-top: 0; }
            .zbooks-modal h3 { margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 8px; }
            .zbooks-modal-summary .summary-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 12px;
                margin-top: 15px;
            }
            .zbooks-modal-summary .summary-item {
                background: #fff;
                padding: 16px 12px;
                border-radius: 8px;
                text-align: center;
                border: 1px solid #e0e0e0;
                transition: box-shadow 0.2s, transform 0.2s;
            }
            .zbooks-modal-summary .summary-item:hover {
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                transform: translateY(-1px);
            }
            .zbooks-modal-summary .summary-item .value {
                display: block;
                font-size: 28px;
                font-weight: 700;
                line-height: 1;
                margin-bottom: 8px;
            }
            .zbooks-modal-summary .summary-item .label {
                display: block;
                font-size: 11px;
                color: #666;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .zbooks-modal-summary .summary-item .desc {
                display: block;
                font-size: 10px;
                color: #888;
                margin-top: 6px;
                font-style: italic;
            }
            .zbooks-modal-summary .summary-item.neutral { background: #f8f9fa; border-color: #dee2e6; }
            .zbooks-modal-summary .summary-item.neutral .value { color: #495057; }
            .zbooks-modal-summary .summary-item.success { background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border-color: #b1dfbb; }
            .zbooks-modal-summary .summary-item.success .value { color: #155724; }
            .zbooks-modal-summary .summary-item.danger { background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); border-color: #f1b0b7; }
            .zbooks-modal-summary .summary-item.danger .value { color: #721c24; }
            .zbooks-modal-summary .summary-item.warning { background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%); border-color: #ffc107; }
            .zbooks-modal-summary .summary-item.warning .value { color: #856404; }
            .zbooks-modal-summary .summary-item.info { background: linear-gradient(135deg, #e7f3ff 0%, #cce5ff 100%); border-color: #b8daff; }
            .zbooks-modal-summary .summary-item.info .value { color: #004085; }
            @media (max-width: 768px) {
                .zbooks-modal-summary .summary-grid { grid-template-columns: repeat(2, 1fr); }
            }
            .zbooks-modal-discrepancies { margin-top: 20px; }
            .zbooks-modal-discrepancies table { margin-top: 10px; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo esc_js(wp_create_nonce('zbooks_reconciliation')); ?>';

            // Run reconciliation.
            $('#zbooks-run-reconciliation').on('click', function() {
                var $btn = $(this);
                var $progress = $('#zbooks-reconciliation-progress');
                var startDate = $('#zbooks-recon-start').val();
                var endDate = $('#zbooks-recon-end').val();

                if (!startDate || !endDate) {
                    alert('<?php echo esc_js(__('Please select both start and end dates.', 'zbooks-for-woocommerce')); ?>');
                    return;
                }

                if (startDate > endDate) {
                    alert('<?php echo esc_js(__('Start date must be before end date.', 'zbooks-for-woocommerce')); ?>');
                    return;
                }

                $btn.prop('disabled', true);
                $progress.show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zbooks_run_reconciliation',
                        nonce: nonce,
                        start_date: startDate,
                        end_date: endDate
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php echo esc_js(__('Reconciliation failed.', 'zbooks-for-woocommerce')); ?>');
                            $btn.prop('disabled', false);
                            $progress.hide();
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error. Please try again.', 'zbooks-for-woocommerce')); ?>');
                        $btn.prop('disabled', false);
                        $progress.hide();
                    }
                });
            });

            // Delete report.
            $('.zbooks-delete-report').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this report?', 'zbooks-for-woocommerce')); ?>')) {
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
                        nonce: nonce,
                        report_id: reportId
                    },
                    success: function(response) {
                        if (response.success) {
                            $btn.closest('tr').fadeOut(function() {
                                $(this).remove();
                            });
                        } else {
                            alert(response.data.message || '<?php echo esc_js(__('Failed to delete report.', 'zbooks-for-woocommerce')); ?>');
                            $btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error. Please try again.', 'zbooks-for-woocommerce')); ?>');
                        $btn.prop('disabled', false);
                    }
                });
            });

            // Export CSV.
            $('.zbooks-export-csv').on('click', function() {
                var reportId = $(this).data('report-id');
                var url = ajaxurl + '?action=zbooks_export_report_csv&nonce=' + nonce + '&report_id=' + reportId;
                window.location.href = url;
            });

            // View report.
            $('.zbooks-view-report').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var reportId = $btn.data('report-id');

                $btn.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zbooks_view_report',
                        nonce: nonce,
                        report_id: reportId
                    },
                    success: function(response) {
                        $btn.prop('disabled', false);
                        if (response.success) {
                            showReportModal(response.data);
                        } else {
                            alert(response.data.message || '<?php echo esc_js(__('Failed to load report.', 'zbooks-for-woocommerce')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error. Please try again.', 'zbooks-for-woocommerce')); ?>');
                        $btn.prop('disabled', false);
                    }
                });
            });

            // Show report modal.
            function showReportModal(data) {
                var summary = data.summary || {};
                var paymentIssues = (summary.payment_mismatches || 0) + (summary.refund_mismatches || 0);
                var statusIssues = summary.status_mismatches || 0;
                var modalHtml = '<div id="zbooks-report-modal" class="zbooks-modal">' +
                    '<div class="zbooks-modal-content">' +
                    '<span class="zbooks-modal-close">&times;</span>' +
                    '<h2><?php echo esc_js(__('Reconciliation Report', 'zbooks-for-woocommerce')); ?></h2>' +
                    '<p><strong><?php echo esc_js(__('Period:', 'zbooks-for-woocommerce')); ?></strong> ' + data.period_start + ' - ' + data.period_end + '</p>' +
                    '<p><strong><?php echo esc_js(__('Generated:', 'zbooks-for-woocommerce')); ?></strong> ' + data.generated_at + '</p>' +
                    '<p><strong><?php echo esc_js(__('Status:', 'zbooks-for-woocommerce')); ?></strong> <span class="zbooks-status zbooks-status-' + data.status + '">' + data.status.charAt(0).toUpperCase() + data.status.slice(1) + '</span></p>' +
                    (data.error ? '<p class="error"><strong><?php echo esc_js(__('Error:', 'zbooks-for-woocommerce')); ?></strong> ' + data.error + '</p>' : '') +
                    '<div class="zbooks-modal-summary">' +
                    '<h3><?php echo esc_js(__('Summary', 'zbooks-for-woocommerce')); ?></h3>' +
                    '<div class="summary-grid">' +
                    '<div class="summary-item neutral"><span class="value">' + (summary.total_wc_orders || 0) + '</span><span class="label"><?php echo esc_js(__('WC Orders', 'zbooks-for-woocommerce')); ?></span><span class="desc"><?php echo esc_js(__('Orders in period', 'zbooks-for-woocommerce')); ?></span></div>' +
                    '<div class="summary-item neutral"><span class="value">' + (summary.total_zoho_invoices || 0) + '</span><span class="label"><?php echo esc_js(__('Zoho Invoices', 'zbooks-for-woocommerce')); ?></span><span class="desc"><?php echo esc_js(__('Invoices in period', 'zbooks-for-woocommerce')); ?></span></div>' +
                    '<div class="summary-item success"><span class="value">' + (summary.matched_count || 0) + '</span><span class="label"><?php echo esc_js(__('Matched', 'zbooks-for-woocommerce')); ?></span><span class="desc"><?php echo esc_js(__('Orders synced correctly', 'zbooks-for-woocommerce')); ?></span></div>' +
                    '<div class="summary-item ' + ((summary.missing_in_zoho || 0) > 0 ? 'danger' : 'neutral') + '"><span class="value">' + (summary.missing_in_zoho || 0) + '</span><span class="label"><?php echo esc_js(__('Missing in Zoho', 'zbooks-for-woocommerce')); ?></span><span class="desc"><?php echo esc_js(__('Orders without invoices', 'zbooks-for-woocommerce')); ?></span></div>' +
                    '<div class="summary-item ' + ((summary.amount_mismatches || 0) > 0 ? 'warning' : 'neutral') + '"><span class="value">' + (summary.amount_mismatches || 0) + '</span><span class="label"><?php echo esc_js(__('Amount Mismatches', 'zbooks-for-woocommerce')); ?></span><span class="desc"><?php echo esc_js(__("Totals don\'t match", 'zbooks-for-woocommerce')); ?></span></div>' +
                    '<div class="summary-item ' + (paymentIssues > 0 ? 'warning' : 'neutral') + '"><span class="value">' + paymentIssues + '</span><span class="label"><?php echo esc_js(__('Payment Issues', 'zbooks-for-woocommerce')); ?></span><span class="desc"><?php echo esc_js(__('Payment or refund mismatch', 'zbooks-for-woocommerce')); ?></span></div>' +
                    '<div class="summary-item ' + (statusIssues > 0 ? 'info' : 'neutral') + '"><span class="value">' + statusIssues + '</span><span class="label"><?php echo esc_js(__('Status Mismatches', 'zbooks-for-woocommerce')); ?></span><span class="desc"><?php echo esc_js(__('Invoice status differs', 'zbooks-for-woocommerce')); ?></span></div>' +
                    '<div class="summary-item neutral"><span class="value">' + (summary.missing_in_wc || 0) + '</span><span class="label"><?php echo esc_js(__('Missing in WC', 'zbooks-for-woocommerce')); ?></span><span class="desc"><?php echo esc_js(__('Invoices without orders', 'zbooks-for-woocommerce')); ?></span></div>' +
                    '</div></div>' +
                    '<div class="zbooks-modal-discrepancies">' +
                    '<h3><?php echo esc_js(__('Discrepancies', 'zbooks-for-woocommerce')); ?> (' + data.discrepancy_count + ')</h3>' +
                    data.discrepancies_html +
                    '</div>' +
                    '</div></div>';

                // Remove existing modal.
                $('#zbooks-report-modal').remove();

                // Add modal to body.
                $('body').append(modalHtml);

                // Show modal.
                $('#zbooks-report-modal').fadeIn();

                // Close on X click.
                $('.zbooks-modal-close').on('click', function() {
                    $('#zbooks-report-modal').fadeOut(function() {
                        $(this).remove();
                    });
                });

                // Close on outside click.
                $('#zbooks-report-modal').on('click', function(e) {
                    if ($(e.target).is('.zbooks-modal')) {
                        $(this).fadeOut(function() {
                            $(this).remove();
                        });
                    }
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Handle settings form submission.
     */
    private function handle_settings_save(): void {
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['zbooks_reconciliation_settings_nonce'] ?? '')),
            'zbooks_save_reconciliation_settings'
        )) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
        $input = isset($_POST['reconciliation']) ? wp_unslash($_POST['reconciliation']) : [];

        $settings = [
            'enabled' => !empty($input['enabled']),
            'frequency' => in_array($input['frequency'] ?? '', ['daily', 'weekly', 'monthly'], true)
                ? $input['frequency']
                : 'weekly',
            'day_of_week' => min(6, max(0, absint($input['day_of_week'] ?? 1))),
            'day_of_month' => min(28, max(1, absint($input['day_of_month'] ?? 1))),
            'amount_tolerance' => min(10, max(0, (float) ($input['amount_tolerance'] ?? 0.05))),
            'email_enabled' => !empty($input['email_enabled']),
            'email_on_discrepancy_only' => !empty($input['email_on_discrepancy_only']),
            'email_address' => sanitize_email($input['email_address'] ?? '') ?: get_option('admin_email'),
        ];

        update_option('zbooks_reconciliation_settings', $settings);

        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Reconciliation settings saved.', 'zbooks-for-woocommerce'); ?></p>
            </div>
            <?php
        });
    }

    /**
     * AJAX handler for running reconciliation.
     */
    public function ajax_run_reconciliation(): void {
        check_ajax_referer('zbooks_reconciliation', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $start_date = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
        $end_date = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));

        if (empty($start_date) || empty($end_date)) {
            wp_send_json_error(['message' => __('Invalid date range.', 'zbooks-for-woocommerce')]);
        }

        try {
            $start = new \DateTimeImmutable($start_date . ' 00:00:00');
            $end = new \DateTimeImmutable($end_date . ' 23:59:59');

            $report = $this->service->run($start, $end);

            // Send email if enabled.
            $this->service->send_email_notification($report);

            wp_send_json_success([
                'report_id' => $report->get_id(),
                'status' => $report->get_status(),
                'summary' => $report->get_summary(),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX handler for deleting a report.
     */
    public function ajax_delete_report(): void {
        check_ajax_referer('zbooks_reconciliation', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $report_id = absint($_POST['report_id'] ?? 0);

        if (!$report_id) {
            wp_send_json_error(['message' => __('Invalid report ID.', 'zbooks-for-woocommerce')]);
        }

        if ($this->repository->delete($report_id)) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => __('Failed to delete report.', 'zbooks-for-woocommerce')]);
        }
    }

    /**
     * AJAX handler for viewing a report.
     */
    public function ajax_view_report(): void {
        check_ajax_referer('zbooks_reconciliation', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zbooks-for-woocommerce')]);
        }

        $report_id = absint($_POST['report_id'] ?? 0);

        if (!$report_id) {
            wp_send_json_error(['message' => __('Invalid report ID.', 'zbooks-for-woocommerce')]);
        }

        $report = $this->repository->get($report_id);

        if (!$report) {
            wp_send_json_error(['message' => __('Report not found.', 'zbooks-for-woocommerce')]);
        }

        $summary = $report->get_summary();
        $discrepancies = $report->get_discrepancies();

        // Build HTML for discrepancies.
        $discrepancies_html = '';
        if (!empty($discrepancies)) {
            $discrepancies_html = '<table class="widefat striped">';
            $discrepancies_html .= '<thead><tr>';
            $discrepancies_html .= '<th>' . esc_html__('Type', 'zbooks-for-woocommerce') . '</th>';
            $discrepancies_html .= '<th>' . esc_html__('Order/Invoice', 'zbooks-for-woocommerce') . '</th>';
            $discrepancies_html .= '<th>' . esc_html__('Date', 'zbooks-for-woocommerce') . '</th>';
            $discrepancies_html .= '<th>' . esc_html__('Details', 'zbooks-for-woocommerce') . '</th>';
            $discrepancies_html .= '</tr></thead><tbody>';

            foreach ($discrepancies as $discrepancy) {
                $type_class = 'zbooks-badge zbooks-badge-' . esc_attr($discrepancy['type']);
                $type_label = ucwords(str_replace('_', ' ', $discrepancy['type']));

                $reference = '';
                if (!empty($discrepancy['order_id'])) {
                    $order_url = admin_url('post.php?post=' . $discrepancy['order_id'] . '&action=edit');
                    $reference = '<a href="' . esc_url($order_url) . '" target="_blank">#' .
                        esc_html($discrepancy['order_number'] ?? $discrepancy['order_id']) . '</a>';
                } elseif (!empty($discrepancy['invoice_number'])) {
                    $reference = esc_html($discrepancy['invoice_number']);
                }

                $date = $discrepancy['order_date'] ?? $discrepancy['invoice_date'] ?? '—';

                $discrepancies_html .= '<tr>';
                $discrepancies_html .= '<td><span class="' . esc_attr($type_class) . '">' . esc_html($type_label) . '</span></td>';
                $discrepancies_html .= '<td>' . $reference . '</td>';
                $discrepancies_html .= '<td>' . esc_html($date) . '</td>';
                $discrepancies_html .= '<td>' . wp_kses_post($discrepancy['message']) . '</td>';
                $discrepancies_html .= '</tr>';
            }

            $discrepancies_html .= '</tbody></table>';
        } else {
            $discrepancies_html = '<p>' . esc_html__('No discrepancies found. All orders match their invoices.', 'zbooks-for-woocommerce') . '</p>';
        }

        wp_send_json_success([
            'id' => $report->get_id(),
            'status' => $report->get_status(),
            'error' => $report->get_error(),
            'period_start' => $report->get_period_start()->format('Y-m-d'),
            'period_end' => $report->get_period_end()->format('Y-m-d'),
            'generated_at' => $report->get_generated_at()->format('Y-m-d H:i:s'),
            'summary' => $summary,
            'discrepancy_count' => count($discrepancies),
            'discrepancies_html' => $discrepancies_html,
        ]);
    }

    /**
     * AJAX handler for exporting a report as CSV.
     */
    public function ajax_export_report_csv(): void {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce check.
        check_ajax_referer('zbooks_reconciliation', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permission denied.', 'zbooks-for-woocommerce'));
        }

        $report_id = absint($_GET['report_id'] ?? 0);

        if (!$report_id) {
            wp_die(__('Invalid report ID.', 'zbooks-for-woocommerce'));
        }

        $report = $this->repository->get($report_id);

        if (!$report) {
            wp_die(__('Report not found.', 'zbooks-for-woocommerce'));
        }

        $summary = $report->get_summary();
        $discrepancies = $report->get_discrepancies();

        // Set headers for CSV download.
        $filename = sprintf(
            'reconciliation-report-%s-to-%s.csv',
            $report->get_period_start()->format('Y-m-d'),
            $report->get_period_end()->format('Y-m-d')
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 compatibility.
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Report header info.
        fputcsv($output, ['Reconciliation Report']);
        fputcsv($output, ['Period', $report->get_period_start()->format('Y-m-d') . ' to ' . $report->get_period_end()->format('Y-m-d')]);
        fputcsv($output, ['Generated', $report->get_generated_at()->format('Y-m-d H:i:s')]);
        fputcsv($output, ['Status', ucfirst($report->get_status())]);
        fputcsv($output, []);

        // Summary section.
        fputcsv($output, ['Summary']);
        fputcsv($output, ['WooCommerce Orders', $summary['total_wc_orders'] ?? 0]);
        fputcsv($output, ['Zoho Invoices', $summary['total_zoho_invoices'] ?? 0]);
        fputcsv($output, ['Matched', $summary['matched_count'] ?? 0]);
        fputcsv($output, ['Missing in Zoho', $summary['missing_in_zoho'] ?? 0]);
        fputcsv($output, ['Amount Mismatches', $summary['amount_mismatches'] ?? 0]);
        fputcsv($output, ['Payment Mismatches', $summary['payment_mismatches'] ?? 0]);
        fputcsv($output, ['Refund Mismatches', $summary['refund_mismatches'] ?? 0]);
        fputcsv($output, ['Status Mismatches', $summary['status_mismatches'] ?? 0]);
        fputcsv($output, ['Total Difference', $summary['amount_difference'] ?? 0]);
        fputcsv($output, []);

        // Discrepancies section.
        if (!empty($discrepancies)) {
            fputcsv($output, ['Discrepancies (' . count($discrepancies) . ')']);
            fputcsv($output, ['Type', 'Order/Invoice', 'Date', 'WC Amount', 'Zoho Amount', 'Difference', 'Details']);

            foreach ($discrepancies as $discrepancy) {
                $type = ucwords(str_replace('_', ' ', $discrepancy['type']));
                $reference = $discrepancy['order_number'] ?? $discrepancy['invoice_number'] ?? $discrepancy['order_id'] ?? '';
                $date = $discrepancy['order_date'] ?? $discrepancy['invoice_date'] ?? '';
                $wc_amount = $discrepancy['order_total'] ?? $discrepancy['order_paid'] ?? $discrepancy['wc_refund_total'] ?? '';
                $zoho_amount = $discrepancy['invoice_total'] ?? $discrepancy['invoice_paid'] ?? $discrepancy['zoho_credits'] ?? '';
                $difference = $discrepancy['difference'] ?? '';

                // Strip HTML from message.
                $message = wp_strip_all_tags($discrepancy['message'] ?? '');

                fputcsv($output, [
                    $type,
                    $reference,
                    $date,
                    $wc_amount,
                    $zoho_amount,
                    $difference,
                    $message,
                ]);
            }
        } else {
            fputcsv($output, ['No discrepancies found']);
        }

        fclose($output);
        exit;
    }
}
