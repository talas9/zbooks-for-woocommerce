<?php
/**
 * Reconciliation service.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Service;

use Zbooks\Api\ZohoClient;
use Zbooks\Logger\SyncLogger;
use Zbooks\Model\ReconciliationReport;
use Zbooks\Repository\ReconciliationRepository;
use Zbooks\Service\EmailTemplateService;

defined( 'ABSPATH' ) || exit;

/**
 * Service for reconciling WooCommerce orders with Zoho Books invoices.
 *
 * Uses an optimized approach: fetch all Zoho invoices once for the period,
 * build a lookup map, then iterate through WooCommerce orders locally.
 */
class ReconciliationService {

	/**
	 * Zoho client.
	 *
	 * @var ZohoClient
	 */
	private ZohoClient $client;

	/**
	 * Logger.
	 *
	 * @var SyncLogger
	 */
	private SyncLogger $logger;

	/**
	 * Reconciliation repository.
	 *
	 * @var ReconciliationRepository
	 */
	private ReconciliationRepository $repository;

	/**
	 * Email template service.
	 *
	 * @var EmailTemplateService|null
	 */
	private ?EmailTemplateService $email_service = null;

	/**
	 * Constructor.
	 *
	 * @param ZohoClient               $client     Zoho client.
	 * @param SyncLogger               $logger     Logger.
	 * @param ReconciliationRepository $repository Report repository.
	 */
	public function __construct(
		ZohoClient $client,
		SyncLogger $logger,
		ReconciliationRepository $repository
	) {
		$this->client     = $client;
		$this->logger     = $logger;
		$this->repository = $repository;
	}

	/**
	 * Get the email template service instance.
	 *
	 * @return EmailTemplateService
	 */
	private function get_email_service(): EmailTemplateService {
		if ( $this->email_service === null ) {
			$this->email_service = new EmailTemplateService();
		}
		return $this->email_service;
	}

	/**
	 * Get centralized notification settings.
	 *
	 * @return array
	 */
	private function get_notification_settings(): array {
		$defaults = [
			'email' => get_option( 'admin_email' ),
			'types' => [
				'reconciliation' => false,
			],
		];

		$saved = get_option( 'zbooks_notification_settings', [] );
		return array_replace_recursive( $defaults, $saved );
	}

	/**
	 * Run reconciliation for a date range.
	 *
	 * @param \DateTimeInterface $start Period start.
	 * @param \DateTimeInterface $end   Period end.
	 * @return ReconciliationReport
	 */
	public function run( \DateTimeInterface $start, \DateTimeInterface $end ): ReconciliationReport {
		$report = new ReconciliationReport( $start, $end );
		$report->set_status( 'running' );
		$this->repository->save( $report );

		$this->logger->info(
			'Starting reconciliation',
			[
				'period_start' => $start->format( 'Y-m-d' ),
				'period_end'   => $end->format( 'Y-m-d' ),
				'report_id'    => $report->get_id(),
			]
		);

		try {
			// Step 1: Fetch all Zoho invoices for the period (optimized - single batch).
			$zoho_invoices = $this->fetch_zoho_invoices( $start, $end );

			// Step 2: Fetch all Zoho payments for the period (optimized - single batch).
			// This is optional - if it fails, we continue without payment data.
			$zoho_payments = [];
			try {
				$zoho_payments = $this->fetch_zoho_payments( $start, $end );
			} catch ( \Throwable $e ) {
				$this->logger->warning(
					'Failed to fetch payments for reconciliation - continuing without payment data',
					[
						'error'   => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
						'trace'   => $e->getTraceAsString(),
					]
				);
			}

			// Step 3: Build lookup maps.
			$invoice_map = $this->build_invoice_map( $zoho_invoices );
			$payment_map = $this->build_payment_map( $zoho_payments );

			// Step 4: Get WooCommerce orders for the period.
			$wc_orders = $this->get_wc_orders( $start, $end );

			// Step 5: Compare and collect discrepancies.
			$this->compare_orders( $report, $wc_orders, $invoice_map, $zoho_invoices, $payment_map );

			// Mark as completed.
			$report->set_status( 'completed' );

			$this->logger->info(
				'Reconciliation completed',
				[
					'report_id'         => $report->get_id(),
					'summary'           => $report->get_summary(),
					'discrepancy_count' => $report->get_discrepancy_count(),
				]
			);
		} catch ( \Exception $e ) {
			$report->set_status( 'failed' );
			$report->set_error( $e->getMessage() );

			$this->logger->error(
				'Reconciliation failed',
				[
					'report_id' => $report->get_id(),
					'error'     => $e->getMessage(),
				]
			);
		}

		$this->repository->save( $report );

		return $report;
	}

	/**
	 * Fetch all Zoho invoices for a date range.
	 *
	 * Uses pagination to fetch all invoices in batches.
	 *
	 * @param \DateTimeInterface $start Period start.
	 * @param \DateTimeInterface $end   Period end.
	 * @return array Array of invoice data.
	 */
	private function fetch_zoho_invoices( \DateTimeInterface $start, \DateTimeInterface $end ): array {
		if ( ! $this->client->is_configured() ) {
			throw new \RuntimeException( __( 'Zoho Books API is not configured.', 'zbooks-for-woocommerce' ) );
		}

		$all_invoices = [];
		$page         = 1;
		$per_page     = 200;
		$has_more     = true;

		while ( $has_more ) {
			$response = $this->client->request(
				function ( $client ) use ( $start, $end, $page, $per_page ) {
					return $client->invoices->getList(
						[
							'date_start' => $start->format( 'Y-m-d' ),
							'date_end'   => $end->format( 'Y-m-d' ),
							'page'       => $page,
							'per_page'   => $per_page,
						]
					);
				},
				[
					'endpoint'   => 'invoices.getList',
					'page'       => $page,
					'date_range' => $start->format( 'Y-m-d' ) . ' to ' . $end->format( 'Y-m-d' ),
				]
			);

			// Convert response to array.
			if ( is_object( $response ) ) {
				$response = json_decode( wp_json_encode( $response ), true );
			}

			$invoices = $response['invoices'] ?? $response ?? [];

			if ( empty( $invoices ) ) {
				$has_more = false;
			} else {
				$all_invoices = array_merge( $all_invoices, $invoices );

				// Check if there are more pages.
				$page_context = $response['page_context'] ?? [];
				$has_more     = ! empty( $page_context['has_more_page'] );
				++$page;
			}

			// Safety limit to prevent infinite loops.
			if ( $page > 100 ) {
				$this->logger->warning(
					'Reconciliation reached page limit',
					[
						'pages_fetched'    => $page - 1,
						'invoices_fetched' => count( $all_invoices ),
					]
				);
				break;
			}
		}

		$this->logger->debug(
			'Fetched Zoho invoices for reconciliation',
			[
				'count' => count( $all_invoices ),
				'pages' => $page - 1,
			]
		);

		return $all_invoices;
	}

	/**
	 * Fetch all Zoho payments for the date range.
	 *
	 * @param \DateTimeInterface $start Start date.
	 * @param \DateTimeInterface $end   End date.
	 * @return array Array of payments.
	 * @throws \RuntimeException If API is not configured.
	 */
	private function fetch_zoho_payments( \DateTimeInterface $start, \DateTimeInterface $end ): array {
		if ( ! $this->client->is_configured() ) {
			throw new \RuntimeException( __( 'Zoho Books API is not configured.', 'zbooks-for-woocommerce' ) );
		}

		$all_payments = [];
		$page         = 1;
		$per_page     = 200;
		$has_more     = true;

		// Note: Zoho Books API doesn't support date filtering for payments via getList.
		// We fetch recent payments and filter in PHP.
		while ( $has_more ) {
			$response = $this->client->request(
				function ( $client ) use ( $page, $per_page ) {
					return $client->customerpayments->getList(
						[
							'page'     => $page,
							'per_page' => $per_page,
						]
					);
				},
				[
					'endpoint' => 'customerpayments.getList',
					'page'     => $page,
				]
			);

			// Convert response to array.
			if ( is_object( $response ) ) {
				$response = json_decode( wp_json_encode( $response ), true );
			}

			$payments = $response['customerpayments'] ?? $response ?? [];

			if ( empty( $payments ) ) {
				$has_more = false;
			} else {
				// Filter payments by date range.
				$start_ts = $start->getTimestamp();
				$end_ts   = $end->getTimestamp();

				foreach ( $payments as $payment ) {
					$payment_date = $payment['payment_date'] ?? '';
					if ( ! empty( $payment_date ) ) {
						$payment_ts = strtotime( $payment_date );
						if ( false !== $payment_ts && $payment_ts >= $start_ts && $payment_ts <= $end_ts ) {
							$all_payments[] = $payment;
						}
					}
				}

				// Check if there are more pages.
				$page_context = $response['page_context'] ?? [];
				$has_more     = ! empty( $page_context['has_more_page'] );
				++$page;
			}

			// Safety limit to prevent infinite loops.
			// Limit to 5 pages (1000 payments) for performance.
			if ( $page > 5 ) {
				$this->logger->warning(
					'Reconciliation reached page limit for payments',
					[
						'pages_fetched'    => $page - 1,
						'payments_fetched' => count( $all_payments ),
					]
				);
				break;
			}
		}

		$this->logger->debug(
			'Fetched Zoho payments for reconciliation',
			[
				'count' => count( $all_payments ),
				'pages' => $page - 1,
			]
		);

		return $all_payments;
	}

	/**
	 * Build a lookup map from invoices keyed by reference number.
	 *
	 * @param array $invoices Array of invoice data.
	 * @return array Map of reference_number => invoice data.
	 */
	private function build_invoice_map( array $invoices ): array {
		$map = [];

		foreach ( $invoices as $invoice ) {
			$reference = $invoice['reference_number'] ?? '';
			if ( ! empty( $reference ) ) {
				// Use reference number as key (this is the WC order number).
				$map[ $reference ] = $invoice;
			}
		}

		return $map;
	}

	/**
	 * Build payment lookup map by invoice_id.
	 *
	 * @param array $payments Array of payments.
	 * @return array Map of invoice_id => array of payments.
	 */
	private function build_payment_map( array $payments ): array {
		$map = [];

		foreach ( $payments as $payment ) {
			// Each payment can be applied to multiple invoices.
			$invoices = $payment['invoices'] ?? [];
			foreach ( $invoices as $invoice_payment ) {
				$invoice_id = $invoice_payment['invoice_id'] ?? '';
				if ( ! empty( $invoice_id ) ) {
					if ( ! isset( $map[ $invoice_id ] ) ) {
						$map[ $invoice_id ] = [];
					}
					// Store the full payment data with invoice-specific amount.
					$map[ $invoice_id ][] = array_merge( $payment, $invoice_payment );
				}
			}
		}

		return $map;
	}

	/**
	 * Get WooCommerce orders for a date range.
	 *
	 * @param \DateTimeInterface $start Period start.
	 * @param \DateTimeInterface $end   Period end.
	 * @return \WC_Order[] Array of orders.
	 */
	private function get_wc_orders( \DateTimeInterface $start, \DateTimeInterface $end ): array {
		// Get triggers to know which statuses should have been synced.
		$triggers = get_option(
			'zbooks_sync_triggers',
			[
				'sync_draft'  => 'processing',
				'sync_submit' => 'completed',
			]
		);

		// Get statuses that trigger invoice creation.
		$sync_statuses = array_filter(
			[
				$triggers['sync_draft'] ?? '',
				$triggers['sync_submit'] ?? '',
			]
		);

		// If no statuses configured, use common ones.
		if ( empty( $sync_statuses ) ) {
			$sync_statuses = [ 'processing', 'completed' ];
		}

		// Add 'wc-' prefix for query.
		$statuses = array_map(
			function ( $status ) {
				return 'wc-' . $status;
			},
			$sync_statuses
		);

		// Also include orders that have been synced regardless of current status.
		$args = [
			'date_created' => $start->format( 'Y-m-d' ) . '...' . $end->format( 'Y-m-d' ),
			'limit'        => -1,
			'return'       => 'objects',
			'status'       => $statuses,
		];

		$orders = wc_get_orders( $args );

		// Also get orders that have zoho_invoice_id meta (already synced).
		$synced_args = [
			'date_created' => $start->format( 'Y-m-d' ) . '...' . $end->format( 'Y-m-d' ),
			'limit'        => -1,
			'return'       => 'objects',
			'meta_key'     => '_zoho_invoice_id',
			'meta_compare' => 'EXISTS',
		];

		$synced_orders = wc_get_orders( $synced_args );

		// Merge and deduplicate, filtering out refunds.
		$all_orders = [];
		foreach ( $orders as $order ) {
			// Skip refunds - they don't have get_order_number() method.
			if ( $order->get_type() === 'shop_order_refund' ) {
				continue;
			}
			$all_orders[ $order->get_id() ] = $order;
		}
		foreach ( $synced_orders as $order ) {
			// Skip refunds - they don't have get_order_number() method.
			if ( $order->get_type() === 'shop_order_refund' ) {
				continue;
			}
			$all_orders[ $order->get_id() ] = $order;
		}

		return array_values( $all_orders );
	}

	/**
	 * Compare orders against invoice map and populate report.
	 *
	 * @param ReconciliationReport $report      Report to populate.
	 * @param array                $wc_orders   WooCommerce orders.
	 * @param array                $invoice_map Invoice lookup map.
	 * @param array                $all_zoho    All Zoho invoices (for orphan detection).
	 */
	private function compare_orders(
		ReconciliationReport $report,
		array $wc_orders,
		array $invoice_map,
		array $all_zoho,
		array $payment_map
	): void {
		$settings  = $this->get_settings();
		$tolerance = (float) ( $settings['amount_tolerance'] ?? 0.05 );

		$wc_total     = 0.0;
		$zoho_total   = 0.0;
		$matched_refs = [];

		$report->update_summary( 'total_wc_orders', count( $wc_orders ) );
		$report->update_summary( 'total_zoho_invoices', count( $all_zoho ) );

		foreach ( $wc_orders as $order ) {
			$order_number = $order->get_order_number();
			$order_total  = (float) $order->get_total();
			$wc_total    += $order_total;

			$zoho_invoice_id = $order->get_meta( '_zoho_invoice_id' );
			$invoice         = $invoice_map[ $order_number ] ?? null;

			// Check if order should have been synced.
			$should_sync = $this->should_have_synced( $order );

			if ( ! $invoice && ! $zoho_invoice_id ) {
				// No invoice found in Zoho.
				if ( $should_sync ) {
					$report->increment_summary( 'missing_in_zoho' );
					$report->add_discrepancy(
						[
							'type'         => 'missing_in_zoho',
							'order_id'     => $order->get_id(),
							'order_number' => $order_number,
							'order_total'  => $order_total,
							'order_status' => $order->get_status(),
							'order_date'   => $order->get_date_created()->format( 'Y-m-d' ),
							'message'      => __( 'Order not found in Zoho Books', 'zbooks-for-woocommerce' ),
						]
					);
				}
				continue;
			}

			// Invoice found - compare amounts.
			if ( $invoice ) {
				$matched_refs[ $order_number ] = true;
				$invoice_total                 = (float) ( $invoice['total'] ?? 0 );
				$zoho_total                   += $invoice_total;

				$difference = abs( $order_total - $invoice_total );

				if ( $difference > $tolerance ) {
					$report->increment_summary( 'amount_mismatches' );

					// Perform detailed breakdown to identify the source of mismatch.
					$breakdown = $this->get_detailed_breakdown( $order, $invoice, $tolerance );

					$report->add_discrepancy(
						[
							'type'           => 'amount_mismatch',
							'order_id'       => $order->get_id(),
							'order_number'   => $order_number,
							'order_status'   => $order->get_status(),
							'order_total'    => $order_total,
							'order_date'     => $order->get_date_created()->format( 'Y-m-d' ),
							'invoice_id'     => $invoice['invoice_id'] ?? '',
							'invoice_number' => $invoice['invoice_number'] ?? '',
							'invoice_status' => $invoice['status'] ?? '',
							'payment_status' => $this->get_payment_status( $order, $invoice, $payment_map ),
							'invoice_total'  => $invoice_total,
							'invoice_date'   => $invoice['date'] ?? '',
							'difference'     => $difference,
							'breakdown'      => $breakdown,
							'message'        => $this->format_breakdown_message( $order_total, $invoice_total, $difference, $breakdown, $order->get_currency() ),
						]
					);
				} else {
					$report->increment_summary( 'matched_count' );
				}

				// Check status alignment.
				$this->check_status_alignment( $report, $order, $invoice, $payment_map );

				// Check payment alignment.
				$this->check_payment_alignment( $report, $order, $invoice, $tolerance, $payment_map );
			}
		}

		// Check for orphan invoices (in Zoho but not in WC).
		foreach ( $all_zoho as $invoice ) {
			$ref = $invoice['reference_number'] ?? '';
			if ( ! empty( $ref ) && ! isset( $matched_refs[ $ref ] ) ) {
				$report->increment_summary( 'missing_in_wc' );
				$report->add_discrepancy(
					[
						'type'             => 'missing_in_wc',
						'invoice_id'       => $invoice['invoice_id'] ?? '',
						'invoice_number'   => $invoice['invoice_number'] ?? '',
						'invoice_status'   => $invoice['status'] ?? '',
						'reference_number' => $ref,
						'invoice_total'    => (float) ( $invoice['total'] ?? 0 ),
						'invoice_date'     => $invoice['date'] ?? '',
						'message'          => __( 'Invoice has no matching WooCommerce order', 'zbooks-for-woocommerce' ),
					]
				);
			}
		}

		// Update totals.
		$report->update_summary( 'wc_total_amount', round( $wc_total, 2 ) );
		$report->update_summary( 'zoho_total_amount', round( $zoho_total, 2 ) );
		$report->update_summary( 'amount_difference', round( abs( $wc_total - $zoho_total ), 2 ) );
	}

	/**
	 * Check if order should have been synced based on current triggers.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function should_have_synced( \WC_Order $order ): bool {
		$triggers = get_option(
			'zbooks_sync_triggers',
			[
				'sync_draft'  => 'processing',
				'sync_submit' => 'completed',
			]
		);

		$status        = $order->get_status();
		$sync_statuses = array_filter(
			[
				$triggers['sync_draft'] ?? '',
				$triggers['sync_submit'] ?? '',
			]
		);

		// Order should sync if it's in a sync status or has passed through one.
		// For simplicity, check completed status which usually means it went through processing.
		return in_array( $status, $sync_statuses, true )
			|| $status === 'completed'
			|| $status === 'refunded';
	}

	/**
	 * Get detailed breakdown of amounts to identify mismatch source.
	 *
	 * @param \WC_Order $order   WooCommerce order.
	 * @param array     $invoice Zoho invoice data.
	 * @param float     $tolerance Amount tolerance.
	 * @return array Breakdown of mismatches.
	 */
	private function get_detailed_breakdown( \WC_Order $order, array $invoice, float $tolerance ): array {
		$breakdown = [];

		// Subtotal (line items).
		$wc_subtotal   = (float) $order->get_subtotal();
		$zoho_subtotal = (float) ( $invoice['sub_total'] ?? 0 );
		if ( abs( $wc_subtotal - $zoho_subtotal ) > $tolerance ) {
			$breakdown['subtotal'] = [
				'wc'   => $wc_subtotal,
				'zoho' => $zoho_subtotal,
				'diff' => $wc_subtotal - $zoho_subtotal,
			];
		}

		// Shipping.
		$wc_shipping   = (float) $order->get_shipping_total();
		$zoho_shipping = (float) ( $invoice['shipping_charge'] ?? 0 );
		if ( abs( $wc_shipping - $zoho_shipping ) > $tolerance ) {
			$breakdown['shipping'] = [
				'wc'   => $wc_shipping,
				'zoho' => $zoho_shipping,
				'diff' => $wc_shipping - $zoho_shipping,
			];
		}

		// Discount.
		$wc_discount   = (float) $order->get_total_discount();
		$zoho_discount = (float) ( $invoice['discount'] ?? 0 );
		if ( abs( $wc_discount - $zoho_discount ) > $tolerance ) {
			$breakdown['discount'] = [
				'wc'   => $wc_discount,
				'zoho' => $zoho_discount,
				'diff' => $wc_discount - $zoho_discount,
			];
		}

		// Tax.
		$wc_tax   = (float) $order->get_total_tax();
		$zoho_tax = (float) ( $invoice['tax_total'] ?? 0 );
		if ( abs( $wc_tax - $zoho_tax ) > $tolerance ) {
			$breakdown['tax'] = [
				'wc'   => $wc_tax,
				'zoho' => $zoho_tax,
				'diff' => $wc_tax - $zoho_tax,
			];
		}

		// Fees (WC fees, Zoho adjustments).
		$wc_fees         = (float) $order->get_total_fees();
		$zoho_adjustment = (float) ( $invoice['adjustment'] ?? 0 );
		if ( abs( $wc_fees - $zoho_adjustment ) > $tolerance ) {
			$breakdown['fees_adjustment'] = [
				'wc'   => $wc_fees,
				'zoho' => $zoho_adjustment,
				'diff' => $wc_fees - $zoho_adjustment,
			];
		}

		return $breakdown;
	}

	/**
	 * Format breakdown into a human-readable message.
	 *
	 * @param float  $wc_total     WC total.
	 * @param float  $zoho_total   Zoho total.
	 * @param float  $difference   Total difference.
	 * @param array  $breakdown    Detailed breakdown.
	 * @param string $currency     Currency code for formatting.
	 * @return string Formatted message.
	 */
	private function format_breakdown_message( float $wc_total, float $zoho_total, float $difference, array $breakdown, string $currency ): string {
		$currency_args = [ 'currency' => $currency ];

		$message = sprintf(
			/* translators: 1: WC total, 2: Zoho total, 3: difference */
			__( 'Total mismatch: WC %1$s vs Zoho %2$s (diff: %3$s)', 'zbooks-for-woocommerce' ),
			wc_price( $wc_total, $currency_args ),
			wc_price( $zoho_total, $currency_args ),
			wc_price( $difference, $currency_args )
		);

		if ( ! empty( $breakdown ) ) {
			$details = [];
			foreach ( $breakdown as $component => $values ) {
				$label     = ucfirst( str_replace( '_', ' ', $component ) );
				$details[] = sprintf(
					'%s: WC %s vs Zoho %s',
					$label,
					wc_price( $values['wc'], $currency_args ),
					wc_price( $values['zoho'], $currency_args )
				);
			}
			$message .= ' | ' . implode( '; ', $details );
		}

		return $message;
	}

	/**
	 * Check payment alignment between order and invoice.
	 *
	 * @param ReconciliationReport $report    Report to update.
	 * @param \WC_Order            $order     WooCommerce order.
	 * @param array                $invoice   Zoho invoice data.
	 * @param float                $tolerance Amount tolerance.
	 */
	private function check_payment_alignment(
		ReconciliationReport $report,
		\WC_Order $order,
		array $invoice,
		float $tolerance,
		array $payment_map
	): void {
		// Get payment amounts.
		$order_paid = $order->is_paid() ? (float) $order->get_total() : 0.0;

		// Calculate invoice payment from available fields.
		// payment_made might not be in list API response, so calculate from total - balance.
		$invoice_total   = (float) ( $invoice['total'] ?? 0 );
		$invoice_balance = (float) ( $invoice['balance'] ?? 0 );
		$invoice_paid    = $invoice_total - $invoice_balance;

		// Check currency alignment - WooCommerce and Zoho should use same currency.
		$order_currency   = strtoupper( $order->get_currency() );
		$invoice_currency = strtoupper( $invoice['currency_code'] ?? '' );

		if ( ! empty( $invoice_currency ) && $order_currency !== $invoice_currency ) {
			// Currency mismatch between WC and Zoho - this is a problem.
			$report->increment_summary( 'payment_mismatches' );
			$report->add_discrepancy(
				[
					'type'           => 'payment_mismatch',
					'order_id'       => $order->get_id(),
					'order_number'   => $order->get_order_number(),
					'order_status'   => $order->get_status(),
					'order_paid'     => $order_paid,
					'order_date'     => $order->get_date_created()->format( 'Y-m-d' ),
					'invoice_id'     => $invoice['invoice_id'] ?? '',
					'invoice_number' => $invoice['invoice_number'] ?? '',
					'invoice_status' => $invoice['status'] ?? '',
					'payment_status' => $this->get_payment_status( $order, $invoice, $payment_map ),
					'invoice_paid'   => $invoice_paid,
					'invoice_date'   => $invoice['date'] ?? '',
					'message'        => sprintf(
						/* translators: 1: WC currency, 2: Zoho currency */
						__( 'Currency mismatch: WC uses %1$s but Zoho invoice uses %2$s', 'zbooks-for-woocommerce' ),
						$order_currency,
						$invoice_currency
					),
				]
			);
			return;
		}

		// Use gateway exchange rate from Stripe metadata (same logic as payment creation).
		$exchange_rate = $this->calculate_gateway_exchange_rate( $order );

		if ( $exchange_rate !== null && $exchange_rate > 0 ) {
			// Gateway uses different currency (e.g., Stripe in AED) - verify conversion.
			$stripe_net                = (float) $order->get_meta( '_stripe_net' );
			$stripe_fee                = (float) $order->get_meta( '_stripe_fee' );
			$total_in_gateway_currency = $stripe_net + $stripe_fee;

			if ( $total_in_gateway_currency > 0 ) {
				// Convert gateway amount to order currency for comparison.
				$order_paid = round( $total_in_gateway_currency / $exchange_rate, 2 );
			}
		}

		// Check for payment mismatches.
		if ( $order->is_paid() && abs( $order_paid - $invoice_paid ) > $tolerance ) {
			$report->increment_summary( 'payment_mismatches' );
			$report->add_discrepancy(
				[
					'type'           => 'payment_mismatch',
					'order_id'       => $order->get_id(),
					'order_number'   => $order->get_order_number(),
					'order_status'   => $order->get_status(),
					'order_paid'     => $order_paid,
					'order_date'     => $order->get_date_created()->format( 'Y-m-d' ),
					'invoice_id'     => $invoice['invoice_id'] ?? '',
					'invoice_number' => $invoice['invoice_number'] ?? '',
					'invoice_status' => $invoice['status'] ?? '',
					'payment_status' => $this->get_payment_status( $order, $invoice, $payment_map ),
					'invoice_paid'   => $invoice_paid,
					'invoice_date'   => $invoice['date'] ?? '',
					'message'        => sprintf(
						/* translators: 1: WC paid amount, 2: Zoho paid amount */
						__( 'Payment mismatch: WC received %1$s vs Zoho received %2$s', 'zbooks-for-woocommerce' ),
						wc_price( $order_paid, [ 'currency' => $order_currency ] ),
						wc_price( $invoice_paid, [ 'currency' => $order_currency ] )
					),
				]
			);
		}

		// Check refunds.
		$wc_refunds      = $order->get_refunds();
		$wc_refund_total = 0.0;
		foreach ( $wc_refunds as $refund ) {
			$wc_refund_total += abs( (float) $refund->get_total() );
		}

		// Zoho credit notes / refunds (balance due can indicate refund).
		$invoice_balance = (float) ( $invoice['balance'] ?? 0 );
		$zoho_credits    = (float) ( $invoice['credits_applied'] ?? 0 );

		// If WC has refunds but Zoho doesn't show credits applied.
		if ( $wc_refund_total > $tolerance && $zoho_credits < $tolerance ) {
			$report->increment_summary( 'refund_mismatches' );
			$report->add_discrepancy(
				[
					'type'            => 'refund_mismatch',
					'order_id'        => $order->get_id(),
					'order_number'    => $order->get_order_number(),
					'order_status'    => $order->get_status(),
					'order_date'      => $order->get_date_created()->format( 'Y-m-d' ),
					'wc_refund_total' => $wc_refund_total,
					'zoho_credits'    => $zoho_credits,
					'invoice_id'      => $invoice['invoice_id'] ?? '',
					'invoice_number'  => $invoice['invoice_number'] ?? '',
					'invoice_status'  => $invoice['status'] ?? '',
					'payment_status'  => $this->get_payment_status( $order, $invoice, $payment_map ),
					'invoice_date'    => $invoice['date'] ?? '',
					'message'         => sprintf(
						/* translators: 1: WC refund amount, 2: Zoho credits amount */
						__( 'Refund mismatch: WC refunded %1$s but Zoho shows %2$s credits', 'zbooks-for-woocommerce' ),
						wc_price( $wc_refund_total, [ 'currency' => $order_currency ] ),
						wc_price( $zoho_credits, [ 'currency' => $order_currency ] )
					),
				]
			);
		}
	}

	/**
	 * Get payment status for display in reconciliation.
	 *
	 * @param \WC_Order $order       Order object.
	 * @param array     $invoice     Invoice data.
	 * @param array     $payment_map Map of invoice_id => array of payments.
	 * @return string Payment status.
	 */
	private function get_payment_status( \WC_Order $order, array $invoice, array $payment_map ): string {
		// Check if order is refunded.
		$order_status = $order->get_status();
		if ( $order_status === 'refunded' ) {
			// Check for credit notes.
			$credits_applied = (float) ( $invoice['credits_applied'] ?? 0 );
			if ( $credits_applied > 0 ) {
				return 'credit_note_applied';
			}
			return 'credit_note_pending';
		}

		// Check for draft payments first.
		$invoice_id = $invoice['invoice_id'] ?? '';
		if ( ! empty( $invoice_id ) && isset( $payment_map[ $invoice_id ] ) ) {
			$payments = $payment_map[ $invoice_id ];
			foreach ( $payments as $payment ) {
				// Check if payment has draft status.
				if ( isset( $payment['payment_mode'] ) && strtolower( $payment['payment_mode'] ) === 'draft' ) {
					return 'draft';
				}
			}
		}

		// For non-refunded orders, check payment status.
		$invoice_total   = (float) ( $invoice['total'] ?? 0 );
		$invoice_balance = (float) ( $invoice['balance'] ?? 0 );
		$invoice_paid    = $invoice_total - $invoice_balance;

		if ( $invoice_balance <= 0.01 ) {
			return 'paid';
		}

		if ( $invoice_paid > 0 ) {
			return 'partially_paid';
		}

		return 'unpaid';
	}

	/**
	 * Calculate exchange rate from gateway data.
	 *
	 * Same logic as PaymentService - uses Stripe metadata to determine
	 * the exchange rate when gateway currency differs from order currency.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return float|null Exchange rate (gateway_currency per order_currency), or null if cannot calculate.
	 */
	private function calculate_gateway_exchange_rate( \WC_Order $order ): ?float {
		$order_total = (float) $order->get_total();
		if ( $order_total <= 0 ) {
			return null;
		}

		// Get Stripe metadata.
		$stripe_net = $order->get_meta( '_stripe_net' );
		$stripe_fee = $order->get_meta( '_stripe_fee' );

		if ( ! empty( $stripe_net ) && is_numeric( $stripe_net ) ) {
			$net                       = (float) $stripe_net;
			$fee                       = ! empty( $stripe_fee ) && is_numeric( $stripe_fee ) ? (float) $stripe_fee : 0.0;
			$total_in_gateway_currency = $net + $fee;

			if ( $total_in_gateway_currency > 0 ) {
				return $total_in_gateway_currency / $order_total;
			}
		}

		return null;
	}

	/**
	 * Check status alignment between order and invoice.
	 *
	 * @param ReconciliationReport $report      Report to update.
	 * @param \WC_Order            $order       WooCommerce order.
	 * @param array                $invoice     Zoho invoice data.
	 * @param array                $payment_map Map of invoice_id => array of payments.
	 */
	private function check_status_alignment( ReconciliationReport $report, \WC_Order $order, array $invoice, array $payment_map ): void {
		$order_status   = $order->get_status();
		$invoice_status = strtolower( $invoice['status'] ?? '' );

		// Map expected statuses.
		$status_map = [
			'completed'  => [ 'paid', 'partially_paid' ],
			'processing' => [ 'draft', 'sent', 'overdue' ],
			'refunded'   => [ 'void' ],
		];

		$expected_statuses = $status_map[ $order_status ] ?? [];

		// Only flag if order is completed but invoice is not paid.
		if ( $order_status === 'completed' && ! in_array( $invoice_status, [ 'paid', 'partially_paid' ], true ) ) {
			$report->increment_summary( 'status_mismatches' );
			$report->add_discrepancy(
				[
					'type'           => 'status_mismatch',
					'order_id'       => $order->get_id(),
					'order_number'   => $order->get_order_number(),
					'order_status'   => $order_status,
					'order_date'     => $order->get_date_created()->format( 'Y-m-d' ),
					'invoice_id'     => $invoice['invoice_id'] ?? '',
					'invoice_number' => $invoice['invoice_number'] ?? '',
					'invoice_status' => $invoice_status,
					'payment_status' => $this->get_payment_status( $order, $invoice, $payment_map ),
					'invoice_date'   => $invoice['date'] ?? '',
					'message'        => sprintf(
						/* translators: 1: Invoice status, 2: Order status */
						__( 'Invoice is %1$s but order is %2$s', 'zbooks-for-woocommerce' ),
						$invoice_status,
						$order_status
					),
				]
			);
		}
	}

	/**
	 * Get reconciliation settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		return get_option(
			'zbooks_reconciliation_settings',
			[
				'enabled'                   => false,
				'frequency'                 => 'weekly',
				'day_of_week'               => 1, // Monday.
				'day_of_month'              => 1,
				'amount_tolerance'          => 0.05,
				'email_enabled'             => false,
				'email_address'             => get_option( 'admin_email' ),
				'email_on_discrepancy_only' => true,
			]
		);
	}

	/**
	 * Send reconciliation report email.
	 *
	 * @param ReconciliationReport $report Report to send.
	 * @return bool True if email sent.
	 */
	public function send_email_notification( ReconciliationReport $report ): bool {
		// Check centralized notification settings first.
		$notification_settings = $this->get_notification_settings();

		if ( empty( $notification_settings['types']['reconciliation'] ) ) {
			return false;
		}

		// Get reconciliation-specific settings for discrepancy-only logic.
		$recon_settings = $this->get_settings();

		// Skip if no discrepancies and set to only email on discrepancy.
		if ( ! empty( $recon_settings['email_on_discrepancy_only'] ) && ! $report->has_discrepancies() ) {
			return false;
		}

		// Use centralized email address.
		$to = $notification_settings['email'] ?? '';
		if ( empty( $to ) || ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		if ( empty( $to ) || ! is_email( $to ) ) {
			return false;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = $report->has_discrepancies()
			? sprintf(
				/* translators: 1: Site name, 2: Number of discrepancies */
				__( '[%1$s] Reconciliation Report: %2$d discrepancies found', 'zbooks-for-woocommerce' ),
				$site_name,
				$report->get_discrepancy_count()
			)
			: sprintf(
				/* translators: %s: Site name */
				__( '[%s] Reconciliation Report: All matched', 'zbooks-for-woocommerce' ),
				$site_name
			);

		// Use EmailTemplateService for professional template.
		$report_url = admin_url( 'admin.php?page=zbooks-reconciliation&report_id=' . $report->get_id() );
		$message    = $this->get_email_service()->build_reconciliation_email(
			$report->get_summary(),
			$report->get_discrepancies(),
			$report->get_period_start()->format( 'Y-m-d' ),
			$report->get_period_end()->format( 'Y-m-d' ),
			$report_url
		);

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$sent = wp_mail( $to, $subject, $message, $headers );

		if ( $sent ) {
			$this->logger->info(
				'Reconciliation email sent',
				[
					'report_id' => $report->get_id(),
					'to'        => $to,
				]
			);
		} else {
			$this->logger->error(
				'Failed to send reconciliation email',
				[
					'report_id' => $report->get_id(),
					'to'        => $to,
				]
			);
		}

		return $sent;
	}

	/**
	 * Calculate the next scheduled run date based on settings.
	 *
	 * @return \DateTimeInterface|null Next run date or null if disabled.
	 */
	public function get_next_scheduled_run(): ?\DateTimeInterface {
		$settings = $this->get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return null;
		}

		$now       = new \DateTimeImmutable();
		$frequency = $settings['frequency'] ?? 'weekly';

		switch ( $frequency ) {
			case 'daily':
				return $now->modify( 'tomorrow 02:00' );

			case 'weekly':
				$day_of_week = (int) ( $settings['day_of_week'] ?? 1 );
				$days        = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];
				$target_day  = $days[ $day_of_week ] ?? 'Monday';
				return $now->modify( "next {$target_day} 02:00" );

			case 'monthly':
				$day_of_month = min( 28, max( 1, (int) ( $settings['day_of_month'] ?? 1 ) ) );
				$next_month   = $now->modify( 'first day of next month' )->setTime( 2, 0 );
				return $next_month->modify( '+' . ( $day_of_month - 1 ) . ' days' );

			default:
				return null;
		}
	}

	/**
	 * Get the period dates for the next scheduled reconciliation.
	 *
	 * @return array{start: \DateTimeInterface, end: \DateTimeInterface}
	 */
	public function get_reconciliation_period(): array {
		$settings  = $this->get_settings();
		$frequency = $settings['frequency'] ?? 'weekly';

		$end = new \DateTimeImmutable( 'yesterday 23:59:59' );

		switch ( $frequency ) {
			case 'daily':
				$start = new \DateTimeImmutable( 'yesterday 00:00:00' );
				break;

			case 'weekly':
				$start = $end->modify( '-6 days' )->setTime( 0, 0, 0 );
				break;

			case 'monthly':
				$start = $end->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				break;

			default:
				$start = $end->modify( '-6 days' )->setTime( 0, 0, 0 );
		}

		return [
			'start' => $start,
			'end'   => $end,
		];
	}
}
