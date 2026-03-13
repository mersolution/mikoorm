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

use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;

/**
 * Raw SQL query executor - replaces SQLRaw
 * 
 * Features:
 * - Named parameters
 * - Prepared statements
 * - Fluent binding API
 * - Safe execution
 */
class RawQuery
{
    protected ConnectionInterface $connection;
    protected string $sql = '';
    protected array $bindings = [];

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Set SQL query
     */
    public function query(string $sql): self
    {
        $this->sql = trim($sql);
        return $this;
    }

    /**
     * Bind a single parameter
     */
    public function bind(string $key, mixed $value): self
    {
        // Ensure key starts with :
        if ($key[0] !== ':') {
            $key = ':' . $key;
        }

        $this->bindings[$key] = $value;
        return $this;
    }

    /**
     * Bind multiple parameters
     */
    public function bindAll(array $params): self
    {
        foreach ($params as $key => $value) {
            $this->bind($key, $value);
        }

        return $this;
    }

    /**
     * Execute and get results
     */
    public function get(): array
    {
        if (empty($this->sql)) {
            throw new DatabaseException('SQL query cannot be empty');
        }

        $this->validateBindings();

        $result = $this->connection->execute($this->sql, $this->bindings);

        if ($this->isSelectQuery()) {
            return $result->all();
        }

        return ['affected_rows' => $result->count()];
    }

    /**
     * Execute and get first result
     */
    public function first(): ?array
    {
        $results = $this->get();
        
        if ($this->isSelectQuery()) {
            return $results[0] ?? null;
        }

        return $results;
    }

    /**
     * Execute and get single value
     */
    public function value(string $column = null): mixed
    {
        $result = $this->first();

        if ($result === null) {
            return null;
        }

        if ($column !== null) {
            return $result[$column] ?? null;
        }

        // Return first column
        return reset($result);
    }

    /**
     * Execute without returning results
     */
    public function execute(): int
    {
        if (empty($this->sql)) {
            throw new DatabaseException('SQL query cannot be empty');
        }

        $this->validateBindings();

        $result = $this->connection->execute($this->sql, $this->bindings);
        return $result->count();
    }

    /**
     * Get SQL query
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Get bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Check if query is SELECT
     */
    protected function isSelectQuery(): bool
    {
        $sql = ltrim($this->sql);
        return stripos($sql, 'SELECT') === 0 || stripos($sql, 'SHOW') === 0;
    }

    /**
     * Validate that all placeholders have bindings
     */
    protected function validateBindings(): void
    {
        $placeholders = $this->extractPlaceholders();
        $boundKeys = array_keys($this->bindings);

        $missing = array_diff($placeholders, $boundKeys);

        if (!empty($missing)) {
            throw new DatabaseException(
                'Missing bindings for placeholders: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Extract placeholders from SQL
     */
    protected function extractPlaceholders(): array
    {
        if (!preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $this->sql, $matches)) {
            return [];
        }

        return array_unique(array_map(fn($name) => ':' . $name, $matches[1]));
    }

    /**
     * Create a new instance
     */
    public static function make(ConnectionInterface $connection, string $sql = ''): self
    {
        $instance = new self($connection);
        
        if ($sql) {
            $instance->query($sql);
        }

        return $instance;
    }
}
