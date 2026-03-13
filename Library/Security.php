<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * Security Library - Input sanitization, XSS prevention, CSRF protection
 * Similar to mersolutionCore Security.cs
 */

namespace Miko\Library;

class Security
{
    private static ?string $csrfToken = null;
    private static string $csrfTokenName = '_csrf_token';

    // ========================================
    // Input Sanitization
    // ========================================

    /**
     * Sanitize string (remove HTML tags, trim)
     */
    public static function sanitize(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize for HTML output (XSS prevention)
     */
    public static function escape(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Alias for escape
     */
    public static function e(string $input): string
    {
        return self::escape($input);
    }

    /**
     * Sanitize email
     */
    public static function sanitizeEmail(string $email): string
    {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }

    /**
     * Sanitize URL
     */
    public static function sanitizeUrl(string $url): string
    {
        return filter_var(trim($url), FILTER_SANITIZE_URL);
    }

    /**
     * Sanitize integer
     */
    public static function sanitizeInt(mixed $value): int
    {
        return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Sanitize float
     */
    public static function sanitizeFloat(mixed $value): float
    {
        return (float) filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }

    /**
     * Sanitize filename (remove dangerous characters)
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove path traversal
        $filename = basename($filename);
        // Remove dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        // Remove multiple underscores
        $filename = preg_replace('/_+/', '_', $filename);
        return $filename;
    }

    /**
     * Sanitize array recursively
     */
    public static function sanitizeArray(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $key = self::sanitize((string) $key);
            if (is_array($value)) {
                $result[$key] = self::sanitizeArray($value);
            } elseif (is_string($value)) {
                $result[$key] = self::sanitize($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    // ========================================
    // Validation
    // ========================================

    /**
     * Validate email
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate URL
     */
    public static function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate IP address
     */
    public static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate integer
     */
    public static function isValidInt(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Validate Turkish phone number
     */
    public static function isValidPhoneTR(string $phone): bool
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        return preg_match('/^(05|5)[0-9]{9}$/', $phone) === 1;
    }

    /**
     * Validate Turkish ID number (TC Kimlik No)
     */
    public static function isValidTcNo(string $tcNo): bool
    {
        if (!preg_match('/^[1-9][0-9]{10}$/', $tcNo)) {
            return false;
        }

        $digits = str_split($tcNo);
        $digits = array_map('intval', $digits);

        // Algorithm check
        $oddSum = $digits[0] + $digits[2] + $digits[4] + $digits[6] + $digits[8];
        $evenSum = $digits[1] + $digits[3] + $digits[5] + $digits[7];
        
        $check10 = (($oddSum * 7) - $evenSum) % 10;
        if ($check10 !== $digits[9]) {
            return false;
        }

        $totalSum = 0;
        for ($i = 0; $i < 10; $i++) {
            $totalSum += $digits[$i];
        }
        
        return ($totalSum % 10) === $digits[10];
    }

    /**
     * Validate Turkish Tax Number (Vergi No)
     */
    public static function isValidTaxNo(string $taxNo): bool
    {
        return preg_match('/^[0-9]{10}$/', $taxNo) === 1;
    }

    // ========================================
    // CSRF Protection
    // ========================================

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        self::$csrfToken = Crypto::randomHex(32);
        $_SESSION[self::$csrfTokenName] = self::$csrfToken;

        return self::$csrfToken;
    }

    /**
     * Get current CSRF token
     */
    public static function getCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::$csrfTokenName])) {
            return self::generateCsrfToken();
        }

        return $_SESSION[self::$csrfTokenName];
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCsrfToken(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::$csrfTokenName])) {
            return false;
        }

        return Crypto::equals($_SESSION[self::$csrfTokenName], $token);
    }

    /**
     * Get CSRF hidden input field
     */
    public static function csrfField(): string
    {
        $token = self::getCsrfToken();
        return '<input type="hidden" name="' . self::$csrfTokenName . '" value="' . self::escape($token) . '">';
    }

    // ========================================
    // SQL Injection Prevention
    // ========================================

    /**
     * Escape for SQL LIKE clause
     */
    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    // ========================================
    // Rate Limiting
    // ========================================

    /**
     * Simple rate limiter (requires session)
     */
    public static function rateLimit(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $rateLimitKey = 'rate_limit_' . $key;
        $now = time();

        if (!isset($_SESSION[$rateLimitKey])) {
            $_SESSION[$rateLimitKey] = [
                'attempts' => 0,
                'reset_at' => $now + $decaySeconds
            ];
        }

        $data = &$_SESSION[$rateLimitKey];

        // Reset if expired
        if ($now >= $data['reset_at']) {
            $data['attempts'] = 0;
            $data['reset_at'] = $now + $decaySeconds;
        }

        // Check limit
        if ($data['attempts'] >= $maxAttempts) {
            return false;
        }

        $data['attempts']++;
        return true;
    }

    /**
     * Get remaining attempts
     */
    public static function getRemainingAttempts(string $key, int $maxAttempts): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $rateLimitKey = 'rate_limit_' . $key;

        if (!isset($_SESSION[$rateLimitKey])) {
            return $maxAttempts;
        }

        return max(0, $maxAttempts - $_SESSION[$rateLimitKey]['attempts']);
    }

    // ========================================
    // IP & User Agent
    // ========================================

    /**
     * Get client IP address
     */
    public static function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (self::isValidIp($ip)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Get user agent
     */
    public static function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Check if request is from bot
     */
    public static function isBot(): bool
    {
        $userAgent = strtolower(self::getUserAgent());
        $bots = ['bot', 'crawler', 'spider', 'slurp', 'googlebot', 'bingbot', 'yandex'];
        
        foreach ($bots as $bot) {
            if (strpos($userAgent, $bot) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
