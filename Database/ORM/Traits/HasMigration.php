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

use Miko\Database\Migration\TableBuilder;
use Miko\Database\Migration\Schema;
use Miko\Database\Connection;
use Miko\Core\Config;
use PDO;

/**
 * HasMigration Trait - Adds migration capabilities to Model
 * 
 * Allows defining table schema directly in Model class (Code-First approach)
 * Similar to Entity Framework migrations
 */
trait HasMigration
{
    /**
     * Schema instance
     */
    protected static ?Schema $migrationSchema = null;

    /**
     * Get schema instance
     */
    protected static function schema(): Schema
    {
        if (static::$migrationSchema === null) {
            $config = [
                'host' => Config::env('DB_HOST_LOCAL', 'localhost'),
                'port' => (int) Config::env('DB_PORT', 3306),
                'database' => Config::env('DB_DATABASE_LOCAL', 'database'),
                'username' => Config::env('DB_USERNAME_LOCAL', 'root'),
                'password' => Config::env('DB_PASSWORD_LOCAL', ''),
                'charset' => 'utf8mb4'
            ];
            
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );
            
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            $connection = new Connection($pdo, $config);
            static::$migrationSchema = new Schema($connection);
        }
        return static::$migrationSchema;
    }

    /**
     * Define table schema (override in child class)
     * 
     * Example:
     * protected static function defineSchema(TableBuilder $table): void
     * {
     *     $table->id('Id');
     *     $table->string('Name', 100);
     *     $table->timestamps();
     * }
     */
    protected static function defineSchema(TableBuilder $table): void
    {
        // Override in child class
    }

    /**
     * Run migration UP - Create table
     */
    public static function up(): void
    {
        static::schema()->create(static::$table, function (TableBuilder $table) {
            static::defineSchema($table);
        });
    }

    /**
     * Run migration DOWN - Drop table
     */
    public static function down(): void
    {
        static::schema()->dropIfExists(static::$table);
    }

    /**
     * Check if table exists
     */
    public static function tableExists(): bool
    {
        return static::schema()->hasTable(static::$table);
    }

    /**
     * Migrate - Create table if not exists
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
     * Fresh - Drop and recreate table
     */
    public static function fresh(): void
    {
        static::down();
        static::up();
    }

    /**
     * Refresh - Alias for fresh
     */
    public static function refresh(): void
    {
        static::fresh();
    }
}
