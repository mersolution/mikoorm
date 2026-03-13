<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 */

namespace Miko\Database\Drivers;

use PDO;

/**
 * Database Driver Interface
 */
interface DriverInterface
{
    /**
     * Get PDO DSN string
     */
    public function getDsn(array $config): string;

    /**
     * Get PDO options
     */
    public function getOptions(): array;

    /**
     * Get driver name
     */
    public function getName(): string;

    /**
     * Get last insert ID query (if needed)
     */
    public function getLastInsertIdQuery(?string $sequence = null): ?string;

    /**
     * Quote identifier (table/column name)
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Get random function for ORDER BY
     */
    public function getRandomFunction(): string;

    /**
     * Get limit/offset SQL syntax
     */
    public function getLimitOffsetSql(int $limit, ?int $offset = null): string;
}
