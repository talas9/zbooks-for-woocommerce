<?php
/**
 * Sync status enum.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Model;

defined('ABSPATH') || exit;

/**
 * Enum representing possible sync statuses.
 */
enum SyncStatus: string {
    case PENDING = 'pending';
    case SYNCED = 'synced';
    case FAILED = 'failed';
    case DRAFT = 'draft';

    /**
     * Get human-readable label.
     *
     * @return string
     */
    public function label(): string {
        return match ($this) {
            self::PENDING => __('Pending', 'zbooks-for-woocommerce'),
            self::SYNCED => __('Synced', 'zbooks-for-woocommerce'),
            self::FAILED => __('Failed', 'zbooks-for-woocommerce'),
            self::DRAFT => __('Draft', 'zbooks-for-woocommerce'),
        };
    }

    /**
     * Get CSS class for status badge.
     *
     * @return string
     */
    public function css_class(): string {
        return match ($this) {
            self::PENDING => 'zbooks-status-pending',
            self::SYNCED => 'zbooks-status-synced',
            self::FAILED => 'zbooks-status-failed',
            self::DRAFT => 'zbooks-status-draft',
        };
    }
}
