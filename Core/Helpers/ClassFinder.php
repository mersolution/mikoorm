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
 * Finds the first class declared in a PHP file (tokenizer based, ignores comments and strings)
 */
final class ClassFinder
{
    public static function fromFile(string $file): ?string
    {
        $code = @file_get_contents($file);
        return $code === false ? null : self::fromCode($code);
    }

    public static function fromCode(string $code): ?string
    {
        $tokens = token_get_all($code);
        $namespace = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $tokens[$j][1];
                    } elseif ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }
                }
            }

            if ($tokens[$i][0] === T_CLASS) {
                // Skip "Foo::class" and anonymous classes
                $previous = self::previousSignificant($tokens, $i);
                if ($previous === T_DOUBLE_COLON || $previous === T_NEW) {
                    continue;
                }

                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        return ($namespace !== '' ? $namespace . '\\' : '') . $tokens[$j][1];
                    }
                    if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }
            }
        }

        return null;
    }

    private static function previousSignificant(array $tokens, int $index): int|string|null
    {
        for ($k = $index - 1; $k >= 0; $k--) {
            $token = $tokens[$k];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($token) ? $token[0] : $token;
        }
        return null;
    }
}
