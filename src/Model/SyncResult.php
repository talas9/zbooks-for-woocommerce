<?php
/**
 * Sync result DTO.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Data transfer object for sync operation results.
 */
final class SyncResult {

	/**
	 * Constructor.
	 *
	 * @param bool        $success     Whether the sync was successful.
	 * @param SyncStatus  $status      The resulting sync status.
	 * @param string|null $invoice_id  Zoho invoice ID if created.
	 * @param string|null $contact_id  Zoho contact ID if created/matched.
	 * @param string|null $error       Error message if failed.
	 * @param array       $data        Additional data from the API response.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly SyncStatus $status,
		public readonly ?string $invoice_id = null,
		public readonly ?string $contact_id = null,
		public readonly ?string $error = null,
		public readonly array $data = []
	) {}

	/**
	 * Create a successful sync result.
	 *
	 * @param string      $invoice_id Zoho invoice ID.
	 * @param string|null $contact_id Zoho contact ID.
	 * @param SyncStatus  $status     Sync status (SYNCED or DRAFT).
	 * @param array       $data       Additional response data.
	 * @return self
	 */
	public static function success(
		string $invoice_id,
		?string $contact_id = null,
		SyncStatus $status = SyncStatus::SYNCED,
		array $data = []
	): self {
		return new self(
			success: true,
			status: $status,
			invoice_id: $invoice_id,
			contact_id: $contact_id,
			data: $data
		);
	}

	/**
	 * Create a failed sync result.
	 *
	 * @param string $error Error message.
	 * @param array  $data  Additional error data.
	 * @return self
	 */
	public static function failure( string $error, array $data = [] ): self {
		return new self(
			success: false,
			status: SyncStatus::FAILED,
			error: $error,
			data: $data
		);
	}

	/**
	 * Create a pending sync result.
	 *
	 * Used when sync cannot proceed (e.g., lock held, rate limited).
	 *
	 * @param string $reason Reason for pending status.
	 * @param array  $data   Additional data.
	 * @return self
	 */
	public static function pending( string $reason, array $data = [] ): self {
		return new self(
			success: false,
			status: SyncStatus::PENDING,
			error: $reason,
			data: $data
		);
	}

	/**
	 * Convert to array for storage.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return [
			'success'    => $this->success,
			'status'     => $this->status->value,
			'invoice_id' => $this->invoice_id,
			'contact_id' => $this->contact_id,
			'error'      => $this->error,
			'data'       => $this->data,
		];
	}
}
