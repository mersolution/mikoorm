<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Query;

use Miko\Database\Exceptions\DatabaseException;

/**
 * Grammar - identifier validation / quoting and driver specific SQL fragments.
 *
 * Every column, table and operator that ends up in SQL text goes through this
 * class. Values never do: they are always bound as parameters.
 */
final class Grammar
{
    public const IDENT = '[A-Za-z_][A-Za-z0-9_]*';

    private const OPERATORS = [
        '=', '!=', '<>', '<', '>', '<=', '>=', '<=>',
        'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE',
    ];

    private const COMPARISONS = ['=', '!=', '<>', '<', '>', '<=', '>='];

    private const JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT', 'CROSS', 'LEFT OUTER', 'RIGHT OUTER', 'FULL', 'FULL OUTER'];

    private const AGGREGATE = '/^(COUNT|SUM|AVG|MIN|MAX)\s*\(\s*(DISTINCT\s+)?(\*|[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*){0,2})\s*\)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$/i';

    private static array $instances = [];

    private string $driver;

    private function __construct(string $driver)
    {
        $this->driver = $driver;
    }

    public static function for(string $driver): self
    {
        $driver = strtolower($driver);
        return self::$instances[$driver] ??= new self($driver);
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    // ========================================
    // Validation (driver independent)
    // ========================================

    public static function isIdentifier(string $value): bool
    {
        return (bool) preg_match('/^' . self::IDENT . '$/', $value);
    }

    public static function assertIdentifier(string $value, string $what = 'identifier'): string
    {
        if (!self::isIdentifier($value)) {
            throw new DatabaseException("Invalid {$what}: " . self::preview($value));
        }
        return $value;
    }

    /**
     * Plain column reference: col, tbl.col or schema.tbl.col
     */
    public static function assertReference(string $value): string
    {
        $value = trim($value);
        $parts = explode('.', $value);

        if (count($parts) > 3) {
            throw new DatabaseException('Invalid column name: ' . self::preview($value));
        }

        foreach ($parts as $part) {
            if (!self::isIdentifier($part)) {
                throw new DatabaseException('Invalid column name: ' . self::preview($value));
            }
        }

        return $value;
    }

    /**
     * Select-list item: reference, tbl.*, *, "x AS alias" or COUNT/SUM/AVG/MIN/MAX(...)
     */
    public static function assertColumn(string $value): string
    {
        if (self::parseColumn($value) === null) {
            throw new DatabaseException('Invalid column expression: ' . self::preview($value) . ' (use selectRaw/whereRaw for expressions)');
        }
        return trim($value);
    }

    /**
     * Table reference: tbl, schema.tbl, "tbl alias" or "tbl AS alias"
     */
    public static function assertTable(string $value): string
    {
        self::parseTable($value);
        return trim($value);
    }

    public static function operator(string $operator): string
    {
        $op = strtoupper(preg_replace('/\s+/', ' ', trim($operator)));
        if (!in_array($op, self::OPERATORS, true)) {
            throw new DatabaseException('Invalid operator: ' . self::preview($operator));
        }
        return $op;
    }

    public static function comparison(string $operator): string
    {
        $op = trim($operator);
        if (!in_array($op, self::COMPARISONS, true)) {
            throw new DatabaseException('Invalid comparison operator: ' . self::preview($operator));
        }
        return $op;
    }

    public static function joinType(string $type): string
    {
        $t = strtoupper(preg_replace('/\s+/', ' ', trim($type)));
        if (!in_array($t, self::JOIN_TYPES, true)) {
            throw new DatabaseException('Invalid join type: ' . self::preview($type));
        }
        return $t;
    }

    public static function direction(string $direction): string
    {
        $d = strtoupper(trim($direction));
        if ($d !== 'ASC' && $d !== 'DESC') {
            throw new DatabaseException('Invalid order direction: ' . self::preview($direction));
        }
        return $d;
    }

    // ========================================
    // Quoting
    // ========================================

    public function quote(string $identifier): string
    {
        return match ($this->driver) {
            'mysql', 'sqlite' => '`' . str_replace('`', '``', $identifier) . '`',
            'sqlsrv' => '[' . str_replace(']', ']]', $identifier) . ']',
            default => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }

    /**
     * Validate and quote a column / select-list expression
     */
    public function wrap(string $value): string
    {
        $parsed = self::parseColumn($value);
        if ($parsed === null) {
            throw new DatabaseException('Invalid column expression: ' . self::preview($value) . ' (use selectRaw/whereRaw for expressions)');
        }

        $sql = $this->wrapParts($parsed['parts']);

        if ($parsed['aggregate'] !== null) {
            [$function, $distinct] = $parsed['aggregate'];
            $sql = $function . '(' . ($distinct ? 'DISTINCT ' : '') . $sql . ')';
        }

        if ($parsed['alias'] !== null) {
            $sql .= ' AS ' . $this->quote($parsed['alias']);
        }

        return $sql;
    }

    public function wrapTable(string $value): string
    {
        [$name, $alias] = self::parseTable($value);
        $sql = $this->wrapParts(explode('.', $name));
        return $alias !== null ? $sql . ' ' . $this->quote($alias) : $sql;
    }

    /**
     * Quote an already validated identifier list (index names, savepoints...)
     */
    public function wrapList(array $identifiers): string
    {
        return implode(', ', array_map(fn($c) => $this->wrap($c), $identifiers));
    }

    // ========================================
    // Driver specific fragments
    // ========================================

    public function compileLimit(?int $limit, ?int $offset, bool $hasOrderBy): string
    {
        $offset = ($offset !== null && $offset > 0) ? $offset : null;
        if ($limit !== null && $limit < 0) {
            $limit = 0;
        }

        if ($this->driver === 'sqlsrv') {
            if ($limit === null && $offset === null) {
                return '';
            }
            $sql = $hasOrderBy ? '' : ' ORDER BY (SELECT NULL)';
            $sql .= ' OFFSET ' . ($offset ?? 0) . ' ROWS';
            if ($limit !== null) {
                $sql .= ' FETCH NEXT ' . $limit . ' ROWS ONLY';
            }
            return $sql;
        }

        $sql = '';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        } elseif ($offset !== null) {
            $sql .= match ($this->driver) {
                'mysql' => ' LIMIT 18446744073709551615',
                'sqlite' => ' LIMIT -1',
                default => '',
            };
        }

        if ($offset !== null) {
            $sql .= ' OFFSET ' . $offset;
        }

        return $sql;
    }

    /**
     * The statement ends with its own ORDER BY (an ORDER BY inside OVER (...) or a subquery does not count)
     */
    public static function hasOrderBy(string $sql): bool
    {
        if (!preg_match_all('/\border\s+by\b/i', $sql, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $tail = substr($sql, end($matches[0])[1]);
        return substr_count($tail, '(') === substr_count($tail, ')');
    }

    /**
     * Raw SELECT used as a derived table: SELECT COUNT(*) FROM (<sql>) AS t.
     * SQL Server rejects ORDER BY there unless OFFSET / TOP is given, so OFFSET 0 ROWS is added.
     */
    public function derivedTable(string $sql): string
    {
        if ($this->driver === 'sqlsrv' && self::hasOrderBy($sql)
            && !preg_match('/\boffset\s+\S+\s+rows?\b|^\s*select\s+(distinct\s+)?top\b/i', $sql)) {
            return $sql . ' OFFSET 0 ROWS';
        }
        return $sql;
    }

    public function random(): string
    {
        return match ($this->driver) {
            'mysql' => 'RAND()',
            'sqlsrv' => 'NEWID()',
            default => 'RANDOM()',
        };
    }

    /**
     * Date part expression (date, time, year, month, day) for an already wrapped column
     */
    public function datePart(string $part, string $wrapped): string
    {
        $part = strtolower($part);
        if (!in_array($part, ['date', 'time', 'year', 'month', 'day'], true)) {
            throw new DatabaseException("Invalid date part: {$part}");
        }

        return match ($this->driver) {
            'pgsql' => match ($part) {
                'date' => "CAST({$wrapped} AS DATE)",
                'time' => "CAST({$wrapped} AS TIME)",
                default => 'CAST(EXTRACT(' . strtoupper($part) . " FROM {$wrapped}) AS INTEGER)",
            },
            'sqlite' => match ($part) {
                'date' => "date({$wrapped})",
                'time' => "time({$wrapped})",
                'year' => "CAST(strftime('%Y', {$wrapped}) AS INTEGER)",
                'month' => "CAST(strftime('%m', {$wrapped}) AS INTEGER)",
                default => "CAST(strftime('%d', {$wrapped}) AS INTEGER)",
            },
            'sqlsrv' => match ($part) {
                'date' => "CAST({$wrapped} AS DATE)",
                'time' => "CAST({$wrapped} AS TIME)",
                default => strtoupper($part) . "({$wrapped})",
            },
            default => strtoupper($part) . "({$wrapped})",
        };
    }

    public function savepoint(string $name): string
    {
        self::assertIdentifier($name, 'savepoint name');
        return $this->driver === 'sqlsrv' ? "SAVE TRANSACTION {$name}" : "SAVEPOINT {$name}";
    }

    public function releaseSavepoint(string $name): ?string
    {
        self::assertIdentifier($name, 'savepoint name');
        return $this->driver === 'sqlsrv' ? null : "RELEASE SAVEPOINT {$name}";
    }

    public function rollbackToSavepoint(string $name): string
    {
        self::assertIdentifier($name, 'savepoint name');
        return $this->driver === 'sqlsrv' ? "ROLLBACK TRANSACTION {$name}" : "ROLLBACK TO SAVEPOINT {$name}";
    }

    /**
     * Maximum number of bound parameters in one statement
     */
    public function maxParameters(?string $serverVersion = null): int
    {
        return match ($this->driver) {
            'sqlsrv' => 2000,
            'sqlite' => ($serverVersion !== null && version_compare($serverVersion, '3.32.0', '<')) ? 999 : 32766,
            default => 65535,
        };
    }

    /**
     * Escape LIKE wildcards in a user value (use together with likeEscapeClause())
     */
    public function likeEscape(string $value): string
    {
        $value = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
        if ($this->driver === 'sqlsrv') {
            $value = str_replace('[', '![', $value);
        }
        return $value;
    }

    public function likeEscapeClause(): string
    {
        return " ESCAPE '!'";
    }

    public function insertDefaultValues(string $wrappedTable): string
    {
        return $this->driver === 'mysql'
            ? "INSERT INTO {$wrappedTable} () VALUES ()"
            : "INSERT INTO {$wrappedTable} DEFAULT VALUES";
    }

    public function booleanLiteral(bool $value): string
    {
        if ($this->driver === 'pgsql') {
            return $value ? 'TRUE' : 'FALSE';
        }
        return $value ? '1' : '0';
    }

    // ========================================
    // Parsing helpers
    // ========================================

    /**
     * @return array{parts: string[], alias: ?string, aggregate: ?array}|null
     */
    private static function parseColumn(string $value): ?array
    {
        $v = trim($value);

        if ($v === '*') {
            return ['parts' => ['*'], 'alias' => null, 'aggregate' => null];
        }

        if (preg_match(self::AGGREGATE, $v, $m)) {
            return [
                'parts' => $m[3] === '*' ? ['*'] : explode('.', $m[3]),
                'alias' => isset($m[4]) && $m[4] !== '' ? $m[4] : null,
                'aggregate' => [strtoupper($m[1]), isset($m[2]) && $m[2] !== ''],
            ];
        }

        $alias = null;
        if (preg_match('/^(\S+)\s+as\s+(' . self::IDENT . ')$/i', $v, $m)) {
            $v = $m[1];
            $alias = $m[2];
        }

        $parts = explode('.', $v);
        if (count($parts) > 3) {
            return null;
        }

        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($part === '*' && $i === $last && $i > 0 && $alias === null) {
                continue;
            }
            if (!self::isIdentifier($part)) {
                return null;
            }
        }

        return ['parts' => $parts, 'alias' => $alias, 'aggregate' => null];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function parseTable(string $value): array
    {
        $ident = self::IDENT;
        if (!preg_match("/^({$ident}(?:\\.{$ident})?)(?:\\s+(?:as\\s+)?({$ident}))?$/i", trim($value), $m)) {
            throw new DatabaseException('Invalid table name: ' . self::preview($value));
        }

        return [$m[1], isset($m[2]) && $m[2] !== '' ? $m[2] : null];
    }

    private function wrapParts(array $parts): string
    {
        return implode('.', array_map(fn($p) => $p === '*' ? '*' : $this->quote($p), $parts));
    }

    private static function preview(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value);
        return strlen($value) > 60 ? substr($value, 0, 57) . '...' : $value;
    }
}
