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

use Miko\Database\ConnectionInterface;
use Miko\Database\Connection;
use Miko\Core\Config;
use PDO;

/**
 * Migrator - Migration runner with auto-discover support
 * Similar to mersolutionCore Migrator class
 */
class Migrator
{
    private ConnectionInterface $connection;
    private PDO $pdo;
    private string $migrationsTable;
    private array $migrations = [];

    public function __construct(ConnectionInterface $connection, string $migrationsTable = '__migrations')
    {
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->migrationsTable = $migrationsTable;
    }

    /**
     * Create Migrator with automatic database creation (like EF EnsureCreated)
     */
    public static function create(array $config, string $migrationsTable = '__migrations'): self
    {
        // Ensure database exists
        self::ensureDatabaseExists($config);
        
        // Create connection
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );
        
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $connection = new Connection($pdo, $config);
        
        return new self($connection, $migrationsTable);
    }

    /**
     * Ensure database exists, create if not
     */
    public static function ensureDatabaseExists(array $config): bool
    {
        $host = $config['host'];
        $port = $config['port'] ?? 3306;
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'];
        $charset = $config['charset'] ?? 'utf8mb4';
        
        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};charset={$charset}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$database}'");
            $exists = $stmt->fetch() !== false;
            
            if (!$exists) {
                $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                echo "✓ Database '{$database}' created\n";
                return true;
            }
            
            echo "✓ Database '{$database}' exists\n";
            return false;
            
        } catch (\PDOException $e) {
            throw new \Exception("Failed to ensure database exists: " . $e->getMessage());
        }
    }

    /**
     * Get database config from environment
     */
    public static function getConfigFromEnv(): array
    {
        if (class_exists('\Miko\Core\Config')) {
            return [
                'host' => Config::env('DB_HOST_LOCAL', 'localhost'),
                'port' => (int) Config::env('DB_PORT', 3306),
                'database' => Config::env('DB_DATABASE_LOCAL', 'database'),
                'username' => Config::env('DB_USERNAME_LOCAL', 'root'),
                'password' => Config::env('DB_PASSWORD_LOCAL', ''),
                'charset' => 'utf8mb4'
            ];
        }
        
        throw new \Exception('Miko Config not loaded. Call Config::load() first.');
    }

    /**
     * Register a migration
     */
    public function add(Migration $migration): self
    {
        $this->migrations[] = $migration;
        return $this;
    }

    /**
     * Register multiple migrations
     */
    public function addMany(array $migrations): self
    {
        foreach ($migrations as $migration) {
            $this->add($migration);
        }
        return $this;
    }

    /**
     * Auto-discover migrations from a directory
     */
    public function discover(string $path): self
    {
        if (!is_dir($path)) {
            return $this;
        }

        $files = glob($path . '/*.php');
        sort($files);

        foreach ($files as $file) {
            require_once $file;
            
            $className = $this->getClassNameFromFile($file);
            if ($className && class_exists($className)) {
                $reflection = new \ReflectionClass($className);
                if (!$reflection->isAbstract() && $reflection->isSubclassOf(Migration::class)) {
                    $this->migrations[] = new $className();
                }
            }
        }

        return $this;
    }

    /**
     * Run all pending migrations
     */
    public function migrate(): MigrationResult
    {
        $result = new MigrationResult();
        
        $this->ensureMigrationsTable();
        
        $applied = $this->getAppliedMigrations();
        $pending = array_filter($this->migrations, fn($m) => !in_array($m->version(), $applied));
        
        // Sort by version
        usort($pending, fn($a, $b) => strcmp($a->version(), $b->version()));
        
        foreach ($pending as $migration) {
            try {
                $schema = new Schema($this->connection);
                $migration->up($schema);
                
                $this->recordMigration($migration->version(), $migration->description());
                $result->applied[] = $migration->version();
                
            } catch (\Exception $e) {
                $result->errors[] = "{$migration->version()}: {$e->getMessage()}";
                $result->success = false;
                break;
            }
        }
        
        $result->success = empty($result->errors);
        return $result;
    }

    /**
     * Rollback last migration(s)
     */
    public function rollback(int $steps = 1): MigrationResult
    {
        $result = new MigrationResult();
        
        $this->ensureMigrationsTable();
        
        $applied = $this->getAppliedMigrations();
        $toRollback = array_slice(array_reverse($applied), 0, $steps);
        
        foreach ($toRollback as $version) {
            $migration = $this->findMigration($version);
            if (!$migration) {
                $result->errors[] = "Migration {$version} not found";
                continue;
            }
            
            try {
                $schema = new Schema($this->connection);
                $migration->down($schema);
                
                $this->removeMigration($version);
                $result->rolledBack[] = $version;
                
            } catch (\Exception $e) {
                $result->errors[] = "{$version}: {$e->getMessage()}";
                $result->success = false;
                break;
            }
        }
        
        $result->success = empty($result->errors);
        return $result;
    }

    /**
     * Reset all migrations
     */
    public function reset(): MigrationResult
    {
        $result = new MigrationResult();
        
        $this->ensureMigrationsTable();
        
        $applied = array_reverse($this->getAppliedMigrations());
        
        foreach ($applied as $version) {
            $migration = $this->findMigration($version);
            if (!$migration) continue;
            
            try {
                $schema = new Schema($this->connection);
                $migration->down($schema);
                
                $this->removeMigration($version);
                $result->rolledBack[] = $version;
                
            } catch (\Exception $e) {
                $result->errors[] = "{$version}: {$e->getMessage()}";
                $result->success = false;
                break;
            }
        }
        
        $result->success = empty($result->errors);
        return $result;
    }

    /**
     * Reset and re-run all migrations
     */
    public function refresh(): MigrationResult
    {
        $resetResult = $this->reset();
        if (!$resetResult->success) {
            return $resetResult;
        }
        
        return $this->migrate();
    }

    /**
     * Get migration status
     */
    public function status(): MigrationStatus
    {
        $status = new MigrationStatus();
        
        $this->ensureMigrationsTable();
        
        $applied = $this->getAppliedMigrations();
        
        // Sort migrations by version
        $sorted = $this->migrations;
        usort($sorted, fn($a, $b) => strcmp($a->version(), $b->version()));
        
        foreach ($sorted as $migration) {
            $status->migrations[] = new MigrationInfo(
                $migration->version(),
                $migration->description(),
                in_array($migration->version(), $applied)
            );
        }
        
        $status->pendingCount = count(array_filter($status->migrations, fn($m) => !$m->applied));
        $status->appliedCount = count(array_filter($status->migrations, fn($m) => $m->applied));
        
        return $status;
    }

    /**
     * Get Schema instance
     */
    public function getSchema(): Schema
    {
        return new Schema($this->connection);
    }

    private function ensureMigrationsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->migrationsTable}` (
            `Id` INT AUTO_INCREMENT PRIMARY KEY,
            `Version` VARCHAR(100) NOT NULL,
            `Description` VARCHAR(255),
            `AppliedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->pdo->exec($sql);
    }

    private function getAppliedMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT Version FROM `{$this->migrationsTable}` ORDER BY Version");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function recordMigration(string $version, string $description): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->migrationsTable}` (Version, Description) VALUES (?, ?)");
        $stmt->execute([$version, $description]);
    }

    private function removeMigration(string $version): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->migrationsTable}` WHERE Version = ?");
        $stmt->execute([$version]);
    }

    private function findMigration(string $version): ?Migration
    {
        foreach ($this->migrations as $migration) {
            if ($migration->version() === $version) {
                return $migration;
            }
        }
        return null;
    }

    private function getClassNameFromFile(string $file): ?string
    {
        $content = file_get_contents($file);
        
        // Extract namespace
        $namespace = '';
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = $matches[1] . '\\';
        }
        
        // Extract class name
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            return $namespace . $matches[1];
        }
        
        return null;
    }
}

/**
 * Migration execution result
 */
class MigrationResult
{
    public bool $success = true;
    public array $applied = [];
    public array $rolledBack = [];
    public array $errors = [];
}

/**
 * Migration status
 */
class MigrationStatus
{
    public array $migrations = [];
    public int $pendingCount = 0;
    public int $appliedCount = 0;
}

/**
 * Single migration info
 */
class MigrationInfo
{
    public function __construct(
        public string $version,
        public string $description,
        public bool $applied
    ) {}
}
