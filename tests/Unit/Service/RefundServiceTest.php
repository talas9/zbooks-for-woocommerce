<?php
/**
 * Unit tests for RefundService.
 *
 * @package Zbooks
 * @subpackage Tests
 */

declare(strict_types=1);

namespace Zbooks\Tests\Unit\Service;

use Zbooks\Tests\TestCase;
use Zbooks\Service\RefundService;
use Zbooks\Api\ZohoClient;
use Zbooks\Logger\SyncLogger;
use Zbooks\Repository\FieldMappingRepository;
use Zbooks\Repository\OrderMetaRepository;
use WC_Order;
use WC_Order_Refund;
use Mockery;

/**
 * Test cases for RefundService.
 */
class RefundServiceTest extends TestCase {

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
	 * Mock field mapping repository.
	 *
	 * @var FieldMappingRepository|\Mockery\MockInterface
	 */
	private $mock_field_mapping;

	/**
	 * Mock order meta repository.
	 *
	 * @var OrderMetaRepository|\Mockery\MockInterface
	 */
	private $mock_order_meta;

	/**
	 * Refund service instance.
	 *
	 * @var RefundService
	 */
	private RefundService $service;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->skip_if_no_woocommerce();

		$this->mock_client        = Mockery::mock( ZohoClient::class );
		$this->mock_logger        = Mockery::mock( SyncLogger::class );
		$this->mock_field_mapping = Mockery::mock( FieldMappingRepository::class );
		$this->mock_order_meta    = Mockery::mock( OrderMetaRepository::class );

		// Allow all logging calls.
		$this->mock_logger->shouldReceive( 'info' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'debug' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'warning' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'error' )->andReturnNull();

		// Default mock for field mapping.
		$this->mock_field_mapping->shouldReceive( 'build_custom_fields' )->andReturn( [] );

		// Default mock for order meta.
		$this->mock_order_meta->shouldReceive( 'get_credit_note_id' )->andReturnNull();
		$this->mock_order_meta->shouldReceive( 'set_credit_note_id' )->andReturnNull();
		$this->mock_order_meta->shouldReceive( 'set_credit_note_number' )->andReturnNull();
		$this->mock_order_meta->shouldReceive( 'set_refund_id' )->andReturnNull();

		$this->service = new RefundService(
			$this->mock_client,
			$this->mock_logger,
			$this->mock_field_mapping,
			$this->mock_order_meta
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
	 * Test process refund creates credit note successfully.
	 */
	public function test_process_refund_creates_credit_note(): void {
		$order        = $this->create_order_with_product( 100.00 );
		$refund       = $this->create_refund( $order, 25.00, 'Customer request' );
		$invoice_id   = 'zoho_invoice_123';
		$contact_id   = 'zoho_contact_456';
		$credit_note_id = 'zoho_cn_789';

		// Mock: create credit note.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'creditnotes.create';
					}
				)
			)
			->andReturn(
				[
					'creditnote' => [
						'creditnote_id'     => $credit_note_id,
						'creditnote_number' => 'CN-001',
					],
				]
			);

		// Mock: get invoice status (for applying credit).
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
						'status'     => 'sent',
					],
				]
			);

		// Mock: apply credit to invoice.
		$this->mock_client->shouldReceive( 'raw_request' )
			->once()
			->with(
				'POST',
				'/invoices/' . $invoice_id . '/credits',
				Mockery::type( 'array' )
			)
			->andReturn( [ 'code' => 0 ] );

		// Disable cash refund for this test.
		update_option( 'zbooks_refund_settings', [ 'create_cash_refund' => false ] );

		$result = $this->service->process_refund( $order, $refund, $invoice_id, $contact_id );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $credit_note_id, $result['credit_note_id'] );
		$this->assertEquals( 'CN-001', $result['credit_note_number'] );
		$this->assertNull( $result['error'] );
	}

	/**
	 * Test refund skips zero amount.
	 */
	public function test_process_refund_skips_zero_amount(): void {
		$order  = $this->create_order_with_product( 100.00 );
		$refund = $this->create_refund( $order, 0.00, 'Test' );

		$result = $this->service->process_refund( $order, $refund, 'inv_123', 'contact_456' );

		$this->assertTrue( $result['success'] );
		$this->assertNull( $result['credit_note_id'] );
		$this->assertNull( $result['error'] );
	}

	/**
	 * Test refund with reason is included in credit note.
	 */
	public function test_refund_reason_included(): void {
		$order        = $this->create_order_with_product( 100.00 );
		$refund       = $this->create_refund( $order, 25.00, 'Damaged product received' );
		$invoice_id   = 'zoho_invoice_123';
		$contact_id   = 'zoho_contact_456';

		$captured_data = null;

		// Capture credit note data.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_creditnotes = Mockery::mock();
						$mock_creditnotes->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
										return true;
									}
								)
							)
							->andReturn( (object) [ 'creditnote_id' => 'cn_123' ] );

						$mock_client              = Mockery::mock();
						$mock_client->creditnotes = $mock_creditnotes;

						$callable( $mock_client );
						return true;
					}
				),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'creditnotes.create';
					}
				)
			)
			->andReturn(
				[
					'creditnote' => [ 'creditnote_id' => 'cn_123' ],
				]
			);

		// Mock: invoice status.
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

		// Mock: apply credit.
		$this->mock_client->shouldReceive( 'raw_request' )
			->with( 'POST', Mockery::type( 'string' ), Mockery::type( 'array' ) )
			->andReturn( [ 'code' => 0 ] );

		update_option( 'zbooks_refund_settings', [ 'create_cash_refund' => false ] );

		$result = $this->service->process_refund( $order, $refund, $invoice_id, $contact_id );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $captured_data );
		$this->assertEquals( 'Damaged product received', $captured_data['notes'] );
	}

	/**
	 * Test credit note cannot be applied to draft invoice.
	 */
	public function test_credit_skipped_for_draft_invoice(): void {
		$order      = $this->create_order_with_product( 100.00 );
		$refund     = $this->create_refund( $order, 25.00, 'Test' );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		// Mock: create credit note.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'creditnotes.create';
					}
				)
			)
			->andReturn(
				[
					'creditnote' => [
						'creditnote_id'     => 'cn_123',
						'creditnote_number' => 'CN-001',
					],
				]
			);

		// Mock: invoice is draft.
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
						'status'     => 'draft',
					],
				]
			);

		// raw_request should NOT be called since invoice is draft.
		$this->mock_client->shouldNotReceive( 'raw_request' );

		update_option( 'zbooks_refund_settings', [ 'create_cash_refund' => false ] );

		$result = $this->service->process_refund( $order, $refund, $invoice_id, $contact_id );

		// Credit note is still created, just not applied.
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'cn_123', $result['credit_note_id'] );
	}

	/**
	 * Test cash refund is created when enabled.
	 */
	public function test_cash_refund_created_when_enabled(): void {
		$order        = $this->create_order_with_product( 100.00 );
		$refund       = $this->create_refund( $order, 25.00, 'Test' );
		$invoice_id   = 'zoho_invoice_123';
		$contact_id   = 'zoho_contact_456';
		$credit_note_id = 'zoho_cn_789';
		$refund_id    = 'zoho_refund_101';

		// Enable cash refund and set account.
		update_option(
			'zbooks_refund_settings',
			[
				'create_cash_refund' => true,
				'refund_account_id'  => 'account_123',
			]
		);

		// Mock: create credit note.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'creditnotes.create';
					}
				)
			)
			->andReturn(
				[
					'creditnote' => [ 'creditnote_id' => $credit_note_id ],
				]
			);

		// Mock: invoice status.
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

		// Mock: apply credit.
		$this->mock_client->shouldReceive( 'raw_request' )
			->with( 'POST', '/invoices/' . $invoice_id . '/credits', Mockery::type( 'array' ) )
			->andReturn( [ 'code' => 0 ] );

		// Mock: create cash refund.
		$this->mock_client->shouldReceive( 'raw_request' )
			->once()
			->with( 'POST', '/creditnotes/' . $credit_note_id . '/refunds', Mockery::type( 'array' ) )
			->andReturn(
				[
					'creditnote_refund' => [
						'creditnote_refund_id' => $refund_id,
					],
				]
			);

		$result = $this->service->process_refund( $order, $refund, $invoice_id, $contact_id );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $credit_note_id, $result['credit_note_id'] );
		$this->assertEquals( $refund_id, $result['refund_id'] );
	}

	/**
	 * Test refund handles API error gracefully.
	 */
	public function test_process_refund_handles_api_error(): void {
		$order      = $this->create_order_with_product( 100.00 );
		$refund     = $this->create_refund( $order, 25.00, 'Test' );
		$invoice_id = 'zoho_invoice_123';
		$contact_id = 'zoho_contact_456';

		// Mock: credit note creation fails.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'creditnotes.create';
					}
				)
			)
			->andThrow( new \Exception( 'API Error: Invalid customer' ) );

		$result = $this->service->process_refund( $order, $refund, $invoice_id, $contact_id );

		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['credit_note_id'] );
		$this->assertStringContainsString( 'Invalid customer', $result['error'] );
	}

	/**
	 * Test void credit note.
	 */
	public function test_void_credit_note(): void {
		$credit_note_id = 'cn_123';

		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'creditnotes.void';
					}
				)
			)
			->andReturnNull();

		$result = $this->service->void_credit_note( $credit_note_id );

		$this->assertTrue( $result );
	}

	/**
	 * Test get credit note.
	 */
	public function test_get_credit_note(): void {
		$credit_note_id = 'cn_123';

		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'creditnotes.get';
					}
				)
			)
			->andReturn(
				[
					'credit_note' => [
						'creditnote_id'     => $credit_note_id,
						'creditnote_number' => 'CN-001',
						'total'             => 25.00,
					],
				]
			);

		$result = $this->service->get_credit_note( $credit_note_id );

		$this->assertNotNull( $result );
		$this->assertEquals( $credit_note_id, $result['creditnote_id'] );
	}

	/**
	 * Helper: Create order with a product.
	 *
	 * @param float $price Product price.
	 * @return WC_Order
	 */
	private function create_order_with_product( float $price ): WC_Order {
		$product = $this->create_test_product(
			[
				'name'          => 'Test Product',
				'regular_price' => (string) $price,
			]
		);

		$order = $this->create_test_order();
		$order->add_product( $product, 1 );
		$order->set_billing_email( 'test@example.com' );
		$order->set_payment_method( 'stripe' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Helper: Create a refund for an order.
	 *
	 * @param WC_Order $order  Parent order.
	 * @param float    $amount Refund amount.
	 * @param string   $reason Refund reason.
	 * @return WC_Order_Refund
	 */
	private function create_refund( WC_Order $order, float $amount, string $reason ): WC_Order_Refund {
		$refund = wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => $reason,
				'refund_payment' => false,
			]
		);

		if ( is_wp_error( $refund ) ) {
			throw new \RuntimeException( 'Failed to create refund: ' . $refund->get_error_message() );
		}

		return $refund;
	}
}
