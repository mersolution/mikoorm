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
 * Seeder Runner - Runs database seeders
 */
class SeederRunner
{
    private ConnectionInterface $connection;
    private string $seedersPath;

    public function __construct(ConnectionInterface $connection, string $seedersPath)
    {
        $this->connection = $connection;
        $this->seedersPath = $seedersPath;
    }

    /**
     * Run a specific seeder
     */
    public function run(string $seederClass): void
    {
        if (!class_exists($seederClass)) {
            $this->loadSeeder($seederClass);
        }

        if (!class_exists($seederClass)) {
            throw new \RuntimeException("Seeder class not found: {$seederClass}");
        }

        $seeder = new $seederClass($this->connection);
        
        echo "\n🌱 Running Seeder: {$seederClass}\n";
        echo str_repeat('-', 50) . "\n";
        
        $startTime = microtime(true);
        $seeder->run();
        $time = round((microtime(true) - $startTime) * 1000, 2);
        
        echo str_repeat('-', 50) . "\n";
        echo "✅ Seeding completed in {$time}ms\n\n";
    }

    /**
     * Run all seeders in the seeders path
     */
    public function runAll(): void
    {
        $files = glob($this->seedersPath . '/*.php');
        
        if (empty($files)) {
            echo "No seeders found in: {$this->seedersPath}\n";
            return;
        }

        echo "\n🌱 Running All Seeders\n";
        echo str_repeat('=', 50) . "\n";
        
        $startTime = microtime(true);
        $count = 0;

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file);
            if ($className) {
                require_once $file;
                
                if (class_exists($className) && is_subclass_of($className, Seeder::class)) {
                    $seeder = new $className($this->connection);
                    
                    echo "Seeding: {$className}... ";
                    $seederStart = microtime(true);
                    $seeder->run();
                    $seederTime = round((microtime(true) - $seederStart) * 1000, 2);
                    echo "Done ({$seederTime}ms)\n";
                    
                    $count++;
                }
            }
        }

        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        echo str_repeat('=', 50) . "\n";
        echo "✅ {$count} seeders completed in {$totalTime}ms\n\n";
    }

    /**
     * Load a seeder file
     */
    private function loadSeeder(string $seederClass): void
    {
        $className = basename(str_replace('\\', '/', $seederClass));
        $file = $this->seedersPath . '/' . $className . '.php';
        
        if (file_exists($file)) {
            require_once $file;
        }
    }

    /**
     * Get class name from file
     */
    private function getClassNameFromFile(string $file): ?string
    {
        $content = file_get_contents($file);
        
        // Get namespace
        $namespace = '';
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = $matches[1] . '\\';
        }
        
        // Get class name
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            return $namespace . $matches[1];
        }
        
        return null;
    }
}
