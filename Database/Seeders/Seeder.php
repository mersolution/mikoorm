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
use Miko\Database\Query\Grammar;

/**
 * Base Seeder class
 *
 * Extend this class to create database seeders for populating
 * your database with test or initial data. Progress is printed on the CLI only.
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

        self::say("Seeding: {$seederClass}");
        $startTime = microtime(true);

        $seeder->run();

        $time = round((microtime(true) - $startTime) * 1000, 2);
        self::say("Seeded:  {$seederClass} ({$time}ms)");
    }

    protected function callMany(array $seederClasses): void
    {
        foreach ($seederClasses as $seederClass) {
            $this->call($seederClass);
        }
    }

    /**
     * Empty a table and reset its auto-increment counter
     */
    protected function truncate(string $table): void
    {
        $driver = $this->connection->getDriverName();
        $wrapped = $this->connection->getGrammar()->wrapTable($table);

        if ($driver === 'sqlite') {
            $this->connection->execute("DELETE FROM {$wrapped}");
            $hasSequence = $this->connection->execute("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")->first();
            if ($hasSequence !== null) {
                $this->connection->execute('DELETE FROM sqlite_sequence WHERE name = ?', [Grammar::assertTable($table)]);
            }
        } elseif ($driver === 'mysql') {
            $this->connection->execute('SET FOREIGN_KEY_CHECKS = 0');
            try {
                $this->connection->execute("TRUNCATE TABLE {$wrapped}");
            } finally {
                $this->connection->execute('SET FOREIGN_KEY_CHECKS = 1');
            }
        } elseif ($driver === 'pgsql') {
            $this->connection->execute("TRUNCATE TABLE {$wrapped} RESTART IDENTITY CASCADE");
        } else {
            $this->connection->execute("TRUNCATE TABLE {$wrapped}");
        }
    }

    /**
     * Insert one row (associative array) or many rows
     */
    protected function insert(string $table, array $data): void
    {
        if ($data === []) {
            return;
        }

        if (!array_is_list($data)) {
            $data = [$data];
        }

        $grammar = $this->connection->getGrammar();
        $columns = array_keys($data[0]);
        $wrapped = implode(', ', array_map(fn($c) => $grammar->wrap(Grammar::assertReference((string) $c)), $columns));
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $bindings = [];
        foreach ($data as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column] ?? null;
            }
        }

        $this->connection->execute(
            'INSERT INTO ' . $grammar->wrapTable($table) . " ({$wrapped}) VALUES " . implode(', ', array_fill(0, count($data), $placeholders)),
            $bindings
        );
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    protected static function say(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            echo $message, "\n";
        }
    }
}
