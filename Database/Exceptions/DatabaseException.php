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

use Miko\Core\Exceptions\FrameworkException;

/**
 * Database exception for database-related errors
 */
class DatabaseException extends FrameworkException
{
    /**
     * SQL query that caused the exception
     *
     * @var string|null
     */
    protected ?string $sql = null;

    /**
     * Query bindings
     *
     * @var array
     */
    protected array $bindings = [];

    /**
     * Set the SQL query
     *
     * @param string $sql
     * @return self
     */
    public function setSql(string $sql): self
    {
        $this->sql = $sql;
        return $this;
    }

    /**
     * Get the SQL query
     *
     * @return string|null
     */
    public function getSql(): ?string
    {
        return $this->sql;
    }

    /**
     * Set the query bindings
     *
     * @param array $bindings
     * @return self
     */
    public function setBindings(array $bindings): self
    {
        $this->bindings = $bindings;
        return $this;
    }

    /**
     * Get the query bindings
     *
     * @return array
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * @inheritDoc
     */
    public function toArray(bool $includeTrace = false): array
    {
        $data = parent::toArray($includeTrace);
        
        if ($this->sql !== null) {
            $data['sql'] = $this->sql;
        }
        
        if (!empty($this->bindings)) {
            $data['bindings'] = $this->bindings;
        }

        return $data;
    }
}
