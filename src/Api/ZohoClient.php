<?php
/**
 * Zoho Books API client.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Api;

use Zbooks\Logger\SyncLogger;
use Weble\ZohoClient\OAuthClient;
use Webleit\ZohoBooksApi\Client as ZohoBooksClient;
use Webleit\ZohoBooksApi\ZohoBooks;

defined( 'ABSPATH' ) || exit;

/**
 * Wrapper around the Zoho Books SDK with token management.
 */
class ZohoClient {

	/**
	 * Zoho Books SDK instance.
	 *
	 * @var ZohoBooks|null
	 */
	private ?ZohoBooks $client = null;

	/**
	 * OAuth client instance.
	 *
	 * @var OAuthClient|null
	 */
	private ?OAuthClient $oauth_client = null;

	/**
	 * SDK client wrapper.
	 *
	 * @var ZohoBooksClient|null
	 */
	private ?ZohoBooksClient $sdk_client = null;

	/**
	 * Token manager.
	 *
	 * @var TokenManager
	 */
	private TokenManager $token_manager;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Logger.
	 *
	 * @var SyncLogger
	 */
	private SyncLogger $logger;

	/**
	 * Zoho datacenter regions.
	 */
	private const DATACENTERS = [
		'us' => 'https://www.zohoapis.com',
		'eu' => 'https://www.zohoapis.eu',
		'in' => 'https://www.zohoapis.in',
		'au' => 'https://www.zohoapis.com.au',
		'jp' => 'https://www.zohoapis.jp',
		'cn' => 'https://www.zohoapis.com.cn',
	];

	/**
	 * Constructor.
	 *
	 * @param TokenManager $token_manager Token manager instance.
	 * @param RateLimiter  $rate_limiter  Rate limiter instance.
	 * @param SyncLogger   $logger        Logger instance.
	 */
	public function __construct(
		TokenManager $token_manager,
		RateLimiter $rate_limiter,
		SyncLogger $logger
	) {
		$this->token_manager = $token_manager;
		$this->rate_limiter  = $rate_limiter;
		$this->logger        = $logger;
	}

	/**
	 * Get the Zoho Books client.
	 *
	 * @return ZohoBooks
	 * @throws \RuntimeException If not configured or token refresh fails.
	 */
	public function get_client(): ZohoBooks {
		if ( $this->client !== null ) {
			// Ensure token is still valid.
			if ( $this->token_manager->is_token_expired() ) {
				$this->refresh_access_token();
			}
			return $this->client;
		}

		$this->initialize_client();
		return $this->client;
	}

	/**
	 * Initialize the Zoho Books client.
	 *
	 * @throws \RuntimeException If not configured.
	 */
	private function initialize_client(): void {
		if ( ! $this->token_manager->has_credentials() ) {
			throw new \RuntimeException(
				esc_html__( 'Zoho Books API credentials not configured.', 'zbooks-for-woocommerce' )
			);
		}

		$credentials = $this->token_manager->get_credentials();
		$datacenter  = get_option( 'zbooks_datacenter', 'us' );

		$this->oauth_client = new OAuthClient(
			$credentials['client_id'],
			$credentials['client_secret']
		);

		$this->oauth_client->setRefreshToken( $credentials['refresh_token'] );

		// Set existing access token if available and not expired.
		$access_token = $this->token_manager->get_access_token();
		if ( $access_token && ! $this->token_manager->is_token_expired() ) {
			$this->oauth_client->setAccessToken( $access_token );
		} else {
			$this->refresh_access_token();
		}

		$this->oauth_client->offlineMode();

		// Set datacenter.
		if ( isset( self::DATACENTERS[ $datacenter ] ) ) {
			$this->oauth_client->setRegion( $datacenter );
		}

		// Create SDK client wrapper (required by ZohoBooks)
		$this->sdk_client = new ZohoBooksClient( $this->oauth_client );

		// Set organization ID on the SDK client.
		$org_id = get_option( 'zbooks_organization_id' );
		if ( $org_id ) {
			$this->sdk_client->setOrganizationId( $org_id );
		}

		// Create the ZohoBooks API instance.
		$this->client = new ZohoBooks( $this->sdk_client );
	}

	/**
	 * Refresh the access token.
	 *
	 * @throws \RuntimeException If refresh fails.
	 */
	public function refresh_access_token(): void {
		if ( $this->oauth_client === null ) {
			$credentials = $this->token_manager->get_credentials();

			if ( ! $credentials ) {
				throw new \RuntimeException(
					esc_html__( 'Zoho Books API credentials not configured.', 'zbooks-for-woocommerce' )
				);
			}

			$this->oauth_client = new OAuthClient(
				$credentials['client_id'],
				$credentials['client_secret']
			);
			$this->oauth_client->setRefreshToken( $credentials['refresh_token'] );
		}

		try {
			$this->logger->debug( 'Refreshing Zoho access token' );

			$token = $this->oauth_client->getAccessToken();

			// Save the new access token.
			$this->token_manager->save_access_token( $token, 3600 );

			$this->logger->info( 'Zoho access token refreshed successfully' );
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to refresh access token',
				[
					'error' => $e->getMessage(),
				]
			);
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: Error message */
					esc_html__( 'Failed to refresh Zoho access token: %s', 'zbooks-for-woocommerce' ),
					esc_html( $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Make an API request with rate limiting.
	 *
	 * @param callable $request The request callback.
	 * @param array    $context Optional context for logging (e.g., endpoint, entity_type, entity_id).
	 * @return mixed The response.
	 * @throws \RuntimeException If rate limited or request fails.
	 */
	public function request( callable $request, array $context = [] ): mixed {
		// Check rate limit.
		if ( ! $this->rate_limiter->can_make_request() ) {
			$wait_time = $this->rate_limiter->get_seconds_until_reset();
			$this->logger->warning( 'Rate limit reached', [ 'wait_seconds' => $wait_time ] );

			if ( ! $this->rate_limiter->wait_for_availability( 30 ) ) {
				throw new \RuntimeException(
					esc_html__( 'Zoho API rate limit exceeded. Please try again later.', 'zbooks-for-woocommerce' )
				);
			}
		}

		// Record the request.
		$this->rate_limiter->record_request();

		try {
			return $request( $this->get_client() );
		} catch ( \Exception $e ) {
			$this->logger->error(
				'API request failed',
				array_merge(
					[
						'error' => $e->getMessage(),
					],
					$context
				)
			);
			throw $e;
		}
	}

	/**
	 * Get available organizations.
	 *
	 * @return array List of organizations.
	 */
	public function get_organizations(): array {
		return $this->request(
			function ( ZohoBooks $client ) {
				$response = $client->organizations->getList();

				// Handle different response formats from the SDK.
				if ( is_array( $response ) ) {
					return $response['organizations'] ?? $response;
				}

				// If it's a collection, convert to array.
				if ( method_exists( $response, 'toArray' ) ) {
					return $response->toArray();
				}

				return [];
			}
		);
	}

	/**
	 * Check if the client is configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return $this->token_manager->has_credentials();
	}

	/**
	 * Test the connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		try {
			$orgs = $this->get_organizations();
			return ! empty( $orgs );
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Connection test failed',
				[
					'error' => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Exchange a grant code for access and refresh tokens.
	 *
	 * Grant codes are single-use and expire quickly (3 min default, configurable up to 10 min).
	 * This method exchanges them for long-lived tokens.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @param string $grant_code    Authorization grant code.
	 * @param string $datacenter    Datacenter region (us, eu, in, au, jp).
	 * @return array{access_token: string, refresh_token: string, expires_in: int}
	 * @throws \RuntimeException If exchange fails.
	 */
	public function exchange_grant_code(
		string $client_id,
		string $client_secret,
		string $grant_code,
		string $datacenter = 'us'
	): array {
		$this->logger->info( 'Exchanging grant code for tokens' );

		$oauth = new OAuthClient( $client_id, $client_secret );
		$oauth->offlineMode();

		if ( isset( self::DATACENTERS[ $datacenter ] ) ) {
			$oauth->setRegion( $datacenter );
		}

		try {
			// Use grant code to get tokens.
			$oauth->setGrantCode( $grant_code );

			// Get access token object - this exchanges the grant code.
			$token_object = $oauth->getAccessTokenObject();
			$access_token = $token_object->getToken();

			// Get refresh token from the token object (not by calling getRefreshToken()
			// which would try to use the already-consumed grant code).
			$refresh_token = $token_object->getRefreshToken();

			if ( empty( $refresh_token ) ) {
				throw new \RuntimeException(
					esc_html__( 'No refresh token received. Ensure the grant code has offline_access scope.', 'zbooks-for-woocommerce' )
				);
			}

			$this->logger->info( 'Grant code exchanged successfully' );

			return [
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
				'expires_in'    => 3600,
			];
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Grant code exchange failed',
				[
					'error' => $e->getMessage(),
				]
			);
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: Error message */
					esc_html__( 'Failed to exchange grant code: %s', 'zbooks-for-woocommerce' ),
					esc_html( $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Check if a token appears to be a grant code vs refresh token.
	 *
	 * Grant codes start with "1000." and are longer than refresh tokens.
	 *
	 * @param string $token Token to check.
	 * @return bool True if likely a grant code.
	 */
	public function is_grant_code( string $token ): bool {
		// Grant codes typically start with "1000." and have a specific format.
		// They contain two parts separated by a dot after the prefix.
		return preg_match( '/^1000\.[a-f0-9]{32}\.[a-f0-9]{32,}$/i', $token ) === 1;
	}

	/**
	 * Make a raw API call to Zoho Books.
	 *
	 * Use this for API endpoints not supported by the SDK.
	 *
	 * @param string $method  HTTP method (GET, POST, PUT, DELETE).
	 * @param string $path    API path (e.g., "/creditnotes/{id}/refunds").
	 * @param array  $data    Request data.
	 * @return array Response data.
	 * @throws \RuntimeException If request fails.
	 */
	public function raw_request( string $method, string $path, array $data = [] ): array {
		// Ensure client is initialized.
		$this->get_client();

		// Check rate limit.
		if ( ! $this->rate_limiter->can_make_request() ) {
			$wait_time = $this->rate_limiter->get_seconds_until_reset();
			$this->logger->warning( 'Rate limit reached', [ 'wait_seconds' => $wait_time ] );

			if ( ! $this->rate_limiter->wait_for_availability( 30 ) ) {
				throw new \RuntimeException(
					esc_html__( 'Zoho API rate limit exceeded. Please try again later.', 'zbooks-for-woocommerce' )
				);
			}
		}

		$this->rate_limiter->record_request();

		// Build URL.
		$datacenter = get_option( 'zbooks_datacenter', 'us' );
		$base_url   = self::DATACENTERS[ $datacenter ] ?? self::DATACENTERS['us'];
		$org_id     = get_option( 'zbooks_organization_id' );
		$url        = $base_url . '/books/v3' . $path . '?organization_id=' . $org_id;

		// Get access token.
		$access_token = $this->token_manager->get_access_token();
		if ( ! $access_token || $this->token_manager->is_token_expired() ) {
			$this->refresh_access_token();
			$access_token = $this->token_manager->get_access_token();
		}

		$args = [
			'method'  => strtoupper( $method ),
			'headers' => [
				'Authorization' => 'Zoho-oauthtoken ' . $access_token,
				'Content-Type'  => 'application/json',
			],
			'timeout' => 30,
		];

		if ( ! empty( $data ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$this->logger->debug(
			'Raw API request',
			[
				'method'   => $method,
				'endpoint' => $path,
			]
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'Raw API request failed',
				[
					'method'   => $method,
					'endpoint' => $path,
					'error'    => $response->get_error_message(),
				]
			);
			throw new \RuntimeException( $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$result      = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->logger->error(
				'Invalid JSON response from Zoho API',
				[
					'method'      => $method,
					'endpoint'    => $path,
					'status_code' => $status_code,
					'raw_body'    => substr( $body, 0, 500 ),
				]
			);
			throw new \RuntimeException( 'Invalid JSON response from Zoho API' );
		}

		// Check for API errors.
		$code = $result['code'] ?? 0;
		if ( $code !== 0 ) {
			$message = $result['message'] ?? 'Unknown API error';
			$this->logger->error(
				'Zoho API error',
				[
					'method'       => $method,
					'endpoint'     => $path,
					'status_code'  => $status_code,
					'zoho_code'    => $code,
					'zoho_message' => $message,
				]
			);
			throw new \RuntimeException( $message );
		}

		return $result;
	}
}
