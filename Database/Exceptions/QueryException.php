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
 * Query Exception - thrown by Connection for every failed statement.
 *
 * getCode() is the driver error number (e.g. MySQL 1062), getSqlState() the SQLSTATE.
 */
class QueryException extends DatabaseException
{
    protected ?string $sqlState = null;
    protected ?string $errorCode = null;

    /**
     * Create query exception with SQL details
     */
    public static function forQuery(string $sql, array $bindings, \Throwable $previous): self
    {
        $driverCode = 0;
        $sqlState = null;

        if ($previous instanceof \PDOException) {
            $sqlState = $previous->errorInfo[0] ?? (is_string($previous->getCode()) ? $previous->getCode() : null);
            $driverCode = (int) ($previous->errorInfo[1] ?? 0);
        }

        $exception = new self('Query error: ' . $previous->getMessage(), $driverCode, $previous);
        $exception->setSql($sql);
        $exception->setBindings($bindings);
        $exception->sqlState = $sqlState !== null ? (string) $sqlState : null;
        $exception->errorCode = $driverCode !== 0 ? (string) $driverCode : null;

        return $exception;
    }

    /**
     * Error reported by an async driver (mysqli, pgsql or the built-in PostgreSQL client)
     */
    public static function fromDriver(string $sql, array $bindings, string $message, int $code, ?string $sqlState, ?\Throwable $previous = null): self
    {
        $state = $sqlState !== null && $sqlState !== '' ? $sqlState : null;
        $text = ($state !== null ? "SQLSTATE[{$state}]: " : '') . ($code !== 0 ? "{$code} " : '') . trim($message);

        $exception = new self('Query error: ' . $text, $code, $previous);
        $exception->setSql($sql);
        $exception->setBindings($bindings);
        $exception->sqlState = $state;
        $exception->errorCode = $code !== 0 ? (string) $code : null;

        return $exception;
    }

    public static function duplicateEntry(string $key, string $value): self
    {
        return new self("Duplicate entry '{$value}' for key '{$key}'", 1062);
    }

    public static function foreignKeyConstraint(string $constraint): self
    {
        return new self("Foreign key constraint failed: {$constraint}", 1451);
    }

    public static function tableNotFound(string $table): self
    {
        return new self("Table '{$table}' doesn't exist", 1146);
    }

    public static function columnNotFound(string $column): self
    {
        return new self("Unknown column '{$column}'", 1054);
    }

    public static function syntaxError(string $message): self
    {
        return new self("SQL syntax error: {$message}", 1064);
    }

    /**
     * Driver error code as string (MySQL 1062, SQL Server 2627, ...)
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode ?? ($this->getCode() !== 0 ? (string) $this->getCode() : null);
    }

    /**
     * SQLSTATE (23000, 23505, 40001, ...)
     */
    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * Unique / primary key violation on any supported driver
     */
    public function isDuplicateEntry(): bool
    {
        $code = $this->getCode();
        return in_array($code, [1062, 1586, 2601, 2627], true)
            || $this->sqlState === '23505'
            || ($this->sqlState === '23000' && stripos($this->getMessage(), 'UNIQUE') !== false);
    }

    /**
     * Foreign key violation on any supported driver
     */
    public function isForeignKeyError(): bool
    {
        $code = $this->getCode();
        return in_array($code, [1451, 1452, 547], true)
            || $this->sqlState === '23503'
            || ($this->sqlState === '23000' && stripos($this->getMessage(), 'FOREIGN KEY') !== false);
    }

    public function isDeadlock(): bool
    {
        return in_array($this->getCode(), [1213, 1205], true) || in_array($this->sqlState, ['40001', '40P01'], true);
    }

    public function isConnectionLost(): bool
    {
        return in_array($this->getCode(), [2006, 2013], true)
            || in_array($this->sqlState, ['08S01', '08003', '08006', '57P01'], true);
    }
}
