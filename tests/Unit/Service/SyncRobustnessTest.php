<?php
/**
 * Unit tests for Sync Robustness features.
 *
 * Tests for Cases 1-12: deleted customer, invoice status sync, locks, etc.
 *
 * @package Zbooks
 * @subpackage Tests
 */

declare(strict_types=1);

namespace Zbooks\Tests\Unit\Service;

use Zbooks\Tests\TestCase;
use Zbooks\Service\CustomerService;
use Zbooks\Service\SyncOrchestrator;
use Zbooks\Service\InvoiceService;
use Zbooks\Service\PaymentService;
use Zbooks\Repository\OrderMetaRepository;
use Zbooks\Api\ZohoClient;
use Zbooks\Logger\SyncLogger;
use Zbooks\Repository\PaymentMethodMappingRepository;
use Zbooks\Repository\ItemMappingRepository;
use Zbooks\Repository\FieldMappingRepository;
use Zbooks\Service\OrderNoteService;
use WC_Order;
use Mockery;

/**
 * Test cases for Sync Robustness features.
 */
class SyncRobustnessTest extends TestCase {

	/**
	 * Mock Zoho client.
	 *
	 * @var ZohoClient|\Mockery\MockInterface
	 */
	private $mock_client;

	/**
	 * Mock logger.
	 *
	 * @var SyncLogger|\Mockery\MockInterface
	 */
	private $mock_logger;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->skip_if_no_woocommerce();

		$this->mock_client = Mockery::mock( ZohoClient::class );
		$this->mock_logger = Mockery::mock( SyncLogger::class );

		// Allow all logging calls.
		$this->mock_logger->shouldReceive( 'info' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'debug' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'warning' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'error' )->andReturnNull();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		Mockery::close();
		parent::tear_down();
	}

	// ==========================================================================
	// Case 1: Customer Verification Tests
	// ==========================================================================

	/**
	 * Test verify_contact_exists returns true for existing contact.
	 */
	public function test_verify_contact_exists_returns_true_for_existing(): void {
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'contacts.get';
					}
				)
			)
			->andReturn(
				[
					'contact' => [
						'contact_id'   => 'contact_123',
						'contact_name' => 'Test Customer',
					],
				]
			);

		$field_mapping = Mockery::mock( FieldMappingRepository::class );
		$field_mapping->shouldReceive( 'build_custom_fields' )->andReturn( [] );

		$service = new CustomerService( $this->mock_client, $this->mock_logger, $field_mapping );

		$result = $service->verify_contact_exists( 'contact_123' );

		$this->assertTrue( $result );
	}

	/**
	 * Test verify_contact_exists returns false for deleted contact.
	 */
	public function test_verify_contact_exists_returns_false_when_deleted(): void {
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'contacts.get';
					}
				)
			)
			->andThrow( new \Exception( 'Contact not found' ) );

		$field_mapping = Mockery::mock( FieldMappingRepository::class );

		$service = new CustomerService( $this->mock_client, $this->mock_logger, $field_mapping );

		$result = $service->verify_contact_exists( 'deleted_contact_123' );

		$this->assertFalse( $result );
	}

	// ==========================================================================
	// Case 5 & 6: Invoice Validation Tests
	// ==========================================================================

	/**
	 * Test payment fails for voided invoice.
	 */
	public function test_payment_fails_for_voided_invoice(): void {
		$order      = $this->create_paid_order( 100.00 );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		// Mock: invoice is void.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.get';
					}
				)
			)
			->andReturn(
				[
					'invoice' => [
						'invoice_id' => $invoice_id,
						'status'     => 'void',
					],
				]
			);

		$mock_mapping = Mockery::mock( PaymentMethodMappingRepository::class );
		$mock_mapping->shouldReceive( 'get_zoho_mode' )->andReturnNull();
		$mock_mapping->shouldReceive( 'get_zoho_account_id' )->andReturnNull();
		$mock_mapping->shouldReceive( 'get_fee_account_id' )->andReturnNull();

		$service = new PaymentService( $this->mock_client, $this->mock_logger, $mock_mapping );

		$result = $service->apply_payment( $order, $invoice_id, $contact_id );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'void', $result['error'] );
	}

	/**
	 * Test payment fails for draft invoice.
	 */
	public function test_payment_fails_for_draft_invoice(): void {
		$order      = $this->create_paid_order( 100.00 );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		// Mock: invoice is draft.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.get';
					}
				)
			)
			->andReturn(
				[
					'invoice' => [
						'invoice_id' => $invoice_id,
						'status'     => 'draft',
					],
				]
			);

		$mock_mapping = Mockery::mock( PaymentMethodMappingRepository::class );
		$mock_mapping->shouldReceive( 'get_zoho_mode' )->andReturnNull();
		$mock_mapping->shouldReceive( 'get_zoho_account_id' )->andReturnNull();
		$mock_mapping->shouldReceive( 'get_fee_account_id' )->andReturnNull();

		$service = new PaymentService( $this->mock_client, $this->mock_logger, $mock_mapping );

		$result = $service->apply_payment( $order, $invoice_id, $contact_id );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'draft', $result['error'] );
	}

	/**
	 * Test payment succeeds for already paid invoice (idempotent).
	 */
	public function test_payment_succeeds_for_already_paid_invoice(): void {
		$order      = $this->create_paid_order( 100.00 );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		// Mock: invoice is already paid.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.get';
					}
				)
			)
			->andReturn(
				[
					'invoice' => [
						'invoice_id' => $invoice_id,
						'status'     => 'paid',
						'balance'    => 0,
					],
				]
			);

		$mock_mapping = Mockery::mock( PaymentMethodMappingRepository::class );
		$mock_mapping->shouldReceive( 'get_zoho_mode' )->andReturnNull();
		$mock_mapping->shouldReceive( 'get_zoho_account_id' )->andReturnNull();
		$mock_mapping->shouldReceive( 'get_fee_account_id' )->andReturnNull();

		$service = new PaymentService( $this->mock_client, $this->mock_logger, $mock_mapping );

		$result = $service->apply_payment( $order, $invoice_id, $contact_id );

		// Should succeed without creating duplicate payment.
		$this->assertTrue( $result['success'] );
		$this->assertNull( $result['payment_id'] );
	}

	/**
	 * Test payment uses invoice balance when amounts mismatch.
	 */
	public function test_payment_uses_invoice_balance_when_mismatch(): void {
		$order      = $this->create_paid_order( 100.00 );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		$captured_amount = null;

		// Mock: invoice has different balance (partial payment already applied).
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.get';
					}
				)
			)
			->andReturn(
				[
					'invoice' => [
						'invoice_id' => $invoice_id,
						'status'     => 'partially_paid',
						'balance'    => 50.00, // Only $50 remaining.
					],
				]
			);

		// Capture payment amount.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_amount ) {
						$mock_payments = Mockery::mock();
						$mock_payments->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_amount ) {
										$captured_amount = $data['amount'];
										return true;
									}
								)
							)
							->andReturn( (object) [ 'payment_id' => 'pay_123' ] );

						$mock_client                   = Mockery::mock();
						$mock_client->customerpayments = $mock_payments;

						$callable( $mock_client );
						return true;
					}
				),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'customerpayments.create';
					}
				)
			)
			->andReturn(
				[
					'payment' => [ 'payment_id' => 'pay_123' ],
				]
			);

		$mock_mapping = Mockery::mock( PaymentMethodMappingRepository::class );
		$mock_mapping->shouldReceive( 'get_zoho_mode' )->andReturnNull();
		$mock_mapping->shouldReceive( 'get_zoho_account_id' )->andReturnNull();
		$mock_mapping->shouldReceive( 'get_fee_account_id' )->andReturnNull();

		$service = new PaymentService( $this->mock_client, $this->mock_logger, $mock_mapping );

		$result = $service->apply_payment( $order, $invoice_id, $contact_id );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 50.00, $captured_amount ); // Should use invoice balance, not order total.
	}

	// ==========================================================================
	// Case 2: Invoice Status Tests
	// ==========================================================================

	/**
	 * Test get and set invoice status.
	 */
	public function test_get_set_invoice_status(): void {
		$order      = $this->create_test_order();
		$repository = new OrderMetaRepository();

		// Initially null.
		$this->assertNull( $repository->get_invoice_status( $order ) );

		// Set status.
		$repository->set_invoice_status( $order, 'paid' );

		// Get updated order.
		$order = wc_get_order( $order->get_id() );
		$this->assertEquals( 'paid', $repository->get_invoice_status( $order ) );
	}

	// ==========================================================================
	// Case 9: Unapplied Credit Tests
	// ==========================================================================

	/**
	 * Test get and set unapplied credit.
	 */
	public function test_get_set_unapplied_credit(): void {
		$order      = $this->create_test_order();
		$repository = new OrderMetaRepository();

		// Initially null.
		$this->assertNull( $repository->get_unapplied_credit( $order ) );

		// Set unapplied credit.
		$repository->set_unapplied_credit( $order, 'credit_note_123', 'Invoice not found' );

		// Get updated order.
		$order  = wc_get_order( $order->get_id() );
		$credit = $repository->get_unapplied_credit( $order );

		$this->assertIsArray( $credit );
		$this->assertEquals( 'credit_note_123', $credit['credit_note_id'] );
		$this->assertEquals( 'Invoice not found', $credit['reason'] );

		// Clear.
		$repository->clear_unapplied_credit( $order );
		$order = wc_get_order( $order->get_id() );
		$this->assertNull( $repository->get_unapplied_credit( $order ) );
	}

	// ==========================================================================
	// Case 8: Payment Error Tracking Tests
	// ==========================================================================

	/**
	 * Test get and set payment error.
	 */
	public function test_get_set_payment_error(): void {
		$order      = $this->create_test_order();
		$repository = new OrderMetaRepository();

		// Initially null.
		$this->assertNull( $repository->get_payment_error( $order ) );

		// Set error.
		$repository->set_payment_error( $order, 'Invoice is void' );

		// Get updated order.
		$order = wc_get_order( $order->get_id() );
		$this->assertEquals( 'Invoice is void', $repository->get_payment_error( $order ) );

		// Clear.
		$repository->clear_payment_error( $order );
		$order = wc_get_order( $order->get_id() );
		$this->assertNull( $repository->get_payment_error( $order ) );
	}

	// ==========================================================================
	// Case 12: Backoff Delay Cap Tests
	// ==========================================================================

	/**
	 * Test backoff delay is capped at 24 hours.
	 */
	public function test_backoff_delay_capped_at_24_hours(): void {
		$order = $this->create_test_order();

		// Set high retry count.
		$order->update_meta_data( '_zbooks_retry_count', 20 );
		$order->save();

		$mock_customer = Mockery::mock( CustomerService::class );
		$mock_invoice  = Mockery::mock( InvoiceService::class );

		$repository = new OrderMetaRepository();

		$orchestrator = new SyncOrchestrator(
			$mock_customer,
			$mock_invoice,
			$repository,
			$this->mock_logger
		);

		$delay = $orchestrator->get_retry_delay( $order );

		// Max delay should be 24 hours (86400 seconds).
		$this->assertLessThanOrEqual( 86400, $delay );
	}

	/**
	 * Test backoff delay increases exponentially.
	 */
	public function test_backoff_delay_increases_exponentially(): void {
		$order = $this->create_test_order();

		$mock_customer = Mockery::mock( CustomerService::class );
		$mock_invoice  = Mockery::mock( InvoiceService::class );

		$repository = new OrderMetaRepository();

		$orchestrator = new SyncOrchestrator(
			$mock_customer,
			$mock_invoice,
			$repository,
			$this->mock_logger
		);

		// Set retry count to 0.
		$order->update_meta_data( '_zbooks_retry_count', 0 );
		$order->save();
		$delay_0 = $orchestrator->get_retry_delay( $order );

		// Set retry count to 1.
		$order->update_meta_data( '_zbooks_retry_count', 1 );
		$order->save();
		$delay_1 = $orchestrator->get_retry_delay( $order );

		// Set retry count to 2.
		$order->update_meta_data( '_zbooks_retry_count', 2 );
		$order->save();
		$delay_2 = $orchestrator->get_retry_delay( $order );

		// Each delay should be approximately double the previous.
		$this->assertGreaterThan( $delay_0, $delay_1 );
		$this->assertGreaterThan( $delay_1, $delay_2 );
	}

	// ==========================================================================
	// Case 4: Invoice Voiding Tests
	// ==========================================================================

	/**
	 * Test voiding an invoice.
	 */
	public function test_void_invoice(): void {
		$invoice_id = 'zoho_invoice_123';

		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.markAsVoid';
					}
				)
			)
			->andReturn( [ 'invoice' => [ 'status' => 'void' ] ] );

		$mock_item_mapping  = Mockery::mock( ItemMappingRepository::class );
		$mock_field_mapping = Mockery::mock( FieldMappingRepository::class );

		$service = new InvoiceService(
			$this->mock_client,
			$this->mock_logger,
			$mock_item_mapping,
			$mock_field_mapping
		);

		$result = $service->void_invoice( $invoice_id );

		$this->assertTrue( $result );
	}

	/**
	 * Test voiding an invoice handles API errors.
	 */
	public function test_void_invoice_handles_error(): void {
		$invoice_id = 'zoho_invoice_123';

		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.markAsVoid';
					}
				)
			)
			->andThrow( new \Exception( 'Invoice has payments applied' ) );

		$mock_item_mapping  = Mockery::mock( ItemMappingRepository::class );
		$mock_field_mapping = Mockery::mock( FieldMappingRepository::class );

		$service = new InvoiceService(
			$this->mock_client,
			$this->mock_logger,
			$mock_item_mapping,
			$mock_field_mapping
		);

		$result = $service->void_invoice( $invoice_id );

		$this->assertFalse( $result );
	}

	// ==========================================================================
	// Sync Lock Tests
	// ==========================================================================

	/**
	 * Test sync lock prevents duplicate invoice creation.
	 */
	public function test_sync_lock_prevents_duplicate_sync(): void {
		$order = $this->create_test_order();

		// Set the transient to simulate an existing lock.
		set_transient( 'zbooks_sync_lock_' . $order->get_id(), time(), 60 );

		$mock_customer = Mockery::mock( CustomerService::class );
		$mock_invoice  = Mockery::mock( InvoiceService::class );

		// These should NOT be called if lock is held.
		$mock_customer->shouldNotReceive( 'find_or_create_contact' );
		$mock_invoice->shouldNotReceive( 'create_invoice' );

		$repository = new OrderMetaRepository();

		$orchestrator = new SyncOrchestrator(
			$mock_customer,
			$mock_invoice,
			$repository,
			$this->mock_logger
		);

		$result = $orchestrator->sync_order( $order );

		// Should return pending due to lock.
		$this->assertFalse( $result->success );

		// Cleanup.
		delete_transient( 'zbooks_sync_lock_' . $order->get_id() );
	}

	// ==========================================================================
	// Helper Methods
	// ==========================================================================

	/**
	 * Helper: Create a paid order.
	 *
	 * @param float  $total          Order total.
	 * @param string $payment_method Payment method slug.
	 * @return WC_Order
	 */
	private function create_paid_order( float $total, string $payment_method = 'bacs' ): WC_Order {
		$product = $this->create_test_product(
			[
				'name'          => 'Test Product',
				'regular_price' => (string) $total,
			]
		);

		$order = $this->create_test_order();
		$order->add_product( $product, 1 );
		$order->set_billing_email( 'test@example.com' );
		$order->set_payment_method( $payment_method );
		$order->set_payment_method_title( ucfirst( $payment_method ) );
		$order->set_date_paid( new \WC_DateTime() );
		$order->calculate_totals();
		$order->save();

		return $order;
	}
}
