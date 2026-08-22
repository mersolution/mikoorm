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

/**
 * Prepared statement interface
 */
interface StatementInterface
{
    /**
     * Bind a value to a parameter
     *
     * @param string|int $parameter Parameter identifier
     * @param mixed $value The value to bind
     * @param int|null $type PDO parameter type
     * @return bool
     */
    public function bindValue(string|int $parameter, mixed $value, ?int $type = null): bool;

    /**
     * Bind a parameter to a variable
     *
     * @param string|int $parameter Parameter identifier
     * @param mixed &$variable The variable to bind
     * @param int $type PDO parameter type
     * @return bool
     */
    public function bindParam(string|int $parameter, mixed &$variable, int $type = \PDO::PARAM_STR): bool;

    /**
     * Execute the prepared statement
     *
     * @param array|null $params Optional parameters
     * @return bool
     */
    public function execute(?array $params = null): bool;

    /**
     * Fetch the next row
     *
     * @param int $mode Fetch mode
     * @return mixed
     */
    public function fetch(int $mode = \PDO::FETCH_ASSOC): mixed;

    /**
     * Fetch all rows
     *
     * @param int $mode Fetch mode
     * @return array
     */
    public function fetchAll(int $mode = \PDO::FETCH_ASSOC): array;

    /**
     * Get the number of affected rows
     *
     * @return int
     */
    public function rowCount(): int;
}
