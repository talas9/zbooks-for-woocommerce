<?php
/**
 * Unit tests for TokenManager.
 *
 * @package Zbooks
 * @subpackage Tests
 */

declare(strict_types=1);

namespace Zbooks\Tests\Unit\Api;

use Zbooks\Tests\TestCase;
use Zbooks\Api\TokenManager;

/**
 * Test cases for TokenManager.
 */
class TokenManagerTest extends TestCase {

	/**
	 * Token manager instance.
	 *
	 * @var TokenManager
	 */
	private TokenManager $manager;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		// Clear any existing options.
		delete_option( 'zbooks_tokens' );
		delete_option( 'zbooks_oauth_credentials' );

		$this->manager = new TokenManager();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		delete_option( 'zbooks_tokens' );
		delete_option( 'zbooks_oauth_credentials' );
		parent::tear_down();
	}

	/**
	 * Test has_credentials returns false when not configured.
	 */
	public function test_has_credentials_returns_false_when_empty(): void {
		$this->assertFalse( $this->manager->has_credentials() );
	}

	/**
	 * Test save and retrieve credentials.
	 */
	public function test_save_and_get_credentials(): void {
		$client_id     = 'test_client_id_12345';
		$client_secret = 'test_client_secret_67890';
		$refresh_token = 'test_refresh_token_abcdef';

		$result = $this->manager->save_credentials( $client_id, $client_secret, $refresh_token );

		$this->assertTrue( $result );
		$this->assertTrue( $this->manager->has_credentials() );

		$credentials = $this->manager->get_credentials();

		$this->assertNotNull( $credentials );
		$this->assertEquals( $client_id, $credentials['client_id'] );
		$this->assertEquals( $client_secret, $credentials['client_secret'] );
		$this->assertEquals( $refresh_token, $credentials['refresh_token'] );
	}

	/**
	 * Test credentials are encrypted in database.
	 */
	public function test_credentials_are_encrypted(): void {
		$client_id     = 'plain_client_id';
		$client_secret = 'plain_client_secret';
		$refresh_token = 'plain_refresh_token';

		$this->manager->save_credentials( $client_id, $client_secret, $refresh_token );

		// Read raw option from database.
		$raw_option = get_option( 'zbooks_oauth_credentials' );

		$this->assertIsArray( $raw_option );

		// Raw stored values should NOT equal plain text (they're encrypted).
		$this->assertNotEquals( $client_id, $raw_option['client_id'] );
		$this->assertNotEquals( $client_secret, $raw_option['client_secret'] );
		$this->assertNotEquals( $refresh_token, $raw_option['refresh_token'] );
	}

	/**
	 * Test save and retrieve access token.
	 */
	public function test_save_and_get_access_token(): void {
		$access_token = 'access_token_xyz_123';
		$expires_in   = 3600; // 1 hour

		$result = $this->manager->save_access_token( $access_token, $expires_in );

		$this->assertTrue( $result );

		$retrieved = $this->manager->get_access_token();

		$this->assertEquals( $access_token, $retrieved );
	}

	/**
	 * Test access token expiry is stored correctly.
	 */
	public function test_access_token_expiry(): void {
		$access_token = 'test_token';
		$expires_in   = 3600;
		$before_save  = time();

		$this->manager->save_access_token( $access_token, $expires_in );

		$expiry = $this->manager->get_access_token_expiry();

		// Expiry should be approximately now + expires_in.
		$this->assertGreaterThanOrEqual( $before_save + $expires_in, $expiry );
		$this->assertLessThanOrEqual( $before_save + $expires_in + 2, $expiry ); // Allow 2s tolerance.
	}

	/**
	 * Test is_token_expired returns true for expired token.
	 */
	public function test_is_token_expired_returns_true_for_expired(): void {
		// Save token that expired 10 minutes ago.
		update_option(
			'zbooks_tokens',
			[
				'access_token' => base64_encode( 'expired_token' ), // Simple encoding fallback.
				'expires_at'   => time() - 600, // 10 minutes ago.
			]
		);

		$this->assertTrue( $this->manager->is_token_expired() );
	}

	/**
	 * Test is_token_expired returns true when within 5 minute buffer.
	 */
	public function test_is_token_expired_returns_true_within_buffer(): void {
		// Save token that expires in 3 minutes (within 5 minute buffer).
		$token = 'almost_expired_token';
		$this->manager->save_access_token( $token, 180 ); // 3 minutes

		$this->assertTrue( $this->manager->is_token_expired() );
	}

	/**
	 * Test is_token_expired returns false for valid token.
	 */
	public function test_is_token_expired_returns_false_for_valid(): void {
		// Save token that expires in 1 hour.
		$token = 'valid_token';
		$this->manager->save_access_token( $token, 3600 );

		$this->assertFalse( $this->manager->is_token_expired() );
	}

	/**
	 * Test is_token_expired returns true when no token exists.
	 */
	public function test_is_token_expired_returns_true_when_no_token(): void {
		$this->assertTrue( $this->manager->is_token_expired() );
	}

	/**
	 * Test get_refresh_token returns token from credentials.
	 */
	public function test_get_refresh_token(): void {
		$refresh_token = 'my_refresh_token_123';

		$this->manager->save_credentials( 'client_id', 'client_secret', $refresh_token );

		$this->assertEquals( $refresh_token, $this->manager->get_refresh_token() );
	}

	/**
	 * Test get_refresh_token returns null when not set.
	 */
	public function test_get_refresh_token_returns_null_when_not_set(): void {
		$this->assertNull( $this->manager->get_refresh_token() );
	}

	/**
	 * Test clear_tokens removes access token.
	 */
	public function test_clear_tokens(): void {
		$this->manager->save_access_token( 'test_token', 3600 );

		$this->assertNotNull( $this->manager->get_access_token() );

		$result = $this->manager->clear_tokens();

		$this->assertTrue( $result );
		$this->assertNull( $this->manager->get_access_token() );
	}

	/**
	 * Test has_credentials returns false with partial credentials.
	 */
	public function test_has_credentials_false_with_partial_data(): void {
		// Save only client_id and client_secret, no refresh token.
		$this->manager->save_credentials( 'client_id', 'client_secret', '' );

		$this->assertFalse( $this->manager->has_credentials() );
	}

	/**
	 * Test is_saving static method.
	 */
	public function test_is_saving_during_save(): void {
		// Before saving.
		$this->assertFalse( TokenManager::is_saving() );

		// is_saving is only true during save_credentials execution.
		// After save_credentials completes, it should be false again.
		$this->manager->save_credentials( 'test', 'test', 'test' );

		$this->assertFalse( TokenManager::is_saving() );
	}

	/**
	 * Test access token encryption in storage.
	 */
	public function test_access_token_is_encrypted(): void {
		$access_token = 'plain_access_token_value';

		$this->manager->save_access_token( $access_token, 3600 );

		// Read raw option.
		$raw_option = get_option( 'zbooks_tokens' );

		$this->assertIsArray( $raw_option );
		$this->assertNotEquals( $access_token, $raw_option['access_token'] );
	}

	/**
	 * Test empty string credentials are handled.
	 */
	public function test_empty_credentials_handled(): void {
		$credentials = $this->manager->get_credentials();

		$this->assertNull( $credentials );
	}

	/**
	 * Test credentials with special characters.
	 */
	public function test_credentials_with_special_characters(): void {
		$client_id     = 'client_!@#$%^&*()_+-={}[]';
		$client_secret = 'secret_<>?/\\|";:\'';
		$refresh_token = 'token_äöü中文日本語';

		$this->manager->save_credentials( $client_id, $client_secret, $refresh_token );

		$credentials = $this->manager->get_credentials();

		$this->assertEquals( $client_id, $credentials['client_id'] );
		$this->assertEquals( $client_secret, $credentials['client_secret'] );
		$this->assertEquals( $refresh_token, $credentials['refresh_token'] );
	}

	/**
	 * Test multiple save operations overwrite correctly.
	 */
	public function test_multiple_saves_overwrite(): void {
		$this->manager->save_credentials( 'old_id', 'old_secret', 'old_token' );

		$credentials1 = $this->manager->get_credentials();
		$this->assertEquals( 'old_id', $credentials1['client_id'] );

		$this->manager->save_credentials( 'new_id', 'new_secret', 'new_token' );

		$credentials2 = $this->manager->get_credentials();
		$this->assertEquals( 'new_id', $credentials2['client_id'] );
		$this->assertEquals( 'new_secret', $credentials2['client_secret'] );
		$this->assertEquals( 'new_token', $credentials2['refresh_token'] );
	}

	/**
	 * Test access token updates preserve other data.
	 */
	public function test_access_token_update_preserves_expiry_format(): void {
		$this->manager->save_access_token( 'first_token', 3600 );
		$first_expiry = $this->manager->get_access_token_expiry();

		// Wait a moment and save new token.
		sleep( 1 );

		$this->manager->save_access_token( 'second_token', 7200 );
		$second_expiry = $this->manager->get_access_token_expiry();

		$this->assertEquals( 'second_token', $this->manager->get_access_token() );
		$this->assertGreaterThan( $first_expiry, $second_expiry );
	}
}
