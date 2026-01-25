<?php
/**
 * Rate limiter for Zoho API.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Implements rate limiting for Zoho API (100 requests/minute).
 */
class RateLimiter {

	/**
	 * Maximum requests per minute.
	 */
	private const MAX_REQUESTS_PER_MINUTE = 100;

	/**
	 * Transient key prefix.
	 */
	private const TRANSIENT_PREFIX = 'zbooks_rate_';

	/**
	 * Check if a request can be made.
	 *
	 * @return bool
	 */
	public function can_make_request(): bool {
		$count = $this->get_request_count();
		return $count < self::MAX_REQUESTS_PER_MINUTE;
	}

	/**
	 * Get remaining requests in the current window.
	 *
	 * @return int
	 */
	public function get_remaining_requests(): int {
		$count = $this->get_request_count();
		return max( 0, self::MAX_REQUESTS_PER_MINUTE - $count );
	}

	/**
	 * Record a request.
	 */
	public function record_request(): void {
		$window_key = $this->get_window_key();
		$count      = $this->get_request_count();

		set_transient( $window_key, $count + 1, 60 );
	}

	/**
	 * Wait until a request can be made.
	 *
	 * @param int $max_wait_seconds Maximum seconds to wait (default 60).
	 * @return bool True if can proceed, false if timeout.
	 */
	public function wait_for_availability( int $max_wait_seconds = 60 ): bool {
		$start_time = time();

		while ( ! $this->can_make_request() ) {
			if ( ( time() - $start_time ) >= $max_wait_seconds ) {
				return false;
			}

			// Wait 1 second before checking again.
			sleep( 1 );
		}

		return true;
	}

	/**
	 * Get seconds until the rate limit resets.
	 *
	 * @return int
	 */
	public function get_seconds_until_reset(): int {
		$window_key = $this->get_window_key();
		$timeout    = get_option( '_transient_timeout_' . $window_key );

		if ( ! $timeout ) {
			return 0;
		}

		return max( 0, (int) $timeout - time() );
	}

	/**
	 * Get current request count.
	 *
	 * @return int
	 */
	private function get_request_count(): int {
		$window_key = $this->get_window_key();
		$count      = get_transient( $window_key );

		return $count !== false ? (int) $count : 0;
	}

	/**
	 * Get the current rate limit window key.
	 *
	 * @return string
	 */
	private function get_window_key(): string {
		// Use minute-based windows.
		$minute = (int) floor( time() / 60 );
		return self::TRANSIENT_PREFIX . $minute;
	}

	/**
	 * Reset the rate limiter (for testing).
	 */
	public function reset(): void {
		$window_key = $this->get_window_key();
		delete_transient( $window_key );
	}
}
