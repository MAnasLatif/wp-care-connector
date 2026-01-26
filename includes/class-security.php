<?php
/**
 * WP Care Security Class
 *
 * Handles API key generation, encryption, and HMAC signature verification.
 * Provides secure authentication for all remote command endpoints.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 */

// Security check: prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_Care_Security
 *
 * Static utility class for security operations:
 * - API key generation and encrypted storage
 * - HMAC signature verification for request authentication
 * - Constant-time comparisons to prevent timing attacks
 */
class WP_Care_Security {

    /**
     * Option key for encrypted API key storage
     *
     * @var string
     */
    private static $option_key = 'wp_care_api_key_encrypted';

    /**
     * Option key for API key hash (for display purposes)
     *
     * @var string
     */
    private static $key_hash = 'wp_care_api_key_hash';

    /**
     * Encryption cipher algorithm
     *
     * @var string
     */
    private static $cipher = 'aes-256-cbc';

    /**
     * Maximum age of request timestamp in seconds (5 minutes)
     *
     * @var int
     */
    private static $max_timestamp_age = 300;

    /**
     * Generate a new API key and store it encrypted
     *
     * Called during plugin activation. Generates a cryptographically
     * secure random key, encrypts it, and stores in wp_options.
     *
     * @return string The raw API key (only returned once, during generation)
     */
    public static function generate_api_key() {
        // Generate 32-character cryptographically secure random key
        // Using wp_generate_password with special chars disabled for URL safety
        $api_key = wp_generate_password(32, false, false);

        // Encrypt the key before storage
        $encrypted = self::encrypt($api_key);

        // Store encrypted key in database
        update_option(self::$option_key, $encrypted, false);

        // Store hash of key for admin display (last 8 chars visible)
        $hash = '********' . substr($api_key, -8);
        update_option(self::$key_hash, $hash, false);

        // Return raw key - this is the only time it's available unencrypted
        return $api_key;
    }

    /**
     * Encrypt data using AES-256-CBC
     *
     * Uses WordPress LOGGED_IN_SALT as encryption key for site-specific
     * encryption. Falls back to AUTH_KEY if LOGGED_IN_SALT unavailable.
     *
     * @param string $data The data to encrypt
     * @return string Base64-encoded encrypted data with IV prepended
     */
    public static function encrypt($data) {
        // Get encryption key from WordPress salts
        $key = self::get_encryption_key();

        // Generate random initialization vector
        $iv_length = openssl_cipher_iv_length(self::$cipher);
        $iv = openssl_random_pseudo_bytes($iv_length);

        // Encrypt the data
        $encrypted = openssl_encrypt(
            $data,
            self::$cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        // Prepend IV to ciphertext and base64 encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data encrypted with encrypt()
     *
     * Extracts the IV from the prepended bytes and decrypts.
     *
     * @param string $encrypted_data Base64-encoded encrypted data
     * @return string|false Decrypted data or false on failure
     */
    public static function decrypt($encrypted_data) {
        // Get encryption key
        $key = self::get_encryption_key();

        // Decode base64
        $data = base64_decode($encrypted_data);
        if ($data === false) {
            return false;
        }

        // Extract IV from prepended bytes
        $iv_length = openssl_cipher_iv_length(self::$cipher);
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);

        // Decrypt and return
        return openssl_decrypt(
            $encrypted,
            self::$cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
    }

    /**
     * Get the stored API key (decrypted)
     *
     * Used internally for HMAC verification. Never expose directly.
     *
     * @return string|false The decrypted API key or false if not found
     */
    public static function get_api_key() {
        $encrypted = get_option(self::$option_key);

        if (empty($encrypted)) {
            return false;
        }

        return self::decrypt($encrypted);
    }

    /**
     * Verify API key from request header
     *
     * Simple API key verification for endpoints that don't require
     * full HMAC verification (e.g., health checks from authenticated sources).
     *
     * @param WP_REST_Request $request The REST API request object
     * @return bool True if API key is valid
     */
    public static function verify_api_key($request) {
        // Get API key from request header
        $provided_key = $request->get_header('X-Api-Key');

        if (empty($provided_key)) {
            return false;
        }

        // Get stored key
        $stored_key = self::get_api_key();

        if ($stored_key === false) {
            return false;
        }

        // Use constant-time comparison to prevent timing attacks
        return hash_equals($stored_key, $provided_key);
    }

    /**
     * Verify HMAC signature from request
     *
     * Full HMAC verification for command endpoints. Validates:
     * 1. Timestamp is within acceptable window (5 minutes)
     * 2. HMAC signature matches expected value
     *
     * Signature is calculated as: HMAC-SHA256(timestamp + body, api_key)
     *
     * @param WP_REST_Request $request The REST API request object
     * @return bool|WP_Error True if valid, WP_Error with reason if invalid
     */
    public static function verify_hmac($request) {
        // Get required headers
        $timestamp = $request->get_header('X-Timestamp');
        $signature = $request->get_header('X-Signature');

        // Validate headers exist
        if (empty($timestamp) || empty($signature)) {
            return new WP_Error(
                'missing_auth_headers',
                'Missing X-Timestamp or X-Signature header',
                ['status' => 401]
            );
        }

        // Validate timestamp is numeric
        if (!is_numeric($timestamp)) {
            return new WP_Error(
                'invalid_timestamp',
                'X-Timestamp must be a Unix timestamp',
                ['status' => 401]
            );
        }

        // Check timestamp is within acceptable window (5 minutes)
        $timestamp_int = intval($timestamp);
        $current_time = time();
        $time_diff = abs($current_time - $timestamp_int);

        if ($time_diff > self::$max_timestamp_age) {
            return new WP_Error(
                'timestamp_expired',
                'Request timestamp is outside acceptable window',
                ['status' => 401]
            );
        }

        // Get API key
        $api_key = self::get_api_key();

        if ($api_key === false) {
            return new WP_Error(
                'no_api_key',
                'API key not configured',
                ['status' => 500]
            );
        }

        // Build payload: timestamp + request body
        $body = $request->get_body();
        $payload = $timestamp . $body;

        // Calculate expected HMAC signature
        $expected_signature = hash_hmac('sha256', $payload, $api_key);

        // Use constant-time comparison to prevent timing attacks
        if (!hash_equals($expected_signature, $signature)) {
            return new WP_Error(
                'invalid_signature',
                'HMAC signature verification failed',
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Get masked API key for admin display
     *
     * Shows only last 8 characters for verification purposes.
     *
     * @return string Masked key like "********ABCD1234"
     */
    public static function get_key_display() {
        return get_option(self::$key_hash, 'Not generated');
    }

    /**
     * Check if API key exists
     *
     * @return bool True if API key is configured
     */
    public static function has_api_key() {
        return !empty(get_option(self::$option_key));
    }

    /**
     * Regenerate API key
     *
     * Deletes existing key and generates new one.
     * Use with caution - existing integrations will break.
     *
     * @return string The new raw API key
     */
    public static function regenerate_api_key() {
        // Delete existing keys
        delete_option(self::$option_key);
        delete_option(self::$key_hash);

        // Generate and return new key
        return self::generate_api_key();
    }

    /**
     * Get encryption key from WordPress salts
     *
     * Uses LOGGED_IN_SALT if available, falls back to AUTH_KEY.
     * Key is hashed to ensure proper length for AES-256.
     *
     * @return string 32-byte encryption key
     */
    private static function get_encryption_key() {
        // Prefer LOGGED_IN_SALT, fall back to AUTH_KEY
        if (defined('LOGGED_IN_SALT') && LOGGED_IN_SALT !== '') {
            $salt = LOGGED_IN_SALT;
        } elseif (defined('AUTH_KEY') && AUTH_KEY !== '') {
            $salt = AUTH_KEY;
        } else {
            // Fallback - should never happen in proper WP install
            $salt = 'wp-care-fallback-salt-' . ABSPATH;
        }

        // Hash to get consistent 32-byte key for AES-256
        return hash('sha256', $salt, true);
    }

    /**
     * Delete all API key data
     *
     * Called during plugin uninstall.
     */
    public static function delete_api_key() {
        delete_option(self::$option_key);
        delete_option(self::$key_hash);
    }
}
