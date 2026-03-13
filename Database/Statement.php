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

use PDOStatement;
use PDO;

/**
 * Prepared statement wrapper
 */
class Statement implements StatementInterface
{
    private PDOStatement $statement;

    public function __construct(PDOStatement $statement)
    {
        $this->statement = $statement;
    }

    /**
     * @inheritDoc
     */
    public function bindValue(string|int $parameter, mixed $value, ?int $type = null): bool
    {
        if ($type === null) {
            $type = $this->inferType($value);
        }

        return $this->statement->bindValue($parameter, $value, $type);
    }

    /**
     * @inheritDoc
     */
    public function bindParam(string|int $parameter, mixed &$variable, int $type = PDO::PARAM_STR): bool
    {
        return $this->statement->bindParam($parameter, $variable, $type);
    }

    /**
     * @inheritDoc
     */
    public function execute(?array $params = null): bool
    {
        return $this->statement->execute($params);
    }

    /**
     * @inheritDoc
     */
    public function fetch(int $mode = PDO::FETCH_ASSOC): mixed
    {
        return $this->statement->fetch($mode);
    }

    /**
     * @inheritDoc
     */
    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array
    {
        return $this->statement->fetchAll($mode);
    }

    /**
     * @inheritDoc
     */
    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }

    /**
     * Get the underlying PDOStatement
     *
     * @return PDOStatement
     */
    public function getPdoStatement(): PDOStatement
    {
        return $this->statement;
    }

    /**
     * Infer PDO parameter type from value
     *
     * @param mixed $value
     * @return int
     */
    private function inferType(mixed $value): int
    {
        return match(true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR
        };
    }
}
