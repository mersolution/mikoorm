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

use Miko\Database\Exceptions\DatabaseException;
use Miko\Entities\Log\Logger;
use PDO;
use PDOException;

/**
 * Database connection implementation
 */
class Connection implements ConnectionInterface
{
    private PDO $pdo;
    private array $config;
    private bool $connected = true;

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
        return $this->pdo;
    }

    /**
     * @inheritDoc
     */
    public function prepare(string $sql): StatementInterface
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            return new Statement($stmt);
        } catch (PDOException $e) {
            Logger::connection("Failed to prepare statement: " . $e->getMessage(), [
                'sql' => $sql,
                'error_code' => $e->getCode()
            ]);
            
            throw (new DatabaseException("Failed to prepare statement: " . $e->getMessage(), 0, $e))
                ->setSql($sql);
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(string $sql, array $params = []): ResultInterface
    {
        try {
            $stmt = $this->prepare($sql);
            
            foreach ($params as $key => $value) {
                if (is_int($key)) {
                    $stmt->bindValue($key + 1, $value);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $stmt->execute();
            
            return new Result($stmt);
        } catch (PDOException $e) {
            Logger::logQuery($sql, $params, $e->getMessage());
            
            throw (new DatabaseException("Failed to execute query: " . $e->getMessage(), 0, $e))
                ->setSql($sql)
                ->setBindings($params);
        }
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        try {
            $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            Logger::connection("Failed to begin transaction: " . $e->getMessage(), [
                'error_code' => $e->getCode()
            ]);
            
            throw new DatabaseException("Failed to begin transaction: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function commit(): void
    {
        try {
            $this->pdo->commit();
        } catch (PDOException $e) {
            Logger::connection("Failed to commit transaction: " . $e->getMessage(), [
                'error_code' => $e->getCode()
            ]);
            
            throw new DatabaseException("Failed to commit transaction: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function rollback(): void
    {
        try {
            $this->pdo->rollBack();
        } catch (PDOException $e) {
            Logger::connection("Failed to rollback transaction: " . $e->getMessage(), [
                'error_code' => $e->getCode()
            ]);
            
            throw new DatabaseException("Failed to rollback transaction: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * @inheritDoc
     */
    public function isConnected(): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            $this->connected = false;
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function reconnect(): void
    {
        $this->disconnect();
        
        // Recreate PDO connection
        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $this->config['driver'] ?? 'mysql',
            $this->config['host'] ?? '127.0.0.1',
            $this->config['port'] ?? 3306,
            $this->config['database'] ?? '',
            $this->config['charset'] ?? 'utf8mb4'
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->pdo = new PDO(
            $dsn,
            $this->config['username'] ?? '',
            $this->config['password'] ?? '',
            $options
        );

        $this->connected = true;
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): void
    {
        $this->connected = false;
        // PDO doesn't have explicit close, setting to null closes it
        unset($this->pdo);
    }

    /**
     * @inheritDoc
     */
    public function lastInsertId(): string|int
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Execute a raw SQL query
     *
     * @param string $sql
     * @return int Number of affected rows
     */
    public function exec(string $sql): int
    {
        try {
            return $this->pdo->exec($sql);
        } catch (PDOException $e) {
            throw (new DatabaseException("Failed to execute SQL: " . $e->getMessage(), 0, $e))
                ->setSql($sql);
        }
    }

    /**
     * Quote a string for use in a query
     *
     * @param string $value
     * @return string
     */
    public function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }

    // ========================================
    // Scalar Methods (like mersolutionCore)
    // ========================================

    /**
     * Execute SQL and return string scalar value
     */
    public function toStringScalar(string $sql, array $params = []): ?string
    {
        $result = $this->execute($sql, $params)->first();
        if ($result === null) {
            return null;
        }
        return (string) reset($result);
    }

    /**
     * Execute SQL and return integer scalar value
     */
    public function toIntScalar(string $sql, array $params = []): int
    {
        $result = $this->execute($sql, $params)->first();
        if ($result === null) {
            return 0;
        }
        return (int) reset($result);
    }

    /**
     * Execute SQL and return float scalar value
     */
    public function toFloatScalar(string $sql, array $params = []): float
    {
        $result = $this->execute($sql, $params)->first();
        if ($result === null) {
            return 0.0;
        }
        return (float) reset($result);
    }

    /**
     * Execute SQL and return decimal scalar value
     */
    public function toDecimalScalar(string $sql, array $params = []): string
    {
        $result = $this->execute($sql, $params)->first();
        if ($result === null) {
            return '0';
        }
        return (string) reset($result);
    }

    /**
     * Execute SQL and return boolean scalar value
     */
    public function toBoolScalar(string $sql, array $params = []): bool
    {
        $result = $this->execute($sql, $params)->first();
        if ($result === null) {
            return false;
        }
        return (bool) reset($result);
    }

    // ========================================
    // Streaming Methods (memory-efficient)
    // ========================================

    /**
     * Execute SQL and stream results row by row (memory-efficient for large datasets)
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param callable $callback Function to call for each row
     */
    public function stream(string $sql, array $params, callable $callback): void
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($params as $key => $value) {
                if (is_int($key)) {
                    $stmt->bindValue($key + 1, $value);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $stmt->execute();
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($callback($row) === false) {
                    break;
                }
            }
            
            $stmt->closeCursor();
        } catch (PDOException $e) {
            throw (new DatabaseException("Failed to stream query: " . $e->getMessage(), 0, $e))
                ->setSql($sql)
                ->setBindings($params);
        }
    }

    /**
     * Execute SQL and yield results as generator (lazy loading)
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return \Generator
     */
    public function cursor(string $sql, array $params = []): \Generator
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($params as $key => $value) {
                if (is_int($key)) {
                    $stmt->bindValue($key + 1, $value);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $stmt->execute();
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                yield $row;
            }
            
            $stmt->closeCursor();
        } catch (PDOException $e) {
            throw (new DatabaseException("Failed to cursor query: " . $e->getMessage(), 0, $e))
                ->setSql($sql)
                ->setBindings($params);
        }
    }

    // ========================================
    // Pagination Methods
    // ========================================

    /**
     * Execute SQL with pagination
     * 
     * @param string $sql Base SQL query (without LIMIT/OFFSET)
     * @param array $params Query parameters
     * @param int $page Page number (1-based)
     * @param int $perPage Records per page
     * @return array Paginated results with metadata
     */
    public function paginate(string $sql, array $params, int $page = 1, int $perPage = 15): array
    {
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM ({$sql}) as count_query";
        $total = $this->toIntScalar($countSql, $params);
        
        // Calculate offset
        $offset = ($page - 1) * $perPage;
        
        // Get paginated data
        $pagedSql = "{$sql} LIMIT {$perPage} OFFSET {$offset}";
        $data = $this->execute($pagedSql, $params)->all();
        
        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $total),
        ];
    }

    // ========================================
    // Utility Methods
    // ========================================

    /**
     * Check if record exists
     * 
     * @param string $table Table name
     * @param string $column Column name
     * @param mixed $value Value to search
     * @return bool
     */
    public function exists(string $table, string $column, mixed $value): bool
    {
        $sql = "SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1";
        return $this->toBoolScalar($sql, [$value]);
    }

    /**
     * Find record or return null
     * 
     * @param string $table Table name
     * @param string $column Column name
     * @param mixed $value Value to search
     * @return array|null
     */
    public function findOrNull(string $table, string $column, mixed $value): ?array
    {
        $sql = "SELECT * FROM {$table} WHERE {$column} = ? LIMIT 1";
        return $this->execute($sql, [$value])->first();
    }

    /**
     * Get last primary key value
     * 
     * @param string $table Table name
     * @param string $column Primary key column name
     * @return int
     */
    public function lastPrimaryKey(string $table, string $column = 'id'): int
    {
        $sql = "SELECT COALESCE(MAX({$column}), 0) FROM {$table}";
        return $this->toIntScalar($sql);
    }

    /**
     * Get table row count
     * 
     * @param string $table Table name
     * @param string|null $where Optional WHERE clause
     * @return int
     */
    public function tableCount(string $table, ?string $where = null): int
    {
        $sql = "SELECT COUNT(*) FROM {$table}";
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        return $this->toIntScalar($sql);
    }

    /**
     * Get driver name
     */
    public function getDriverName(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Get server version
     */
    public function getServerVersion(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    }
}
