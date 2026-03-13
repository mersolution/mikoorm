<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * Crypto Library - Encryption, Hashing, and Security utilities
 * Similar to mersolutionCore Crypto.cs
 */

namespace Miko\Library;

class Crypto
{
    private static string $defaultCipher = 'AES-256-CBC';
    private static ?string $encryptionKey = null;

    /**
     * Set encryption key
     */
    public static function setKey(string $key): void
    {
        self::$encryptionKey = $key;
    }

    /**
     * Get encryption key (from config or set key)
     */
    private static function getKey(): string
    {
        if (self::$encryptionKey !== null) {
            return self::$encryptionKey;
        }

        // Try to get from config
        if (class_exists('\Miko\Core\Config')) {
            $key = \Miko\Core\Config::env('APP_KEY', '');
            if (!empty($key)) {
                return $key;
            }
        }

        throw new \RuntimeException('Encryption key not set. Call Crypto::setKey() or set APP_KEY in .env');
    }

    // ========================================
    // Hashing
    // ========================================

    /**
     * Create password hash (bcrypt)
     */
    public static function hashPassword(string $password, int $cost = 12): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    /**
     * Verify password against hash
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if password needs rehash
     */
    public static function needsRehash(string $hash, int $cost = 12): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    /**
     * Create MD5 hash
     */
    public static function md5(string $data): string
    {
        return md5($data);
    }

    /**
     * Create SHA1 hash
     */
    public static function sha1(string $data): string
    {
        return sha1($data);
    }

    /**
     * Create SHA256 hash
     */
    public static function sha256(string $data): string
    {
        return hash('sha256', $data);
    }

    /**
     * Create SHA512 hash
     */
    public static function sha512(string $data): string
    {
        return hash('sha512', $data);
    }

    /**
     * Create HMAC hash
     */
    public static function hmac(string $data, string $key, string $algo = 'sha256'): string
    {
        return hash_hmac($algo, $data, $key);
    }

    // ========================================
    // Encryption / Decryption
    // ========================================

    /**
     * Encrypt data using AES-256-CBC
     */
    public static function encrypt(string $data, ?string $key = null): string
    {
        $key = $key ?? self::getKey();
        $key = self::deriveKey($key);
        
        $iv = random_bytes(openssl_cipher_iv_length(self::$defaultCipher));
        $encrypted = openssl_encrypt($data, self::$defaultCipher, $key, OPENSSL_RAW_DATA, $iv);
        
        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Prepend IV to encrypted data and encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data
     */
    public static function decrypt(string $data, ?string $key = null): string
    {
        $key = $key ?? self::getKey();
        $key = self::deriveKey($key);
        
        $data = base64_decode($data);
        if ($data === false) {
            throw new \RuntimeException('Invalid encrypted data');
        }

        $ivLength = openssl_cipher_iv_length(self::$defaultCipher);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);

        $decrypted = openssl_decrypt($encrypted, self::$defaultCipher, $key, OPENSSL_RAW_DATA, $iv);
        
        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $decrypted;
    }

    /**
     * Derive key to proper length
     */
    private static function deriveKey(string $key): string
    {
        return hash('sha256', $key, true);
    }

    // ========================================
    // Token Generation
    // ========================================

    /**
     * Generate random bytes
     */
    public static function randomBytes(int $length = 32): string
    {
        return random_bytes($length);
    }

    /**
     * Generate random hex string
     */
    public static function randomHex(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Generate random string (alphanumeric)
     */
    public static function randomString(int $length = 32): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $result = '';
        $max = strlen($chars) - 1;
        
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }
        
        return $result;
    }

    /**
     * Generate UUID v4
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        
        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generate secure token (for remember me, API keys, etc.)
     */
    public static function generateToken(int $length = 64): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Generate OTP (One-Time Password)
     */
    public static function generateOtp(int $digits = 6): string
    {
        $min = pow(10, $digits - 1);
        $max = pow(10, $digits) - 1;
        return (string) random_int($min, $max);
    }

    // ========================================
    // Base64 Encoding
    // ========================================

    /**
     * Base64 encode
     */
    public static function base64Encode(string $data): string
    {
        return base64_encode($data);
    }

    /**
     * Base64 decode
     */
    public static function base64Decode(string $data): string
    {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64 data');
        }
        return $decoded;
    }

    /**
     * URL-safe base64 encode
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL-safe base64 decode
     */
    public static function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode($data);
    }

    // ========================================
    // Signature
    // ========================================

    /**
     * Create signature for data
     */
    public static function sign(string $data, ?string $key = null): string
    {
        $key = $key ?? self::getKey();
        return self::hmac($data, $key, 'sha256');
    }

    /**
     * Verify signature
     */
    public static function verifySignature(string $data, string $signature, ?string $key = null): bool
    {
        $key = $key ?? self::getKey();
        $expected = self::hmac($data, $key, 'sha256');
        return hash_equals($expected, $signature);
    }

    // ========================================
    // Timing-safe comparison
    // ========================================

    /**
     * Timing-safe string comparison
     */
    public static function equals(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }
}
