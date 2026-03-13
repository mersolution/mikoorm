<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * TextHelper Library - String manipulation utilities
 * Similar to mersolutionCore TextHelper.cs
 */

namespace Miko\Library;

class TextHelper
{
    // ========================================
    // Case Conversion
    // ========================================

    /**
     * Convert to camelCase
     */
    public static function camelCase(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return lcfirst(str_replace(' ', '', $value));
    }

    /**
     * Convert to PascalCase (StudlyCase)
     */
    public static function pascalCase(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }

    /**
     * Convert to snake_case
     */
    public static function snakeCase(string $value): string
    {
        $value = preg_replace('/([a-z])([A-Z])/', '$1_$2', $value);
        return strtolower(str_replace(['-', ' '], '_', $value));
    }

    /**
     * Convert to kebab-case
     */
    public static function kebabCase(string $value): string
    {
        $value = preg_replace('/([a-z])([A-Z])/', '$1-$2', $value);
        return strtolower(str_replace(['_', ' '], '-', $value));
    }

    /**
     * Convert to Title Case
     */
    public static function titleCase(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Convert to UPPER CASE
     */
    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * Convert to lower case
     */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    // ========================================
    // String Manipulation
    // ========================================

    /**
     * Truncate string with ellipsis
     */
    public static function truncate(string $value, int $length, string $end = '...'): string
    {
        if (mb_strlen($value, 'UTF-8') <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - mb_strlen($end, 'UTF-8'), 'UTF-8') . $end;
    }

    /**
     * Limit words
     */
    public static function words(string $value, int $words, string $end = '...'): string
    {
        preg_match('/^\s*+(?:\S++\s*+){1,' . $words . '}/u', $value, $matches);

        if (!isset($matches[0]) || mb_strlen($value, 'UTF-8') === mb_strlen($matches[0], 'UTF-8')) {
            return $value;
        }

        return rtrim($matches[0]) . $end;
    }

    /**
     * Get excerpt from text
     */
    public static function excerpt(string $text, string $phrase, int $radius = 100, string $end = '...'): string
    {
        $phraseLen = mb_strlen($phrase, 'UTF-8');
        $textLen = mb_strlen($text, 'UTF-8');

        $pos = mb_stripos($text, $phrase, 0, 'UTF-8');

        if ($pos === false) {
            return self::truncate($text, $radius * 2, $end);
        }

        $start = max($pos - $radius, 0);
        $length = min($phraseLen + ($radius * 2), $textLen - $start);

        $excerpt = mb_substr($text, $start, $length, 'UTF-8');

        if ($start > 0) {
            $excerpt = $end . ltrim($excerpt);
        }

        if ($start + $length < $textLen) {
            $excerpt = rtrim($excerpt) . $end;
        }

        return $excerpt;
    }

    /**
     * Pad string
     */
    public static function padLeft(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_LEFT);
    }

    public static function padRight(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_RIGHT);
    }

    public static function padBoth(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_BOTH);
    }

    /**
     * Repeat string
     */
    public static function repeat(string $value, int $times): string
    {
        return str_repeat($value, $times);
    }

    /**
     * Reverse string
     */
    public static function reverse(string $value): string
    {
        preg_match_all('/./us', $value, $matches);
        return implode('', array_reverse($matches[0]));
    }

    /**
     * Replace first occurrence
     */
    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);
        if ($pos !== false) {
            return substr_replace($subject, $replace, $pos, strlen($search));
        }
        return $subject;
    }

    /**
     * Replace last occurrence
     */
    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        $pos = strrpos($subject, $search);
        if ($pos !== false) {
            return substr_replace($subject, $replace, $pos, strlen($search));
        }
        return $subject;
    }

    // ========================================
    // String Checks
    // ========================================

    /**
     * Check if string starts with
     */
    public static function startsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if string ends with
     */
    public static function endsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if string contains
     */
    public static function contains(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if string contains all
     */
    public static function containsAll(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if string is empty or whitespace
     */
    public static function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /**
     * Check if string is not empty
     */
    public static function isNotBlank(?string $value): bool
    {
        return !self::isBlank($value);
    }

    // ========================================
    // Slug & URL
    // ========================================

    /**
     * Generate URL-friendly slug
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        // Turkish character map
        $map = [
            'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's',
            'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u',
            'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
            'ä' => 'a', 'Ä' => 'a', 'ë' => 'e', 'Ë' => 'e',
        ];

        $value = strtr($value, $map);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9\s-]/', '', $value);
        $value = preg_replace('/[\s-]+/', $separator, $value);
        $value = trim($value, $separator);

        return $value;
    }

    /**
     * Generate ASCII slug
     */
    public static function ascii(string $value): string
    {
        $value = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
        return preg_replace('/[^a-z0-9]/', '', $value);
    }

    // ========================================
    // Formatting
    // ========================================

    /**
     * Format phone number (Turkish)
     */
    public static function formatPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) === 10) {
            return sprintf('(%s) %s %s %s',
                substr($phone, 0, 3),
                substr($phone, 3, 3),
                substr($phone, 6, 2),
                substr($phone, 8, 2)
            );
        }
        
        if (strlen($phone) === 11 && $phone[0] === '0') {
            return sprintf('(%s) %s %s %s',
                substr($phone, 1, 3),
                substr($phone, 4, 3),
                substr($phone, 7, 2),
                substr($phone, 9, 2)
            );
        }

        return $phone;
    }

    /**
     * Format money (Turkish Lira)
     */
    public static function formatMoney(float $amount, string $currency = '₺', int $decimals = 2): string
    {
        return number_format($amount, $decimals, ',', '.') . ' ' . $currency;
    }

    /**
     * Format number
     */
    public static function formatNumber(float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals, ',', '.');
    }

    /**
     * Format file size
     */
    public static function formatFileSize(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Format date (Turkish)
     */
    public static function formatDate(string|\DateTime $date, string $format = 'd.m.Y'): string
    {
        if (is_string($date)) {
            $date = new \DateTime($date);
        }
        return $date->format($format);
    }

    /**
     * Format datetime (Turkish)
     */
    public static function formatDateTime(string|\DateTime $date, string $format = 'd.m.Y H:i'): string
    {
        return self::formatDate($date, $format);
    }

    /**
     * Time ago (relative time)
     */
    public static function timeAgo(string|\DateTime $date): string
    {
        if (is_string($date)) {
            $date = new \DateTime($date);
        }

        $now = new \DateTime();
        $diff = $now->diff($date);

        if ($diff->y > 0) return $diff->y . ' yıl önce';
        if ($diff->m > 0) return $diff->m . ' ay önce';
        if ($diff->d > 0) return $diff->d . ' gün önce';
        if ($diff->h > 0) return $diff->h . ' saat önce';
        if ($diff->i > 0) return $diff->i . ' dakika önce';
        return 'Az önce';
    }

    // ========================================
    // Masking
    // ========================================

    /**
     * Mask string (for sensitive data)
     */
    public static function mask(string $value, string $char = '*', int $visibleStart = 0, int $visibleEnd = 0): string
    {
        $length = mb_strlen($value, 'UTF-8');
        
        if ($length <= $visibleStart + $visibleEnd) {
            return str_repeat($char, $length);
        }

        $start = mb_substr($value, 0, $visibleStart, 'UTF-8');
        $end = $visibleEnd > 0 ? mb_substr($value, -$visibleEnd, null, 'UTF-8') : '';
        $middle = str_repeat($char, $length - $visibleStart - $visibleEnd);

        return $start . $middle . $end;
    }

    /**
     * Mask email
     */
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $name = $parts[0];
        $domain = $parts[1];

        $maskedName = self::mask($name, '*', 2, 1);
        
        return $maskedName . '@' . $domain;
    }

    /**
     * Mask phone
     */
    public static function maskPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        return self::mask($phone, '*', 3, 2);
    }

    /**
     * Mask credit card
     */
    public static function maskCreditCard(string $card): string
    {
        $card = preg_replace('/[^0-9]/', '', $card);
        return self::mask($card, '*', 4, 4);
    }

    // ========================================
    // Pluralization (Turkish)
    // ========================================

    /**
     * Pluralize word (Turkish)
     */
    public static function plural(string $word, int $count): string
    {
        if ($count === 1) {
            return $word;
        }

        // Turkish vowel harmony
        $lastVowel = '';
        preg_match('/[aeıioöuü]/ui', strrev($word), $matches);
        if (!empty($matches)) {
            $lastVowel = mb_strtolower($matches[0], 'UTF-8');
        }

        $suffix = match($lastVowel) {
            'a', 'ı' => 'lar',
            'e', 'i' => 'ler',
            'o', 'u' => 'lar',
            'ö', 'ü' => 'ler',
            default => 'ler'
        };

        return $word . $suffix;
    }
}
