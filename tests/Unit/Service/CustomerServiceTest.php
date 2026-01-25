<?php
/**
 * Unit tests for CustomerService.
 *
 * @package Zbooks
 * @subpackage Tests
 */

declare(strict_types=1);

namespace Zbooks\Tests\Unit\Service;

use Zbooks\Tests\TestCase;
use Zbooks\Service\CustomerService;
use Zbooks\Api\ZohoClient;
use Zbooks\Logger\SyncLogger;
use Zbooks\Repository\FieldMappingRepository;
use WC_Order;
use Mockery;

/**
 * Test cases for CustomerService.
 */
class CustomerServiceTest extends TestCase {

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
	 * Customer service instance.
	 *
	 * @var CustomerService
	 */
	private CustomerService $service;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->skip_if_no_woocommerce();

		$this->mock_client        = Mockery::mock( ZohoClient::class );
		$this->mock_logger        = Mockery::mock( SyncLogger::class );
		$this->mock_field_mapping = Mockery::mock( FieldMappingRepository::class );

		// Allow all logging calls.
		$this->mock_logger->shouldReceive( 'info' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'debug' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'warning' )->andReturnNull();
		$this->mock_logger->shouldReceive( 'error' )->andReturnNull();

		// Default mock for field mapping.
		$this->mock_field_mapping->shouldReceive( 'build_custom_fields' )->andReturn( [] );

		$this->service = new CustomerService(
			$this->mock_client,
			$this->mock_logger,
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
	 * Test find or create returns existing contact.
	 */
	public function test_find_or_create_returns_existing_contact(): void {
		$order      = $this->create_order_with_billing( 'john@example.com', 'John', 'Doe' );
		$contact_id = 'zoho_contact_123';

		// Mock: find contact by email - return list.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'email';
					}
				)
			)
			->andReturn(
				[
					'contacts' => [
						[
							'contact_id'    => $contact_id,
							'email'         => 'john@example.com',
							'currency_code' => 'USD',
						],
					],
				]
			);

		// Mock: get full contact details.
		$this->mock_client->shouldReceive( 'request' )
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
						'contact_id'    => $contact_id,
						'contact_name'  => 'John Doe',
						'email'         => 'john@example.com',
						'currency_code' => 'USD',
					],
				]
			);

		$result = $this->service->find_or_create_contact( $order );

		$this->assertEquals( $contact_id, $result );
	}

	/**
	 * Test find or create creates new contact when not found.
	 */
	public function test_find_or_create_creates_new_contact(): void {
		$order         = $this->create_order_with_billing( 'new@example.com', 'Jane', 'Smith' );
		$new_contact_id = 'zoho_contact_456';

		// Mock: no existing contact found.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'email';
					}
				)
			)
			->andReturn( [ 'contacts' => [] ] );

		// Mock: create contact.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'contacts.create';
					}
				)
			)
			->andReturn(
				[
					'contact' => [
						'contact_id' => $new_contact_id,
					],
				]
			);

		$result = $this->service->find_or_create_contact( $order );

		$this->assertEquals( $new_contact_id, $result );
	}

	/**
	 * Test find or create throws on missing email.
	 */
	public function test_find_or_create_throws_on_missing_email(): void {
		$order = $this->create_test_order();
		$order->set_billing_email( '' );
		$order->save();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'no billing email' );

		$this->service->find_or_create_contact( $order );
	}

	/**
	 * Test currency mismatch throws exception.
	 */
	public function test_currency_mismatch_throws_exception(): void {
		$order      = $this->create_order_with_billing( 'john@example.com', 'John', 'Doe', 'EUR' );
		$contact_id = 'zoho_contact_123';

		// Mock: find contact with different currency.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'email';
					}
				)
			)
			->andReturn(
				[
					'contacts' => [
						[
							'contact_id' => $contact_id,
						],
					],
				]
			);

		// Mock: get contact with USD currency.
		$this->mock_client->shouldReceive( 'request' )
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
						'contact_id'    => $contact_id,
						'currency_code' => 'USD', // Contact is USD.
					],
				]
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Currency mismatch' );

		$this->service->find_or_create_contact( $order );
	}

	/**
	 * Test currency compatibility with matching currencies.
	 */
	public function test_check_currency_compatibility_matching(): void {
		$order   = $this->create_order_with_billing( 'test@example.com', 'Test', 'User', 'USD' );
		$contact = [
			'contact_id'    => 'contact_123',
			'currency_code' => 'USD',
		];

		$result = $this->service->check_currency_compatibility( $contact, $order );

		$this->assertTrue( $result['compatible'] );
		$this->assertEquals( 'USD', $result['contact_currency'] );
		$this->assertEquals( 'USD', $result['order_currency'] );
	}

	/**
	 * Test currency compatibility with no contact currency (defaults to org).
	 */
	public function test_check_currency_compatibility_no_contact_currency(): void {
		$order   = $this->create_order_with_billing( 'test@example.com', 'Test', 'User', 'EUR' );
		$contact = [
			'contact_id' => 'contact_123',
			// No currency_code set.
		];

		$result = $this->service->check_currency_compatibility( $contact, $order );

		$this->assertTrue( $result['compatible'] );
		$this->assertNull( $result['contact_currency'] );
	}

	/**
	 * Test contact name mapping from order.
	 */
	public function test_contact_name_mapping(): void {
		$order      = $this->create_order_with_billing( 'test@example.com', 'John', 'Doe' );
		$contact_id = 'zoho_contact_123';

		$captured_data = null;

		// Mock: no existing contact.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'email';
					}
				)
			)
			->andReturn( [ 'contacts' => [] ] );

		// Capture contact data.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_contacts = Mockery::mock();
						$mock_contacts->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
										return true;
									}
								)
							)
							->andReturn( (object) [ 'contact_id' => 'zoho_contact_123' ] );

						$mock_client           = Mockery::mock();
						$mock_client->contacts = $mock_contacts;

						$callable( $mock_client );
						return true;
					}
				),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'contacts.create';
					}
				)
			)
			->andReturn(
				[
					'contact' => [ 'contact_id' => $contact_id ],
				]
			);

		$result = $this->service->find_or_create_contact( $order );

		$this->assertEquals( $contact_id, $result );
		$this->assertNotNull( $captured_data );
		$this->assertEquals( 'John Doe', $captured_data['contact_name'] );
		$this->assertEquals( 'test@example.com', $captured_data['email'] );
		$this->assertEquals( 'customer', $captured_data['contact_type'] );
	}

	/**
	 * Test billing address is mapped correctly.
	 */
	public function test_billing_address_mapping(): void {
		$order = $this->create_order_with_full_address();

		$captured_data = null;

		// Mock: no existing contact.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'email';
					}
				)
			)
			->andReturn( [ 'contacts' => [] ] );

		// Capture contact data.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_contacts = Mockery::mock();
						$mock_contacts->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
										return true;
									}
								)
							)
							->andReturn( (object) [ 'contact_id' => 'contact_123' ] );

						$mock_client           = Mockery::mock();
						$mock_client->contacts = $mock_contacts;

						$callable( $mock_client );
						return true;
					}
				),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'contacts.create';
					}
				)
			)
			->andReturn(
				[
					'contact' => [ 'contact_id' => 'contact_123' ],
				]
			);

		$this->service->find_or_create_contact( $order );

		$this->assertNotNull( $captured_data );
		$this->assertArrayHasKey( 'billing_address', $captured_data );
		$this->assertEquals( '123 Main St', $captured_data['billing_address']['address'] );
		$this->assertEquals( 'New York', $captured_data['billing_address']['city'] );
		$this->assertEquals( 'NY', $captured_data['billing_address']['state'] );
		$this->assertEquals( '10001', $captured_data['billing_address']['zip'] );
		$this->assertEquals( 'US', $captured_data['billing_address']['country'] );
	}

	/**
	 * Test contact update when details change.
	 */
	public function test_contact_updated_when_details_change(): void {
		$order      = $this->create_order_with_billing( 'john@example.com', 'John', 'Doe Updated' );
		$contact_id = 'zoho_contact_123';

		// Mock: find existing contact.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'email';
					}
				)
			)
			->andReturn(
				[
					'contacts' => [
						[ 'contact_id' => $contact_id ],
					],
				]
			);

		// Mock: get contact - name is different.
		$this->mock_client->shouldReceive( 'request' )
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
						'contact_id'    => $contact_id,
						'contact_name'  => 'John Doe', // Old name.
						'email'         => 'john@example.com',
						'currency_code' => 'USD',
					],
				]
			);

		// Mock: update contact should be called.
		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'contacts.update';
					}
				)
			)
			->andReturnNull();

		$result = $this->service->find_or_create_contact( $order );

		$this->assertEquals( $contact_id, $result );
	}

	/**
	 * Test find contact by email returns ID.
	 */
	public function test_find_contact_by_email(): void {
		$email      = 'test@example.com';
		$contact_id = 'contact_123';

		// Mock: find contact.
		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'email';
					}
				)
			)
			->andReturn(
				[
					'contacts' => [
						[ 'contact_id' => $contact_id ],
					],
				]
			);

		// Mock: get contact.
		$this->mock_client->shouldReceive( 'request' )
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
					'contact' => [ 'contact_id' => $contact_id ],
				]
			);

		$result = $this->service->find_contact_by_email( $email );

		$this->assertEquals( $contact_id, $result );
	}

	/**
	 * Test find contact by email returns null when not found.
	 */
	public function test_find_contact_by_email_returns_null(): void {
		$email = 'notfound@example.com';

		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'email';
					}
				)
			)
			->andReturn( [ 'contacts' => [] ] );

		$result = $this->service->find_contact_by_email( $email );

		$this->assertNull( $result );
	}

	/**
	 * Test company name is included when set.
	 */
	public function test_company_name_included(): void {
		$order = $this->create_order_with_billing( 'corp@example.com', 'Jane', 'Smith' );
		$order->set_billing_company( 'Acme Corporation' );
		$order->save();

		$captured_data = null;

		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'email';
					}
				)
			)
			->andReturn( [ 'contacts' => [] ] );

		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_contacts = Mockery::mock();
						$mock_contacts->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
										return true;
									}
								)
							)
							->andReturn( (object) [ 'contact_id' => 'contact_123' ] );

						$mock_client           = Mockery::mock();
						$mock_client->contacts = $mock_contacts;

						$callable( $mock_client );
						return true;
					}
				),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'contacts.create';
					}
				)
			)
			->andReturn(
				[
					'contact' => [ 'contact_id' => 'contact_123' ],
				]
			);

		$this->service->find_or_create_contact( $order );

		$this->assertNotNull( $captured_data );
		$this->assertEquals( 'Acme Corporation', $captured_data['company_name'] );
	}

	/**
	 * Test phone is included when set.
	 */
	public function test_phone_included(): void {
		$order = $this->create_order_with_billing( 'phone@example.com', 'Test', 'User' );
		$order->set_billing_phone( '+1-555-123-4567' );
		$order->save();

		$captured_data = null;

		$this->mock_client->shouldReceive( 'request' )
			->with(
				Mockery::type( 'callable' ),
				Mockery::on(
					function ( $context ) {
						return isset( $context['filter'] ) && $context['filter'] === 'email';
					}
				)
			)
			->andReturn( [ 'contacts' => [] ] );

		$this->mock_client->shouldReceive( 'request' )
			->once()
			->with(
				Mockery::on(
					function ( $callable ) use ( &$captured_data ) {
						$mock_contacts = Mockery::mock();
						$mock_contacts->shouldReceive( 'create' )
							->once()
							->with(
								Mockery::on(
									function ( $data ) use ( &$captured_data ) {
										$captured_data = $data;
										return true;
									}
								)
							)
							->andReturn( (object) [ 'contact_id' => 'contact_123' ] );

						$mock_client           = Mockery::mock();
						$mock_client->contacts = $mock_contacts;

						$callable( $mock_client );
						return true;
					}
				),
				Mockery::on(
					function ( $context ) {
						return isset( $context['endpoint'] ) && $context['endpoint'] === 'contacts.create';
					}
				)
			)
			->andReturn(
				[
					'contact' => [ 'contact_id' => 'contact_123' ],
				]
			);

		$this->service->find_or_create_contact( $order );

		$this->assertNotNull( $captured_data );
		$this->assertEquals( '+1-555-123-4567', $captured_data['phone'] );
	}

	/**
	 * Helper: Create order with billing details.
	 *
	 * @param string $email     Email address.
	 * @param string $first     First name.
	 * @param string $last      Last name.
	 * @param string $currency  Currency code.
	 * @return WC_Order
	 */
	private function create_order_with_billing(
		string $email,
		string $first,
		string $last,
		string $currency = 'USD'
	): WC_Order {
		$order = $this->create_test_order();
		$order->set_billing_email( $email );
		$order->set_billing_first_name( $first );
		$order->set_billing_last_name( $last );
		$order->set_currency( $currency );
		$order->save();

		return $order;
	}

	/**
	 * Helper: Create order with full address.
	 *
	 * @return WC_Order
	 */
	private function create_order_with_full_address(): WC_Order {
		$order = $this->create_test_order();
		$order->set_billing_email( 'full@example.com' );
		$order->set_billing_first_name( 'Full' );
		$order->set_billing_last_name( 'Address' );
		$order->set_billing_address_1( '123 Main St' );
		$order->set_billing_address_2( 'Suite 100' );
		$order->set_billing_city( 'New York' );
		$order->set_billing_state( 'NY' );
		$order->set_billing_postcode( '10001' );
		$order->set_billing_country( 'US' );
		$order->set_currency( 'USD' );
		$order->save();

		return $order;
	}
}
