<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Seeders;

use Miko\Database\ConnectionInterface;

/**
 * Base Seeder class
 * 
 * Extend this class to create database seeders for populating
 * your database with test or initial data.
 */
abstract class Seeder
{
    protected ConnectionInterface $connection;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Run the database seeds
     */
    abstract public function run(): void;

    /**
     * Call another seeder
     */
    protected function call(string $seederClass): void
    {
        $seeder = new $seederClass($this->connection);
        
        echo "Seeding: {$seederClass}\n";
        $startTime = microtime(true);
        
        $seeder->run();
        
        $time = round((microtime(true) - $startTime) * 1000, 2);
        echo "Seeded:  {$seederClass} ({$time}ms)\n";
    }

    /**
     * Call multiple seeders
     */
    protected function callMany(array $seederClasses): void
    {
        foreach ($seederClasses as $seederClass) {
            $this->call($seederClass);
        }
    }

    /**
     * Truncate a table before seeding
     */
    protected function truncate(string $table): void
    {
        $driver = $this->connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            $this->connection->execute("DELETE FROM {$table}");
            $this->connection->execute("DELETE FROM sqlite_sequence WHERE name = ?", [$table]);
        } elseif ($driver === 'mysql') {
            $this->connection->execute("SET FOREIGN_KEY_CHECKS = 0");
            $this->connection->execute("TRUNCATE TABLE {$table}");
            $this->connection->execute("SET FOREIGN_KEY_CHECKS = 1");
        } elseif ($driver === 'pgsql') {
            $this->connection->execute("TRUNCATE TABLE {$table} RESTART IDENTITY CASCADE");
        } elseif ($driver === 'sqlsrv') {
            $this->connection->execute("TRUNCATE TABLE {$table}");
        }
    }

    /**
     * Insert data into a table
     */
    protected function insert(string $table, array $data): void
    {
        if (empty($data)) {
            return;
        }

        // Single row
        if (!isset($data[0])) {
            $data = [$data];
        }

        $columns = array_keys($data[0]);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($data), $placeholders));
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES {$allPlaceholders}";
        
        $bindings = [];
        foreach ($data as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column] ?? null;
            }
        }

        $this->connection->execute($sql, $bindings);
    }

    /**
     * Get the connection
     */
    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
