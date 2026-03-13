<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Exceptions;

/**
 * Query Exception - Specific exception for query errors
 */
class QueryException extends DatabaseException
{
    protected ?string $sql = null;
    protected array $bindings = [];
    protected ?string $errorCode = null;

    /**
     * Create query exception with SQL details
     */
    public static function forQuery(string $sql, array $bindings, \Throwable $previous): self
    {
        $message = "Query error: " . $previous->getMessage();
        
        $exception = new self($message, (int)$previous->getCode(), $previous);
        $exception->sql = $sql;
        $exception->bindings = $bindings;
        
        if ($previous instanceof \PDOException) {
            $exception->errorCode = $previous->errorInfo[1] ?? null;
        }

        return $exception;
    }

    /**
     * Create for duplicate entry
     */
    public static function duplicateEntry(string $key, string $value): self
    {
        return new self("Duplicate entry '{$value}' for key '{$key}'", 1062);
    }

    /**
     * Create for foreign key constraint
     */
    public static function foreignKeyConstraint(string $constraint): self
    {
        return new self("Foreign key constraint failed: {$constraint}", 1451);
    }

    /**
     * Create for table not found
     */
    public static function tableNotFound(string $table): self
    {
        return new self("Table '{$table}' doesn't exist", 1146);
    }

    /**
     * Create for column not found
     */
    public static function columnNotFound(string $column): self
    {
        return new self("Unknown column '{$column}'", 1054);
    }

    /**
     * Create for syntax error
     */
    public static function syntaxError(string $message): self
    {
        return new self("SQL syntax error: {$message}", 1064);
    }

    /**
     * Get MySQL error code
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Check if duplicate entry error
     */
    public function isDuplicateEntry(): bool
    {
        return $this->getCode() === 1062 || $this->errorCode === '1062';
    }

    /**
     * Check if foreign key error
     */
    public function isForeignKeyError(): bool
    {
        return in_array($this->getCode(), [1451, 1452]);
    }

    /**
     * Check if deadlock
     */
    public function isDeadlock(): bool
    {
        return $this->getCode() === 1213;
    }

    /**
     * Check if connection lost
     */
    public function isConnectionLost(): bool
    {
        return in_array($this->getCode(), [2006, 2013]);
    }
}
