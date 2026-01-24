<?php
/**
 * Token manager for Zoho OAuth.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Api;

defined('ABSPATH') || exit;

/**
 * Manages Zoho OAuth tokens securely.
 */
class TokenManager {

    /**
     * Option name for tokens.
     */
    private const TOKENS_OPTION = 'zbooks_tokens';

    /**
     * Option name for credentials.
     */
    private const CREDENTIALS_OPTION = 'zbooks_oauth_credentials';

    /**
     * Recursion guard to prevent infinite loops from WordPress sanitize filters.
     *
     * @var bool
     */
    private static bool $saving_credentials = false;

    /**
     * Check if TokenManager is currently saving credentials.
     *
     * Used by SettingsPage to skip sanitize callback when TokenManager is handling storage directly.
     *
     * @return bool
     */
    public static function is_saving(): bool {
        return self::$saving_credentials;
    }

    /**
     * Get OAuth credentials.
     *
     * @return array{client_id: string, client_secret: string, refresh_token: string}|null
     */
    public function get_credentials(): ?array {
        $credentials = get_option(self::CREDENTIALS_OPTION);

        if (!is_array($credentials)) {
            return null;
        }

        return [
            'client_id' => $this->decrypt($credentials['client_id'] ?? ''),
            'client_secret' => $this->decrypt($credentials['client_secret'] ?? ''),
            'refresh_token' => $this->decrypt($credentials['refresh_token'] ?? ''),
        ];
    }

    /**
     * Save OAuth credentials.
     *
     * @param string $client_id     Client ID.
     * @param string $client_secret Client secret.
     * @param string $refresh_token Refresh token.
     * @return bool
     */
    public function save_credentials(
        string $client_id,
        string $client_secret,
        string $refresh_token
    ): bool {
        // Prevent recursion from WordPress sanitize_option filter.
        // SettingsPage registers a sanitize_callback that calls this method,
        // which triggers another sanitize_option call, causing infinite recursion.
        if (self::$saving_credentials) {
            return true;
        }

        self::$saving_credentials = true;

        try {
            $enc_client_id = $this->encrypt($client_id);
            $enc_client_secret = $this->encrypt($client_secret);
            $enc_refresh_token = $this->encrypt($refresh_token);

            $credentials = [
                'client_id' => $enc_client_id,
                'client_secret' => $enc_client_secret,
                'refresh_token' => $enc_refresh_token,
            ];

            return update_option(self::CREDENTIALS_OPTION, $credentials);
        } finally {
            self::$saving_credentials = false;
        }
    }

    /**
     * Get access token.
     *
     * @return string|null
     */
    public function get_access_token(): ?string {
        $tokens = get_option(self::TOKENS_OPTION);

        if (!is_array($tokens) || empty($tokens['access_token'])) {
            return null;
        }

        return $this->decrypt($tokens['access_token']);
    }

    /**
     * Get access token expiry timestamp.
     *
     * @return int|null
     */
    public function get_access_token_expiry(): ?int {
        $tokens = get_option(self::TOKENS_OPTION);

        if (!is_array($tokens) || !isset($tokens['expires_at'])) {
            return null;
        }

        return (int) $tokens['expires_at'];
    }

    /**
     * Check if access token is expired.
     *
     * @return bool
     */
    public function is_token_expired(): bool {
        $expiry = $this->get_access_token_expiry();

        if ($expiry === null) {
            return true;
        }

        // Consider expired 5 minutes before actual expiry.
        return time() >= ($expiry - 300);
    }

    /**
     * Save access token.
     *
     * @param string $access_token Access token.
     * @param int    $expires_in   Seconds until expiry.
     * @return bool
     */
    public function save_access_token(string $access_token, int $expires_in): bool {
        $tokens = get_option(self::TOKENS_OPTION, []);

        if (!is_array($tokens)) {
            $tokens = [];
        }

        $tokens['access_token'] = $this->encrypt($access_token);
        $tokens['expires_at'] = time() + $expires_in;

        return update_option(self::TOKENS_OPTION, $tokens);
    }

    /**
     * Get refresh token.
     *
     * @return string|null
     */
    public function get_refresh_token(): ?string {
        $credentials = $this->get_credentials();
        return $credentials['refresh_token'] ?? null;
    }

    /**
     * Clear all tokens.
     *
     * @return bool
     */
    public function clear_tokens(): bool {
        return delete_option(self::TOKENS_OPTION);
    }

    /**
     * Check if credentials are configured.
     *
     * @return bool
     */
    public function has_credentials(): bool {
        $credentials = $this->get_credentials();

        return $credentials !== null
            && !empty($credentials['client_id'])
            && !empty($credentials['client_secret'])
            && !empty($credentials['refresh_token']);
    }

    /**
     * Encrypt a value.
     *
     * @param string $value Value to encrypt.
     * @return string Encrypted value.
     */
    private function encrypt(string $value): string {
        if (empty($value)) {
            return '';
        }

        $key = $this->get_encryption_key();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $encrypted = sodium_crypto_secretbox($value, $nonce, $key);
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            return base64_encode($nonce . $encrypted);
        }

        // Fallback to simple encoding if sodium not available.
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        return base64_encode($value);
    }

    /**
     * Decrypt a value.
     *
     * @param string $value Value to decrypt.
     * @return string Decrypted value.
     */
    private function decrypt(string $value): string {
        if (empty($value)) {
            return '';
        }

        $key = $this->get_encryption_key();

        if (function_exists('sodium_crypto_secretbox_open')) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            $decoded = base64_decode($value, true);

            if ($decoded === false) {
                return '';
            }

            $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

            return $decrypted !== false ? $decrypted : '';
        }

        // Fallback for simple encoding.
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $decoded = base64_decode($value, true);
        return $decoded !== false ? $decoded : '';
    }

    /**
     * Get encryption key.
     *
     * @return string
     */
    private function get_encryption_key(): string {
        if (defined('ZBOOKS_ENCRYPTION_KEY')) {
            return hash('sha256', ZBOOKS_ENCRYPTION_KEY, true);
        }

        // Use WordPress salts as fallback.
        $salt = '';
        if (defined('SECURE_AUTH_KEY')) {
            $salt .= SECURE_AUTH_KEY;
        }
        if (defined('SECURE_AUTH_SALT')) {
            $salt .= SECURE_AUTH_SALT;
        }
        if (empty($salt)) {
            $salt = 'zbooks-default-key-' . ABSPATH;
        }

        return hash('sha256', $salt, true);
    }
}
