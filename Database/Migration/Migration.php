<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Migration;

/**
 * Migration - Base class for database migrations
 * Similar to mersolutionCore Migration class
 */
abstract class Migration
{
    /**
     * Migration version/timestamp (e.g., "2026_01_27_120000")
     */
    public abstract function version(): string;

    /**
     * Migration description
     */
    public function description(): string
    {
        return static::class;
    }

    /**
     * Run the migration (create tables, add columns, etc.)
     */
    public abstract function up(Schema $schema): void;

    /**
     * Reverse the migration (drop tables, remove columns, etc.)
     */
    public abstract function down(Schema $schema): void;
}
