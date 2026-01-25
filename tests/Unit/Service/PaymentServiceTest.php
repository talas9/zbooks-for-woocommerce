<?php
/**
 * Unit tests for PaymentService.
 *
 * @package Zbooks
 * @subpackage Tests
 */

declare(strict_types=1);

namespace Zbooks\Tests\Unit\Service;

use Zbooks\Tests\TestCase;
use Zbooks\Service\PaymentService;
use Zbooks\Api\ZohoClient;
use Zbooks\Logger\SyncLogger;
use Zbooks\Repository\PaymentMethodMappingRepository;
use WC_Order;
use Mockery;

/**
 * Test cases for PaymentService.
 */
class PaymentServiceTest extends TestCase {

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
	 * Mock payment mapping repository.
	 *
	 * @var PaymentMethodMappingRepository|\Mockery\MockInterface
	 */
	private $mock_mapping;

	/**
	 * Payment service instance.
	 *
	 * @var PaymentService
	 */
	private PaymentService $service;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->skip_if_no_woocommerce();

		$this->mock_client  = Mockery::mock( ZohoClient::class );
		$this->mock_logger  = Mockery::mock( SyncLogger::class );
		$this->mock_mapping = Mockery::mock( PaymentMethodMappingRepository::class );

		// Allow all logging calls.
		$this->mock_logger->shouldReceive( 'info' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'debug' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'warning' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'error' )->andReturnNull();

		// Default mapping behavior.
		$this->mock_mapping->shouldReceive( 'get_zoho_mode' )->andReturnNull();
		$this->mock_mapping->shouldReceive( 'get_zoho_account_id' )->andReturnNull();
		$this->mock_mapping->shouldReceive( 'get_fee_account_id' )->andReturnNull();

		$this->service = new PaymentService(
			$this->mock_client,
			$this->mock_logger,
			$this->mock_mapping
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		Mockery::close();
		parent::tear_down();
	}

	/**
	 * Test payment is applied successfully.
	 */
	public function test_apply_payment_success(): void {
		$order      = $this->create_paid_order( 100.00 );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';
		$payment_id = 'zoho_payment_789';

		// Mock: invoice not already paid.
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
						'status'     => 'sent',
					],
				]
			);

		// Mock: create payment.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'customerpayments.create';
					}
				)
			)
			->andReturn(
				[
					'payment' => [
						'payment_id'     => $payment_id,
						'payment_number' => 'PAY-001',
					],
				]
			);

		$result = $this->service->apply_payment( $order, $invoice_id, $contact_id );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $payment_id, $result['payment_id'] );
		$this->assertNull( $result['error'] );
	}

	/**
	 * Test payment skipped for zero amount orders.
	 */
	public function test_apply_payment_skips_zero_amount(): void {
		$order = $this->create_paid_order( 0.00 );

		$result = $this->service->apply_payment( $order, 'inv_123', 'contact_456' );

		$this->assertTrue( $result['success'] );
		$this->assertNull( $result['payment_id'] );
		$this->assertNull( $result['error'] );
	}

	/**
	 * Test payment skipped if invoice already paid.
	 */
	public function test_apply_payment_skips_already_paid(): void {
		$order      = $this->create_paid_order( 100.00 );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		// Mock: invoice already paid.
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
					],
				]
			);

		$result = $this->service->apply_payment( $order, $invoice_id, $contact_id );

		$this->assertTrue( $result['success'] );
		$this->assertNull( $result['payment_id'] );
	}

	/**
	 * Test payment mode mapping for PayPal.
	 */
	public function test_payment_mode_mapping_paypal(): void {
		$order      = $this->create_paid_order( 100.00, 'paypal' );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		$captured_data = null;

		// Mock: invoice status check.
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
					'invoice' => [ 'status' => 'sent' ],
				]
			);

		// Capture payment data.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_payments = Mockery::mock();
						$mock_payments->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
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

		$result = $this->service->apply_payment( $order, $invoice_id, $contact_id );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $captured_data );
		$this->assertEquals( 'PayPal', $captured_data['payment_mode'] );
	}

	/**
	 * Test payment mode mapping for Stripe.
	 */
	public function test_payment_mode_mapping_stripe(): void {
		$order      = $this->create_paid_order( 100.00, 'stripe' );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		$captured_data = null;

		// Mock: invoice status check.
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
					'invoice' => [ 'status' => 'sent' ],
				]
			);

		// Capture payment data.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_payments = Mockery::mock();
						$mock_payments->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
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

		$result = $this->service->apply_payment( $order, $invoice_id, $contact_id );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $captured_data );
		$this->assertEquals( 'Credit Card', $captured_data['payment_mode'] );
	}

	/**
	 * Test bank charges are included when account is configured.
	 */
	public function test_bank_charges_included_with_account(): void {
		$order      = $this->create_paid_order( 100.00, 'stripe' );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		// Add Stripe fee to order meta.
		$order->update_meta_data( '_stripe_fee', '2.90' );
		$order->save();

		// Mock mapping returns account ID.
		$this->mock_mapping = Mockery::mock( PaymentMethodMappingRepository::class );
		$this->mock_mapping->shouldReceive( 'get_zoho_mode' )->andReturnNull();
		$this->mock_mapping->shouldReceive( 'get_zoho_account_id' )->andReturn( 'account_123' );
		$this->mock_mapping->shouldReceive( 'get_fee_account_id' )->andReturn( 'fee_account_456' );

		$this->service = new PaymentService(
			$this->mock_client,
			$this->mock_logger,
			$this->mock_mapping
		);

		$captured_data = null;

		// Mock: invoice status check.
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
					'invoice' => [ 'status' => 'sent' ],
				]
			);

		// Capture payment data.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_payments = Mockery::mock();
						$mock_payments->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
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

		$result = $this->service->apply_payment( $order, $invoice_id, $contact_id );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $captured_data );
		$this->assertEquals( 'account_123', $captured_data['account_id'] );
		$this->assertEquals( 2.90, $captured_data['bank_charges'] );
		$this->assertEquals( 'fee_account_456', $captured_data['bank_charges_account_id'] );
	}

	/**
	 * Test payment handles API error gracefully.
	 */
	public function test_apply_payment_handles_api_error(): void {
		$order      = $this->create_paid_order( 100.00 );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		// Mock: invoice status check.
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
					'invoice' => [ 'status' => 'sent' ],
				]
			);

		// Mock: payment creation fails.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'customerpayments.create';
					}
				)
			)
			->andThrow( new \Exception( 'API Error: Invalid account' ) );

		$result = $this->service->apply_payment( $order, $invoice_id, $contact_id );

		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['payment_id'] );
		$this->assertStringContainsString( 'Invalid account', $result['error'] );
	}

	/**
	 * Test transaction ID is used as reference number.
	 */
	public function test_transaction_id_as_reference(): void {
		$order      = $this->create_paid_order( 100.00, 'stripe' );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		// Set transaction ID.
		$order->set_transaction_id( 'ch_1234567890' );
		$order->save();

		$captured_data = null;

		// Mock: invoice status check.
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
					'invoice' => [ 'status' => 'sent' ],
				]
			);

		// Capture payment data.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_payments = Mockery::mock();
						$mock_payments->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
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

		$result = $this->service->apply_payment( $order, $invoice_id, $contact_id );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $captured_data );
		$this->assertEquals( 'ch_1234567890', $captured_data['reference_number'] );
	}

	/**
	 * Test find payment by reference.
	 */
	public function test_find_payment_by_reference(): void {
		$reference  = 'ch_1234567890';
		$payment_id = 'zoho_payment_123';

		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'customerpayments.getList';
					}
				)
			)
			->andReturn(
				[
					'customerpayments' => [
						[
							'payment_id'       => $payment_id,
							'reference_number' => $reference,
						],
					],
				]
			);

		$result = $this->service->find_payment_by_reference( $reference );

		$this->assertEquals( $payment_id, $result );
	}

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
