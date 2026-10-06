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
 * HasTimestamps trait - fills CreatedDate on insert and UpdatedDate on every change.
 *
 * Opt-in: add "use HasTimestamps;" to the model.
 * Override the columns with: const CREATED_AT = 'Created'; const UPDATED_AT = 'Updated';
 */
trait HasTimestamps
{
    /**
     * Turn timestamps off for a single model instance: $model->timestamps = false
     */
    public bool $timestamps = true;

    public function getCreatedAtColumn(): string
    {
        return defined(static::class . '::CREATED_AT') ? constant(static::class . '::CREATED_AT') : 'CreatedDate';
    }

    public function getUpdatedAtColumn(): string
    {
        return defined(static::class . '::UPDATED_AT') ? constant(static::class . '::UPDATED_AT') : 'UpdatedDate';
    }

    protected function updateTimestamps(): void
    {
        if (!$this->timestamps) {
            return;
        }

        $time = $this->freshTimestampString();
        $created = $this->getCreatedAtColumn();
        $updated = $this->getUpdatedAtColumn();

        if (!$this->exists && ($this->attributes[$created] ?? null) === null) {
            $this->attributes[$created] = $time;
        }

        if (!$this->isDirty($updated)) {
            $this->attributes[$updated] = $time;
        }
    }

    protected function setCreatedAt(string $value): void
    {
        $this->attributes[$this->getCreatedAtColumn()] = $value;
    }

    protected function setUpdatedAt(string $value): void
    {
        $this->attributes[$this->getUpdatedAtColumn()] = $value;
    }

    protected function freshTimestamp(): string
    {
        return $this->freshTimestampString();
    }

    public function getCreatedAt(): ?string
    {
        return $this->attributes[$this->getCreatedAtColumn()] ?? null;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->attributes[$this->getUpdatedAtColumn()] ?? null;
    }
}
