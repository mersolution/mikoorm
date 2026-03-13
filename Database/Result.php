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
 * Query result wrapper
 */
class Result implements ResultInterface
{
    private StatementInterface $statement;
    private ?array $results = null;

    public function __construct(StatementInterface $statement)
    {
        $this->statement = $statement;
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        if ($this->results === null) {
            $this->results = $this->statement->fetchAll();
        }

        return $this->results;
    }

    /**
     * @inheritDoc
     */
    public function first(): ?array
    {
        $results = $this->all();
        return $results[0] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        return $this->statement->rowCount();
    }

    /**
     * @inheritDoc
     */
    public function isEmpty(): bool
    {
        return empty($this->all());
    }

    /**
     * @inheritDoc
     */
    public function pluck(string $column): array
    {
        return array_column($this->all(), $column);
    }
}
