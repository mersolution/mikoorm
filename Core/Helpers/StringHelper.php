<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Core\Helpers;

class StringHelper
{
    public static function camelToSnake(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    public static function snakeToCamel(string $input): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $input))));
    }

    public static function snakeToPascal(string $input): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $input)));
    }

    public static function truncate(string $text, int $length, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . $suffix;
    }

    public static function slug(string $text, string $separator = '-'): string
    {
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        $text = preg_replace('/\s+/', $separator, trim($text));
        return mb_strtolower($text);
    }

    /**
     * SEO uyumlu URL oluştur (Türkçe karakterleri ASCII'ye çevirir)
     * 
     * @param string $text
     * @param string $separator
     * @return string
     */
    public static function seoSlug(string $text, string $separator = '-'): string
    {
        $text = mb_strtolower($text);
        
        // Türkçe karakterleri ASCII'ye çevir
        $turkish = ['ç', 'ğ', 'ı', 'ö', 'ş', 'ü', 'Ç', 'Ğ', 'İ', 'Ö', 'Ş', 'Ü'];
        $ascii = ['c', 'g', 'i', 'o', 's', 'u', 'c', 'g', 'i', 'o', 's', 'u'];
        $text = str_replace($turkish, $ascii, $text);
        
        // Alfanumerik olmayan karakterleri kaldır
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        
        // Boşlukları ve çoklu tire'leri tek tire yap
        $text = preg_replace('/[\s-]+/', $separator, $text);
        
        return trim($text, $separator);
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) !== false;
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    public static function endsWith(string $haystack, string $needle): bool
    {
        $length = strlen($needle);
        if ($length === 0) {
            return true;
        }
        return substr($haystack, -$length) === $needle;
    }

    public static function random(int $length = 16): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';
        $max = strlen($characters) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[random_int(0, $max)];
        }
        return $result;
    }

    public static function mask(string $text, int $visibleStart = 2, int $visibleEnd = 2, string $maskChar = '*'): string
    {
        $length = mb_strlen($text);
        if ($length <= $visibleStart + $visibleEnd) {
            return $text;
        }
        $start = mb_substr($text, 0, $visibleStart);
        $end = mb_substr($text, -$visibleEnd);
        $maskLength = $length - $visibleStart - $visibleEnd;
        return $start . str_repeat($maskChar, $maskLength) . $end;
    }
}
