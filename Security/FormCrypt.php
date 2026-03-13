<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Security;

use function Miko\Core\env;

/**
 * FormCrypt - AES-256-CBC encryption for form data
 * 
 * Usage:
 * FormCrypt::encrypt($data);
 * FormCrypt::decrypt($encryptedData);
 */
class FormCrypt
{
    private static ?string $key = null;
    private const CIPHER = 'AES-256-CBC';
    private const IV_LENGTH = 16;

    /**
     * Get encryption key from environment
     * 
     * @return string
     */
    private static function getKey(): string
    {
        if (self::$key === null) {
            self::$key = env('ENCRYPTION_KEY', '0e36d681957e28c729d5c8ffb9efb1e4');
        }
        return self::$key;
    }

    /**
     * Set custom encryption key
     * 
     * @param string $key
     * @return void
     */
    public static function setKey(string $key): void
    {
        self::$key = $key;
    }

    /**
     * Encrypt data
     * 
     * @param string $data
     * @return string Base64 encoded encrypted data
     */
    public static function encrypt(string $data): string
    {
        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);
        $encrypted = openssl_encrypt($data, self::CIPHER, self::getKey(), 0, $iv);
        
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data
     * 
     * @param string $encryptedData Base64 encoded encrypted data
     * @return string|false Decrypted data or false on failure
     */
    public static function decrypt(string $encryptedData): string|false
    {
        $decoded = base64_decode($encryptedData);
        
        if ($decoded === false) {
            return false;
        }
        
        $iv = substr($decoded, 0, self::IV_LENGTH);
        $encrypted = substr($decoded, self::IV_LENGTH);
        
        return openssl_decrypt($encrypted, self::CIPHER, self::getKey(), 0, $iv);
    }

    /**
     * Generate a secure random key
     * 
     * @param int $length
     * @return string
     */
    public static function generateKey(int $length = 32): string
    {
        return bin2hex(openssl_random_pseudo_bytes($length / 2));
    }
}
