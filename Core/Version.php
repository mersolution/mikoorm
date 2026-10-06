<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Core;

/**
 * Library version
 */
final class Version
{
    public const VERSION = '2.1.0';

    public static function get(): string
    {
        return self::VERSION;
    }
}
