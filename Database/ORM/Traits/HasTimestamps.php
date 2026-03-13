<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\ORM\Traits;

/**
 * HasTimestamps trait - Automatically manages created_at and updated_at timestamps
 */
trait HasTimestamps
{
    /**
     * Indicates if the model should be timestamped
     */
    public bool $timestamps = true;

    /**
     * Get the name of the "created at" column
     */
    public function getCreatedAtColumn(): string
    {
        return 'CreatedDate';
    }

    /**
     * Get the name of the "updated at" column
     */
    public function getUpdatedAtColumn(): string
    {
        return 'UpdatedDate';
    }

    /**
     * Update the creation and update timestamps
     */
    protected function updateTimestamps(): void
    {
        if (!$this->timestamps) {
            return;
        }

        $time = $this->freshTimestamp();

        if (!$this->exists) {
            $this->setCreatedAt($time);
        }

        $this->setUpdatedAt($time);
    }

    /**
     * Set the value of the "created at" attribute
     */
    protected function setCreatedAt(string $value): void
    {
        $this->attributes[$this->getCreatedAtColumn()] = $value;
    }

    /**
     * Set the value of the "updated at" attribute
     */
    protected function setUpdatedAt(string $value): void
    {
        $this->attributes[$this->getUpdatedAtColumn()] = $value;
    }

    /**
     * Get a fresh timestamp for the model
     */
    protected function freshTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Get the created at timestamp
     */
    public function getCreatedAt(): ?string
    {
        return $this->attributes[$this->getCreatedAtColumn()] ?? null;
    }

    /**
     * Get the updated at timestamp
     */
    public function getUpdatedAt(): ?string
    {
        return $this->attributes[$this->getUpdatedAtColumn()] ?? null;
    }
}
