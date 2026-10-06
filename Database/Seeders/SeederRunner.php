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

use Miko\Core\Helpers\ClassFinder;
use Miko\Database\ConnectionInterface;

/**
 * Seeder Runner - Runs database seeders (progress printed on the CLI only)
 */
class SeederRunner
{
    private ConnectionInterface $connection;
    private string $seedersPath;

    public function __construct(ConnectionInterface $connection, string $seedersPath)
    {
        $this->connection = $connection;
        $this->seedersPath = rtrim($seedersPath, '/\\');
    }

    /**
     * Run a specific seeder
     */
    public function run(string $seederClass): void
    {
        if (!class_exists($seederClass)) {
            $this->loadSeeder($seederClass);
        }

        if (!class_exists($seederClass) || !is_subclass_of($seederClass, Seeder::class)) {
            throw new \RuntimeException("Seeder class not found: {$seederClass}");
        }

        $seeder = new $seederClass($this->connection);

        self::say("Running Seeder: {$seederClass}");
        $startTime = microtime(true);
        $seeder->run();
        $time = round((microtime(true) - $startTime) * 1000, 2);
        self::say("Seeding completed in {$time}ms");
    }

    /**
     * Run all seeders in the seeders path
     *
     * @return int Number of seeders run
     */
    public function runAll(): int
    {
        $files = glob($this->seedersPath . '/*.php') ?: [];
        sort($files);

        if ($files === []) {
            self::say("No seeders found in: {$this->seedersPath}");
            return 0;
        }

        $startTime = microtime(true);
        $count = 0;

        foreach ($files as $file) {
            $className = ClassFinder::fromFile($file);
            if ($className === null) {
                continue;
            }

            require_once $file;

            if (class_exists($className) && is_subclass_of($className, Seeder::class)) {
                $seederStart = microtime(true);
                (new $className($this->connection))->run();
                self::say("Seeded: {$className} (" . round((microtime(true) - $seederStart) * 1000, 2) . 'ms)');
                $count++;
            }
        }

        self::say("{$count} seeders completed in " . round((microtime(true) - $startTime) * 1000, 2) . 'ms');
        return $count;
    }

    private function loadSeeder(string $seederClass): void
    {
        $className = basename(str_replace('\\', '/', $seederClass));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $className)) {
            return;
        }

        $file = $this->seedersPath . '/' . $className . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }

    private static function say(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            echo $message, "\n";
        }
    }
}
