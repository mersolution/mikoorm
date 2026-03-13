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

/**
 * CodeGenerator - Generate unique reference codes
 * 
 * Usage:
 * CodeGenerator::generate(123, 5, 'TRF');  // TRF-5-124
 * CodeGenerator::generate(1, 10, 'ORD');   // ORD-10-002
 */
class CodeGenerator
{
    /**
     * Generate a unique reference code
     * 
     * @param int $lastId Last inserted ID
     * @param int $companyId Company ID
     * @param string $prefix Code prefix (e.g., 'TRF', 'ORD', 'INV')
     * @param int $padding Number padding (default: 3)
     * @return string Generated code (e.g., 'TRF-5-001')
     */
    public static function generate(int $lastId, int $companyId, string $prefix, int $padding = 3): string
    {
        $nextId = $lastId + 1;
        return sprintf("%s-%d-%0{$padding}d", $prefix, $companyId, $nextId);
    }

    /**
     * Generate a unique reference code with date
     * 
     * @param int $lastId Last inserted ID
     * @param string $prefix Code prefix
     * @param int $padding Number padding (default: 4)
     * @return string Generated code (e.g., 'INV-20260126-0001')
     */
    public static function generateWithDate(int $lastId, string $prefix, int $padding = 4): string
    {
        $nextId = $lastId + 1;
        $date = date('Ymd');
        return sprintf("%s-%s-%0{$padding}d", $prefix, $date, $nextId);
    }

    /**
     * Generate a random alphanumeric code
     * 
     * @param int $length Code length (default: 8)
     * @param bool $uppercase Use uppercase only (default: true)
     * @return string Random code (e.g., 'A7X9K2M4')
     */
    public static function random(int $length = 8, bool $uppercase = true): string
    {
        $chars = $uppercase 
            ? '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'
            : '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        
        $code = '';
        $max = strlen($chars) - 1;
        
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        
        return $code;
    }

    /**
     * Generate UUID v4
     * 
     * @return string UUID (e.g., '550e8400-e29b-41d4-a716-446655440000')
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
