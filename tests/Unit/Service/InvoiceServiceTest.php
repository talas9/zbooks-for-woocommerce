<?php
/**
 * Unit tests for InvoiceService.
 *
 * @package Zbooks
 * @subpackage Tests
 */

declare(strict_types=1);

namespace Zbooks\Tests\Unit\Service;

use Zbooks\Tests\TestCase;
use Zbooks\Service\InvoiceService;
use Zbooks\Api\ZohoClient;
use Zbooks\Logger\SyncLogger;
use Zbooks\Repository\ItemMappingRepository;
use Zbooks\Repository\FieldMappingRepository;
use Zbooks\Model\SyncResult;
use Zbooks\Model\SyncStatus;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;
use Mockery;

/**
 * Test cases for InvoiceService.
 */
class InvoiceServiceTest extends TestCase {

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
	 * Mock item mapping repository.
	 *
	 * @var ItemMappingRepository|\Mockery\MockInterface
	 */
	private $mock_item_mapping;

	/**
	 * Mock field mapping repository.
	 *
	 * @var FieldMappingRepository|\Mockery\MockInterface
	 */
	private $mock_field_mapping;

	/**
	 * Invoice service instance.
	 *
	 * @var InvoiceService
	 */
	private InvoiceService $service;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->skip_if_no_woocommerce();

		$this->mock_client        = Mockery::mock( ZohoClient::class );
		$this->mock_logger        = Mockery::mock( SyncLogger::class );
		$this->mock_item_mapping  = Mockery::mock( ItemMappingRepository::class );
		$this->mock_field_mapping = Mockery::mock( FieldMappingRepository::class );

		// Allow all logging calls.
		$this->mock_logger->shouldReceive( 'info' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'debug' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'warning' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'error' )->andReturnNull();

		// Default mock for field mapping.
		$this->mock_field_mapping->shouldReceive( 'build_custom_fields' )->andReturn( [] );

		$this->service = new InvoiceService(
			$this->mock_client,
			$this->mock_logger,
			$this->mock_item_mapping,
			$this->mock_field_mapping
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
	 * Test invoice creation returns success with invoice ID.
	 */
	public function test_create_invoice_returns_success_result(): void {
		$order      = $this->create_order_with_product( 100.00 );
		$contact_id = 'zoho_contact_123';
		$invoice_id = 'zoho_invoice_456';

		// Mock: no existing invoice.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.getList';
					}
				)
			)
			->andReturn( [] );

		// Mock: create invoice.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.create';
					}
				)
			)
			->andReturn(
				[
					'invoice' => [
						'invoice_id'     => $invoice_id,
						'invoice_number' => 'INV-001',
					],
				]
			);

		// Mock: mark as sent (if enabled).
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.markAsSent';
					}
				)
			)
			->andReturnNull();

		$result = $this->service->create_invoice( $order, $contact_id );

		$this->assertTrue( $result->is_success() );
		$this->assertEquals( $invoice_id, $result->get_invoice_id() );
		$this->assertEquals( $contact_id, $result->get_contact_id() );
	}

	/**
	 * Test invoice creation skips if invoice already exists.
	 */
	public function test_create_invoice_returns_existing_if_found(): void {
		$order             = $this->create_order_with_product( 100.00 );
		$contact_id        = 'zoho_contact_123';
		$existing_id       = 'existing_invoice_789';

		// Mock: find existing invoice by reference_number.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'reference_number';
					}
				)
			)
			->andReturn(
				[
					[
						'invoice_id' => $existing_id,
					],
				]
			);

		$result = $this->service->create_invoice( $order, $contact_id );

		$this->assertTrue( $result->is_success() );
		$this->assertEquals( $existing_id, $result->get_invoice_id() );
		$this->assertEquals( SyncStatus::SYNCED, $result->get_status() );
	}

	/**
	 * Test invoice creation with discount is mapped correctly.
	 */
	public function test_invoice_with_discount_maps_correctly(): void {
		$order      = $this->create_order_with_discount( 100.00, 10.00 );
		$contact_id = 'zoho_contact_123';
		$invoice_id = 'zoho_invoice_456';

		// Capture the invoice data passed to API.
		$captured_data = null;

		// Mock: no existing invoice.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.getList';
					}
				)
			)
			->andReturn( [] );

		// Mock: create invoice and capture data.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						// Execute the callable with a mock client to capture data.
						$mock_invoices = Mockery::mock();
						$mock_invoices->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
										return true;
									}
								)
							)
							->andReturn( (object) [ 'invoice_id' => 'zoho_invoice_456' ] );

						$mock_client           = Mockery::mock();
						$mock_client->invoices = $mock_invoices;

						$callable( $mock_client );
						return true;
					}
				),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.create';
					}
				)
			)
			->andReturn(
				[
					'invoice' => [ 'invoice_id' => $invoice_id ],
				]
			);

		// Mock: mark as sent.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.markAsSent';
					}
				)
			)
			->andReturnNull();

		$result = $this->service->create_invoice( $order, $contact_id );

		$this->assertTrue( $result->is_success() );
		$this->assertNotNull( $captured_data );
		$this->assertEquals( 10.0, $captured_data['discount'] );
		$this->assertEquals( 'entity_level', $captured_data['discount_type'] );
		$this->assertTrue( $captured_data['is_discount_before_tax'] );
	}

	/**
	 * Test invoice with shipping is mapped correctly.
	 */
	public function test_invoice_with_shipping_maps_correctly(): void {
		$order      = $this->create_order_with_shipping( 100.00, 15.00 );
		$contact_id = 'zoho_contact_123';
		$invoice_id = 'zoho_invoice_456';

		$captured_data = null;

		// Mock: no existing invoice.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.getList';
					}
				)
			)
			->andReturn( [] );

		// Mock: create invoice.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_invoices = Mockery::mock();
						$mock_invoices->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
										return true;
									}
								)
							)
							->andReturn( (object) [ 'invoice_id' => 'zoho_invoice_456' ] );

						$mock_client           = Mockery::mock();
						$mock_client->invoices = $mock_invoices;

						$callable( $mock_client );
						return true;
					}
				),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.create';
					}
				)
			)
			->andReturn(
				[
					'invoice' => [ 'invoice_id' => $invoice_id ],
				]
			);

		// Mock: mark as sent.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.markAsSent';
					}
				)
			)
			->andReturnNull();

		$result = $this->service->create_invoice( $order, $contact_id );

		$this->assertTrue( $result->is_success() );
		$this->assertNotNull( $captured_data );
		$this->assertEquals( 15.0, $captured_data['shipping_charge'] );
	}

	/**
	 * Test invoice creation handles API errors gracefully.
	 */
	public function test_create_invoice_handles_api_error(): void {
		$order      = $this->create_order_with_product( 100.00 );
		$contact_id = 'zoho_contact_123';

		// Mock: no existing invoice.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.getList';
					}
				)
			)
			->andReturn( [] );

		// Mock: create invoice fails.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.create';
					}
				)
			)
			->andThrow( new \Exception( 'API Error: Rate limit exceeded' ) );

		$result = $this->service->create_invoice( $order, $contact_id );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Rate limit exceeded', $result->get_error() );
	}

	/**
	 * Test line items are mapped correctly.
	 */
	public function test_line_items_mapping(): void {
		$product = $this->create_test_product(
			[
				'name'          => 'Test Widget',
				'regular_price' => '25.00',
			]
		);

		$order = $this->create_test_order();
		$order->add_product( $product, 4 ); // 4 x $25 = $100.
		$order->calculate_totals();
		$order->save();

		$contact_id = 'zoho_contact_123';
		$invoice_id = 'zoho_invoice_456';

		$captured_data = null;

		// Mock: no existing invoice.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.getList';
					}
				)
			)
			->andReturn( [] );

		// Mock item mapping returns null (no mapped Zoho item).
		$this->mock_item_mapping->shouldReceive( 'get_zoho_item_id' )->andReturnNull();

		// Mock: create invoice.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_invoices = Mockery::mock();
						$mock_invoices->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
										return true;
									}
								)
							)
							->andReturn( (object) [ 'invoice_id' => 'zoho_invoice_456' ] );

						$mock_client           = Mockery::mock();
						$mock_client->invoices = $mock_invoices;

						$callable( $mock_client );
						return true;
					}
				),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.create';
					}
				)
			)
			->andReturn(
				[
					'invoice' => [ 'invoice_id' => $invoice_id ],
				]
			);

		// Mock: mark as sent.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.markAsSent';
					}
				)
			)
			->andReturnNull();

		$result = $this->service->create_invoice( $order, $contact_id );

		$this->assertTrue( $result->is_success() );
		$this->assertNotNull( $captured_data );

		// Check line items.
		$this->assertCount( 1, $captured_data['line_items'] );
		$this->assertEquals( 'Test Widget', $captured_data['line_items'][0]['name'] );
		$this->assertEquals( 4, $captured_data['line_items'][0]['quantity'] );
		$this->assertEquals( 25.0, $captured_data['line_items'][0]['rate'] );
	}

	/**
	 * Test mapped Zoho item ID is included in line item.
	 */
	public function test_mapped_zoho_item_id_included(): void {
		$product = $this->create_test_product(
			[
				'name'          => 'Mapped Product',
				'regular_price' => '50.00',
			]
		);

		$order = $this->create_test_order();
		$order->add_product( $product, 2 );
		$order->calculate_totals();
		$order->save();

		$contact_id   = 'zoho_contact_123';
		$invoice_id   = 'zoho_invoice_456';
		$zoho_item_id = 'zoho_item_789';

		$captured_data = null;

		// Mock: no existing invoice.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.getList';
					}
				)
			)
			->andReturn( [] );

		// Mock item mapping returns a Zoho item ID.
		$this->mock_item_mapping->shouldReceive( 'get_zoho_item_id' )
			->with( $product->get_id() )
			->andReturn( $zoho_item_id );

		// Mock: create invoice.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_invoices = Mockery::mock();
						$mock_invoices->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
										return true;
									}
								)
							)
							->andReturn( (object) [ 'invoice_id' => 'zoho_invoice_456' ] );

						$mock_client           = Mockery::mock();
						$mock_client->invoices = $mock_invoices;

						$callable( $mock_client );
						return true;
					}
				),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.create';
					}
				)
			)
			->andReturn(
				[
					'invoice' => [ 'invoice_id' => $invoice_id ],
				]
			);

		// Mock: mark as sent.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.markAsSent';
					}
				)
			)
			->andReturnNull();

		$result = $this->service->create_invoice( $order, $contact_id );

		$this->assertTrue( $result->is_success() );
		$this->assertNotNull( $captured_data );
		$this->assertEquals( $zoho_item_id, $captured_data['line_items'][0]['item_id'] );
	}

	/**
	 * Test draft invoice is not marked as sent.
	 */
	public function test_draft_invoice_not_marked_as_sent(): void {
		$order      = $this->create_order_with_product( 100.00 );
		$contact_id = 'zoho_contact_123';
		$invoice_id = 'zoho_invoice_456';

		// Mock: no existing invoice.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.getList';
					}
				)
			)
			->andReturn( [] );

		// Mock: create invoice.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.create';
					}
				)
			)
			->andReturn(
				[
					'invoice' => [ 'invoice_id' => $invoice_id ],
				]
			);

		// Should NOT receive markAsSent for draft.
		$this->mock_client->shouldNotReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'invoices.markAsSent';
					}
				)
			);

		$result = $this->service->create_invoice( $order, $contact_id, true ); // as_draft = true

		$this->assertTrue( $result->is_success() );
		$this->assertEquals( SyncStatus::DRAFT, $result->get_status() );
	}

	/**
	 * Test find invoice by order number checks both reference and invoice number.
	 */
	public function test_find_invoice_by_order_number_checks_both_fields(): void {
		$order_number = '12345';

		// Mock: not found by reference_number.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'reference_number';
					}
				)
			)
			->andReturn( [] );

		// Mock: found by invoice_number.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'invoice_number';
					}
				)
			)
			->andReturn(
				[
					[
						'invoice_id' => 'found_by_invoice_num',
					],
				]
			);

		$result = $this->service->find_invoice_by_order_number( $order_number );

		$this->assertEquals( 'found_by_invoice_num', $result );
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
		$order->set_billing_first_name( 'John' );
		$order->set_billing_last_name( 'Doe' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Helper: Create order with discount.
	 *
	 * @param float $price    Product price.
	 * @param float $discount Discount amount.
	 * @return WC_Order
	 */
	private function create_order_with_discount( float $price, float $discount ): WC_Order {
		$order = $this->create_order_with_product( $price );

		// Add discount as a coupon item.
		$coupon_item = new \WC_Order_Item_Coupon();
		$coupon_item->set_code( 'TESTCOUPON' );
		$coupon_item->set_discount( $discount );
		$coupon_item->set_discount_tax( 0 );
		$order->add_item( $coupon_item );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Helper: Create order with shipping.
	 *
	 * @param float $price    Product price.
	 * @param float $shipping Shipping cost.
	 * @return WC_Order
	 */
	private function create_order_with_shipping( float $price, float $shipping ): WC_Order {
		$order = $this->create_order_with_product( $price );

		// Add shipping item.
		$shipping_item = new \WC_Order_Item_Shipping();
		$shipping_item->set_method_title( 'Flat Rate' );
		$shipping_item->set_method_id( 'flat_rate:1' );
		$shipping_item->set_total( (string) $shipping );
		$order->add_item( $shipping_item );
		$order->calculate_totals();
		$order->save();

		return $order;
	}
}
