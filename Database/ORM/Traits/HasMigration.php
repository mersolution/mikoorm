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

use Miko\Database\Migration\Schema;
use Miko\Database\Migration\TableBuilder;
use Miko\Database\ORM\ModelMetadata;

/**
 * HasMigration Trait - Code-First table definition inside the model
 *
 * protected static function defineSchema(TableBuilder $table): void
 * {
 *     $table->id('Id');
 *     $table->string('Name', 100);
 *     $table->timestamps();
 * }
 *
 * User::migrate();        // create table if missing
 * User::recreateTable();  // drop + create
 *
 * Uses the model's own connection and the matching SQL dialect.
 */
trait HasMigration
{
    protected static function schema(): Schema
    {
        return new Schema(static::resolveConnection());
    }

    /**
     * Define table schema (override in your model)
     */
    protected static function defineSchema(TableBuilder $table): void
    {
    }

    /**
     * True when the model overrides defineSchema()
     */
    public static function definesSchema(): bool
    {
        return (new \ReflectionMethod(static::class, 'defineSchema'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Create the table on a schema (defineSchema() or #[Column] metadata)
     */
    public static function createTableUsing(Schema $schema): void
    {
        $schema->create(static::getTable(), function (TableBuilder $table) {
            if (static::definesSchema()) {
                static::defineSchema($table);
            } else {
                ModelMetadata::for(static::class)->applyTo($table);
            }
        });
    }

    public static function up(): void
    {
        static::createTableUsing(static::schema());
    }

    public static function down(): void
    {
        static::schema()->dropIfExists(static::getTable());
    }

    public static function tableExists(): bool
    {
        return static::schema()->hasTable(static::getTable());
    }

    /**
     * Create the table if it does not exist
     */
    public static function migrate(): bool
    {
        if (!static::tableExists()) {
            static::up();
            return true;
        }
        return false;
    }

    /**
     * Drop and recreate the table (was fresh()/refresh() in 1.x)
     */
    public static function recreateTable(): void
    {
        static::down();
        static::up();
    }
}
