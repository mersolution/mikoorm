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
 * FormCrypt - authenticated encryption for form / URL values.
 *
 * AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC), keys derived from
 * ENCRYPTION_KEY (.env, at least 16 characters) with HKDF. There is no
 * built-in fallback key. Output: "v2:" + base64url(iv | mac | ciphertext).
 *
 * FormCrypt::encrypt($data);
 * FormCrypt::decrypt($payload);   // false when tampered / wrong key
 */
class FormCrypt
{
    private const CIPHER = 'AES-256-CBC';
    private const IV_LENGTH = 16;
    private const MAC_LENGTH = 32;
    private const PREFIX = 'v2:';

    private static ?string $key = null;

    private static function getKey(): string
    {
        if (self::$key === null) {
            $key = (string) env('ENCRYPTION_KEY', '');
            if (strlen($key) < 16) {
                throw new \RuntimeException('ENCRYPTION_KEY is missing or too short (min 16 characters). Set it in .env or call FormCrypt::setKey().');
            }
            self::$key = $key;
        }
        return self::$key;
    }

    public static function setKey(string $key): void
    {
        if (strlen($key) < 16) {
            throw new \InvalidArgumentException('Encryption key must be at least 16 characters.');
        }
        self::$key = $key;
    }

    /**
     * @return array{0: string, 1: string} [encryption key, mac key]
     */
    private static function keys(): array
    {
        $master = self::getKey();
        return [
            hash_hkdf('sha256', $master, 32, 'miko-formcrypt-enc'),
            hash_hkdf('sha256', $master, 32, 'miko-formcrypt-mac'),
        ];
    }

    public static function encrypt(string $data): string
    {
        [$encKey, $macKey] = self::keys();
        $iv = random_bytes(self::IV_LENGTH);
        $cipher = openssl_encrypt($data, self::CIPHER, $encKey, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed');
        }

        $mac = hash_hmac('sha256', $iv . $cipher, $macKey, true);

        return self::PREFIX . rtrim(strtr(base64_encode($iv . $mac . $cipher), '+/', '-_'), '=');
    }

    /**
     * @return string|false Decrypted data, false when invalid or tampered
     */
    public static function decrypt(string $payload): string|false
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            return false;
        }

        $encoded = strtr(substr($payload, strlen(self::PREFIX)), '-_', '+/');
        $raw = base64_decode($encoded . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        if ($raw === false || strlen($raw) <= self::IV_LENGTH + self::MAC_LENGTH) {
            return false;
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $mac = substr($raw, self::IV_LENGTH, self::MAC_LENGTH);
        $cipher = substr($raw, self::IV_LENGTH + self::MAC_LENGTH);

        [$encKey, $macKey] = self::keys();
        if (!hash_equals(hash_hmac('sha256', $iv . $cipher, $macKey, true), $mac)) {
            return false;
        }

        return openssl_decrypt($cipher, self::CIPHER, $encKey, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Decrypt a 1.x payload (no MAC). Only for migrating old values - re-encrypt them with encrypt().
     */
    public static function decryptLegacy(string $encryptedData): string|false
    {
        $decoded = base64_decode($encryptedData, true);
        if ($decoded === false || strlen($decoded) <= self::IV_LENGTH) {
            return false;
        }

        return openssl_decrypt(substr($decoded, self::IV_LENGTH), self::CIPHER, self::getKey(), 0, substr($decoded, 0, self::IV_LENGTH));
    }

    /**
     * Random key of $length hex characters (default 64 = 256 bit)
     */
    public static function generateKey(int $length = 64): string
    {
        $length = max(16, $length);
        return substr(bin2hex(random_bytes(intdiv($length + 1, 2))), 0, $length);
    }
}
