<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Async;

use Miko\Database\Exceptions\DatabaseException;

/**
 * Finds the "?" and ":name" placeholders of a statement (outside string literals,
 * quoted identifiers, comments and PostgreSQL dollar quotes) so the async drivers
 * can bind them: inlined as escaped literals for mysqli, as $1..$n for pgsql.
 *
 * Same rules as PDO: "??" outside literals is a literal "?", ":name" is a placeholder
 * only when a binding with that name exists, "::type" casts are left alone.
 */
final class SqlBinder
{
    /** @var array<string, list<string|array{0: string, 1: int|string}>> */
    private static array $cache = [];

    /**
     * Split SQL into text and placeholder parts
     *
     * @return list<string|array{0: string, 1: int|string}> text, or ['?', index] / [':', name]
     */
    public static function parse(string $sql, string $driver, array $bindings): array
    {
        $named = [];
        foreach ($bindings as $key => $_) {
            if (is_string($key) && $key !== '') {
                $named[ltrim($key, ':')] = true;
            }
        }

        $cacheKey = $driver . "\0" . implode(',', array_keys($named)) . "\0" . $sql;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $parts = [];
        $text = '';
        $length = strlen($sql);
        $position = 0;
        $mysql = $driver === 'mysql';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            // string literals and quoted identifiers ([name] on SQL Server)
            if ($char === "'" || $char === '"' || ($char === '`' && $mysql) || ($char === '[' && $driver === 'sqlsrv')) {
                $end = self::skipQuoted($sql, $i, $char === '[' ? ']' : $char, $mysql && $char !== '`');
                $text .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }

            // comments
            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length - 1 : $end;
                $text .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
            if ($char === '#' && $mysql) {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length - 1 : $end;
                $text .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length - 1 : $end + 1;
                $text .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }

            // PostgreSQL dollar quoting: $$...$$ / $tag$...$tag$
            if ($char === '$' && $driver === 'pgsql' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i)) {
                $tag = $m[0];
                $end = strpos($sql, $tag, $i + strlen($tag));
                $end = $end === false ? $length - 1 : $end + strlen($tag) - 1;
                $text .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }

            if ($char === '?') {
                if ($next === '?') {
                    $text .= '?';
                    $i++;
                    continue;
                }
                $parts[] = $text;
                $text = '';
                $parts[] = ['?', $position++];
                continue;
            }

            if ($char === ':' && ($sql[$i - 1] ?? '') !== ':' && preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $m, 0, $i)) {
                if (isset($named[$m[1]])) {
                    $parts[] = $text;
                    $text = '';
                    $parts[] = [':', $m[1]];
                } else {
                    $text .= $m[0];
                }
                $i += strlen($m[0]) - 1;
                continue;
            }

            $text .= $char;
        }

        $parts[] = $text;
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

        if (count(self::$cache) > 500) {
            self::$cache = [];
        }
        return self::$cache[$cacheKey] = $parts;
    }

    /**
     * Value of a placeholder part
     */
    public static function valueFor(array $part, array $bindings, array $positional): mixed
    {
        if ($part[0] === '?') {
            if (!array_key_exists($part[1], $positional)) {
                throw new DatabaseException('Not enough values for the "?" placeholders of the query.');
            }
            return $positional[$part[1]];
        }

        $name = $part[1];
        if (array_key_exists(':' . $name, $bindings)) {
            return $bindings[':' . $name];
        }
        return $bindings[$name];
    }

    /**
     * Positional values in order (bindings with integer keys)
     */
    public static function positional(array $bindings): array
    {
        $values = [];
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * Check the "?" count against the positional values (PDO HY093)
     */
    public static function assertCount(array $parts, array $positional): void
    {
        $count = 0;
        foreach ($parts as $part) {
            if (is_array($part) && $part[0] === '?') {
                $count++;
            }
        }
        if ($count !== count($positional)) {
            throw new DatabaseException("The query has {$count} \"?\" placeholders but " . count($positional) . ' positional values.');
        }
    }

    /**
     * MySQL form: values inlined as literals, typed like PDO (int as number, bool as 1/0,
     * null as NULL, everything else - floats too - as a string quoted by $quote)
     *
     * @param callable(string): string $quote
     */
    public static function inlined(string $sql, array $bindings, callable $quote): string
    {
        $parts = self::parse($sql, 'mysql', $bindings);
        $positional = self::positional($bindings);
        self::assertCount($parts, $positional);

        $out = '';
        foreach ($parts as $part) {
            if (is_string($part)) {
                $out .= $part;
                continue;
            }
            $value = self::normalize(self::valueFor($part, $bindings, $positional));
            $out .= match (true) {
                $value === null => 'NULL',
                is_bool($value) => $value ? '1' : '0',
                is_int($value) => (string) $value,
                default => $quote((string) $value),
            };
        }
        return $out;
    }

    /**
     * PostgreSQL form: "?" / ":name" -> $1..$n with the values in order as text
     * (a repeated name reuses its number, bool as t/f like PDO::PARAM_BOOL)
     *
     * @return array{0: string, 1: list<?string>}
     */
    public static function numbered(string $sql, array $bindings): array
    {
        [$out, $values] = self::marked($sql, 'pgsql', $bindings, '$');
        $params = [];
        foreach ($values as $value) {
            $params[] = match (true) {
                $value === null => null,
                is_bool($value) => $value ? 't' : 'f',
                default => (string) $value,
            };
        }
        return [$out, $params];
    }

    /**
     * "?" / ":name" -> {$prefix}1..n with the normalised values in order (a repeated name reuses its number)
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function marked(string $sql, string $driver, array $bindings, string $prefix): array
    {
        $parts = self::parse($sql, $driver, $bindings);
        $positional = self::positional($bindings);
        self::assertCount($parts, $positional);

        $out = '';
        $values = [];
        $numbers = [];

        foreach ($parts as $part) {
            if (is_string($part)) {
                $out .= $part;
                continue;
            }

            if ($part[0] === ':' && isset($numbers[$part[1]])) {
                $out .= $prefix . $numbers[$part[1]];
                continue;
            }

            $values[] = self::normalize(self::valueFor($part, $bindings, $positional));
            $out .= $prefix . count($values);
            if ($part[0] === ':') {
                $numbers[$part[1]] = count($values);
            }
        }

        return [$out, $values];
    }

    /**
     * Normalise a bound value the way PDO would accept it
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_resource($value)) {
            return (string) stream_get_contents($value);
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        throw new DatabaseException('Cannot bind a value of type ' . get_debug_type($value) . '.');
    }

    private static function skipQuoted(string $sql, int $start, string $quote, bool $backslashEscapes): int
    {
        $length = strlen($sql);
        for ($i = $start + 1; $i < $length; $i++) {
            $char = $sql[$i];
            if ($backslashEscapes && $char === '\\') {
                $i++;
                continue;
            }
            if ($char === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i++; // doubled quote
                    continue;
                }
                return $i;
            }
        }
        return $length - 1;
    }
}
