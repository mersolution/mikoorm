<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database;

use Miko\Database\Async\AsyncDriver;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\Exceptions\QueryException;
use Miko\Database\Log\QueryLogger;
use Miko\Database\Monitor\ConnectionStats;
use Miko\Database\Query\Grammar;
use Miko\Log\Logger;
use PDO;
use PDOException;

/**
 * Database connection implementation
 */
class Connection implements ConnectionInterface
{
    private ?PDO $pdo;
    private array $config;
    private ?string $driver = null;

    /** Parallel query driver: null = not created yet, false = not supported */
    private AsyncDriver|false|null $asyncDriver = null;

    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            throw new DatabaseException('Connection is closed. Call reconnect() first.');
        }

        return $this->pdo;
    }

    /**
     * @inheritDoc
     */
    public function getDriverName(): string
    {
        return $this->driver ??= strtolower((string) $this->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /**
     * @inheritDoc
     */
    public function getGrammar(): Grammar
    {
        return Grammar::for($this->getDriverName());
    }

    /**
     * Connection config without secrets
     */
    public function getConfig(): array
    {
        $config = $this->config;
        unset($config['password']);
        return $config;
    }

    /**
     * @internal driver that runs the *Async() queries of this connection in parallel
     * (null: SQLite, or no client fits the config)
     */
    public function asyncDriver(): ?AsyncDriver
    {
        if ($this->asyncDriver === null) {
            $this->asyncDriver = AsyncDriver::for($this, $this->config) ?? false;
        }
        return $this->asyncDriver ?: null;
    }

    /**
     * @inheritDoc
     */
    public function prepare(string $sql): StatementInterface
    {
        try {
            return new Statement($this->getPdo()->prepare($sql));
        } catch (PDOException $e) {
            Logger::logQuery($sql, [], $e->getMessage());
            throw QueryException::forQuery($sql, [], $e);
        }
    }

    /**
     * PDO native prepares reject duplicate named placeholders (HY093).
     * Expand each repeated :name to :name__dupN with the same bound value.
     *
     * @return array{0: string, 1: array}
     */
    private function expandNamedParameters(string $sql, array $params): array
    {
        $named = [];
        foreach ($params as $key => $value) {
            if (is_string($key) && $key !== '') {
                $named[$key[0] === ':' ? $key : ':' . $key] = $value;
            }
        }

        if ($named === []) {
            return [$sql, $params];
        }

        $expanded = [];
        $occurrences = [];

        // string literals and quoted names are matched first and kept as they are (':n' inside quotes is text)
        $newSql = preg_replace_callback(
            "/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.)*\"|`[^`]*`|(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)\\b/s",
            static function (array $m) use ($named, &$expanded, &$occurrences): string {
                if (!isset($m[1]) || $m[1] === '') {
                    return $m[0];
                }

                $name = ':' . $m[1];
                if (!array_key_exists($name, $named)) {
                    return $m[0];
                }

                $count = $occurrences[$name] ?? 0;
                $occurrences[$name] = $count + 1;

                if ($count === 0) {
                    $expanded[$name] = $named[$name];
                    return $name;
                }

                $unique = $name . '__dup' . $count;
                $expanded[$unique] = $named[$name];
                return $unique;
            },
            $sql
        );

        foreach ($named as $key => $value) {
            if (!array_key_exists($key, $expanded) && isset($occurrences[$key])) {
                $expanded[$key] = $value;
            }
        }

        return [$newSql, $expanded];
    }

    private function bindAll(\PDOStatement|StatementInterface $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : $key;
            if ($stmt instanceof StatementInterface) {
                $stmt->bindValue($param, $value);
            } else {
                $stmt->bindValue($param, $value, Statement::inferType($value));
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(string $sql, array $params = []): ResultInterface
    {
        [$sql, $params] = $this->expandNamedParameters($sql, $params);
        $start = hrtime(true);

        try {
            $stmt = $this->prepare($sql);
            $this->bindAll($stmt, $params);
            $stmt->execute();
        } catch (QueryException $e) {
            $e->setBindings($params);
            throw $e;
        } catch (PDOException $e) {
            Logger::logQuery($sql, $params, $e->getMessage());
            throw QueryException::forQuery($sql, $params, $e);
        }

        $ms = (hrtime(true) - $start) / 1e6;
        QueryLogger::log($sql, $params, $ms);
        ConnectionStats::recordQuery($ms / 1000);

        return new Result($stmt);
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        try {
            $this->getPdo()->beginTransaction();
        } catch (PDOException $e) {
            Logger::connection('Failed to begin transaction: ' . $e->getMessage());
            throw new DatabaseException('Failed to begin transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function commit(): void
    {
        try {
            $this->getPdo()->commit();
        } catch (PDOException $e) {
            Logger::connection('Failed to commit transaction: ' . $e->getMessage());
            throw new DatabaseException('Failed to commit transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function rollback(): void
    {
        try {
            $this->getPdo()->rollBack();
        } catch (PDOException $e) {
            Logger::connection('Failed to rollback transaction: ' . $e->getMessage());
            throw new DatabaseException('Failed to rollback transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function inTransaction(): bool
    {
        return $this->pdo !== null && $this->pdo->inTransaction();
    }

    /**
     * @inheritDoc
     */
    public function isConnected(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->pdo = ConnectionFactory::createPdo($this->config);
        $this->driver = null;
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): void
    {
        if ($this->asyncDriver) {
            $this->asyncDriver->close();
        }
        $this->asyncDriver = null;

        if ($this->pdo !== null) {
            ConnectionStats::recordRelease();
        }
        $this->pdo = null;
    }

    /**
     * @inheritDoc
     */
    public function lastInsertId(?string $sequence = null): string|int
    {
        $id = $this->getPdo()->lastInsertId($sequence);
        return $id === false ? 0 : $id;
    }

    /**
     * Execute a raw SQL statement without parameters
     *
     * @return int Number of affected rows
     */
    public function exec(string $sql): int
    {
        try {
            return (int) $this->getPdo()->exec($sql);
        } catch (PDOException $e) {
            Logger::logQuery($sql, [], $e->getMessage());
            throw QueryException::forQuery($sql, [], $e);
        }
    }

    /**
     * Quote a string for use in a query
     */
    public function quote(string $value): string
    {
        return $this->getPdo()->quote($value);
    }

    /**
     * Maximum number of bound parameters for one statement
     */
    public function maxParameters(): int
    {
        $version = null;
        if ($this->getDriverName() === 'sqlite') {
            $version = (string) $this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        }
        return $this->getGrammar()->maxParameters($version);
    }

    // ========================================
    // Scalar Methods
    // ========================================

    public function toStringScalar(string $sql, array $params = []): ?string
    {
        $result = $this->execute($sql, $params)->first();
        if ($result === null) {
            return null;
        }
        $value = reset($result);
        return $value === null ? null : (string) $value;
    }

    public function toIntScalar(string $sql, array $params = []): int
    {
        $result = $this->execute($sql, $params)->first();
        return $result === null ? 0 : (int) reset($result);
    }

    public function toFloatScalar(string $sql, array $params = []): float
    {
        $result = $this->execute($sql, $params)->first();
        return $result === null ? 0.0 : (float) reset($result);
    }

    public function toDecimalScalar(string $sql, array $params = []): string
    {
        $result = $this->execute($sql, $params)->first();
        return $result === null ? '0' : (string) reset($result);
    }

    public function toBoolScalar(string $sql, array $params = []): bool
    {
        $result = $this->execute($sql, $params)->first();
        return $result !== null && (bool) reset($result);
    }

    // ========================================
    // Streaming Methods (memory-efficient)
    // ========================================

    /**
     * Stream rows one by one; return false from the callback to stop
     */
    public function stream(string $sql, array $params, callable $callback): void
    {
        foreach ($this->cursor($sql, $params) as $row) {
            if ($callback($row) === false) {
                break;
            }
        }
    }

    /**
     * Yield rows one by one (lazy)
     */
    public function cursor(string $sql, array $params = []): \Generator
    {
        [$sql, $params] = $this->expandNamedParameters($sql, $params);

        try {
            $stmt = $this->getPdo()->prepare($sql);
            $this->bindAll($stmt, $params);
            $stmt->execute();
        } catch (PDOException $e) {
            Logger::logQuery($sql, $params, $e->getMessage());
            throw QueryException::forQuery($sql, $params, $e);
        }

        try {
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    // ========================================
    // Pagination
    // ========================================

    /**
     * Paginate a raw SELECT (without LIMIT/OFFSET)
     */
    public function paginate(string $sql, array $params, int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $grammar = $this->getGrammar();
        $total = $this->toIntScalar('SELECT COUNT(*) AS total FROM (' . $grammar->derivedTable($sql) . ') AS miko_count', $params);
        $offset = ($page - 1) * $perPage;
        $hasOrder = Grammar::hasOrderBy($sql);

        $data = $this->execute($sql . $grammar->compileLimit($perPage, $offset, $hasOrder), $params)->all();

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'from' => $total > 0 && $data !== [] ? $offset + 1 : 0,
            'to' => $data !== [] ? $offset + count($data) : 0,
        ];
    }

    // ========================================
    // Utility Methods
    // ========================================

    public function exists(string $table, string $column, mixed $value): bool
    {
        $g = $this->getGrammar();
        $sql = 'SELECT 1 FROM ' . $g->wrapTable($table) . ' WHERE ' . $g->wrap(Grammar::assertReference($column)) . ' = ?'
            . $g->compileLimit(1, null, false);
        return $this->execute($sql, [$value])->first() !== null;
    }

    public function findOrNull(string $table, string $column, mixed $value): ?array
    {
        $g = $this->getGrammar();
        $sql = 'SELECT * FROM ' . $g->wrapTable($table) . ' WHERE ' . $g->wrap(Grammar::assertReference($column)) . ' = ?'
            . $g->compileLimit(1, null, false);
        return $this->execute($sql, [$value])->first();
    }

    public function lastPrimaryKey(string $table, string $column = 'Id'): int
    {
        $g = $this->getGrammar();
        return $this->toIntScalar('SELECT COALESCE(MAX(' . $g->wrap(Grammar::assertReference($column)) . '), 0) FROM ' . $g->wrapTable($table));
    }

    /**
     * Row count; $where is raw SQL, pass values through $params
     */
    public function tableCount(string $table, ?string $where = null, array $params = []): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->getGrammar()->wrapTable($table);
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        return $this->toIntScalar($sql, $params);
    }

    public function getServerVersion(): string
    {
        return (string) $this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }
}
