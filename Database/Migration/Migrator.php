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

use Miko\Core\Database\DatabaseConfig;
use Miko\Core\Helpers\ClassFinder;
use Miko\Database\ConnectionFactory;
use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\ORM\Transaction;
use Miko\Database\Query\Grammar;

/**
 * Migrator - Migration runner with auto-discover support
 * Similar to mersolutionCore Migrator class
 */
class Migrator
{
    private ConnectionInterface $connection;
    private string $migrationsTable;
    private array $migrations = [];

    public function __construct(ConnectionInterface $connection, string $migrationsTable = '__migrations')
    {
        $this->connection = $connection;
        $this->migrationsTable = Grammar::assertIdentifier($migrationsTable, 'migrations table name');
    }

    /**
     * Create the database when missing (MySQL) and return a Migrator for it
     */
    public static function create(array $config, string $migrationsTable = '__migrations'): self
    {
        if (($config['driver'] ?? 'mysql') === 'mysql') {
            self::ensureDatabaseExists($config);
        }

        return new self(ConnectionFactory::make($config), $migrationsTable);
    }

    /**
     * Create the MySQL database when it does not exist.
     *
     * @return bool true when it was created
     */
    public static function ensureDatabaseExists(array $config): bool
    {
        $driver = $config['driver'] ?? 'mysql';
        if ($driver !== 'mysql') {
            return false;
        }

        $database = Grammar::assertIdentifier((string) ($config['database'] ?? ''), 'database name');
        $charset = preg_match('/^[A-Za-z0-9_]+$/', (string) ($config['charset'] ?? '')) ? $config['charset'] : 'utf8mb4';
        $collation = preg_match('/^[A-Za-z0-9_]+$/', (string) ($config['collation'] ?? '')) ? $config['collation'] : 'utf8mb4_unicode_ci';

        $server = $config;
        $server['database'] = '';
        $connection = ConnectionFactory::make($server);

        try {
            $exists = $connection->execute(
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$database]
            )->first() !== null;

            if ($exists) {
                return false;
            }

            $connection->exec("CREATE DATABASE `{$database}` CHARACTER SET {$charset} COLLATE {$collation}");
            return true;
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * Default connection config (Config/Database.php / .env)
     */
    public static function getConfigFromEnv(): array
    {
        return DatabaseConfig::connectionConfig();
    }

    public function add(Migration $migration): self
    {
        $this->migrations[] = $migration;
        return $this;
    }

    public function addMany(array $migrations): self
    {
        foreach ($migrations as $migration) {
            $this->add($migration);
        }
        return $this;
    }

    /**
     * Load every Migration class from *.php files of a directory
     */
    public function discover(string $path): self
    {
        if (!is_dir($path)) {
            return $this;
        }

        $files = glob(rtrim($path, '/\\') . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            require_once $file;

            $className = ClassFinder::fromFile($file);
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
     * Run all pending migrations (each in its own transaction where the driver allows DDL in transactions)
     */
    public function migrate(): MigrationResult
    {
        $result = new MigrationResult();
        $this->ensureMigrationsTable();

        $applied = $this->getAppliedMigrations();
        $pending = array_values(array_filter($this->migrations, fn($m) => !in_array($m->version(), $applied, true)));
        usort($pending, fn($a, $b) => strcmp($a->version(), $b->version()));

        foreach ($pending as $migration) {
            try {
                Transaction::run(function () use ($migration) {
                    $migration->up(new Schema($this->connection));
                    $this->recordMigration($migration->version(), $migration->description());
                }, $this->connection);

                $result->applied[] = $migration->version();
            } catch (\Throwable $e) {
                $result->errors[] = "{$migration->version()}: {$e->getMessage()}";
                break;
            }
        }

        $result->success = $result->errors === [];
        return $result;
    }

    /**
     * Roll back the last $steps migrations
     */
    public function rollback(int $steps = 1): MigrationResult
    {
        $this->ensureMigrationsTable();
        $applied = array_reverse($this->getAppliedMigrations());

        return $this->runDown(array_slice($applied, 0, max(1, $steps)), true);
    }

    /**
     * Roll back all migrations
     */
    public function reset(): MigrationResult
    {
        $this->ensureMigrationsTable();
        return $this->runDown(array_reverse($this->getAppliedMigrations()), false);
    }

    public function refresh(): MigrationResult
    {
        $reset = $this->reset();
        return $reset->success ? $this->migrate() : $reset;
    }

    public function status(): MigrationStatus
    {
        $status = new MigrationStatus();
        $this->ensureMigrationsTable();
        $applied = $this->getAppliedMigrations();

        $sorted = $this->migrations;
        usort($sorted, fn($a, $b) => strcmp($a->version(), $b->version()));

        foreach ($sorted as $migration) {
            $status->migrations[] = new MigrationInfo(
                $migration->version(),
                $migration->description(),
                in_array($migration->version(), $applied, true)
            );
        }

        $status->pendingCount = count(array_filter($status->migrations, fn($m) => !$m->applied));
        $status->appliedCount = count(array_filter($status->migrations, fn($m) => $m->applied));

        return $status;
    }

    public function getSchema(): Schema
    {
        return new Schema($this->connection);
    }

    private function runDown(array $versions, bool $reportMissing): MigrationResult
    {
        $result = new MigrationResult();

        foreach ($versions as $version) {
            $migration = $this->findMigration($version);
            if ($migration === null) {
                if ($reportMissing) {
                    $result->errors[] = "Migration {$version} not found";
                }
                continue;
            }

            try {
                Transaction::run(function () use ($migration, $version) {
                    $migration->down(new Schema($this->connection));
                    $this->removeMigration($version);
                }, $this->connection);

                $result->rolledBack[] = $version;
            } catch (\Throwable $e) {
                $result->errors[] = "{$version}: {$e->getMessage()}";
                break;
            }
        }

        $result->success = $result->errors === [];
        return $result;
    }

    private function ensureMigrationsTable(): void
    {
        $schema = new Schema($this->connection);

        if ($schema->hasTable($this->migrationsTable)) {
            return;
        }

        $schema->create($this->migrationsTable, function (TableBuilder $table) {
            $table->id('Id');
            $table->string('Version', 100)->notNull();
            $table->string('Description', 255)->nullable();
            $table->dateTime('AppliedAt')->nullable();
        });
    }

    private function getAppliedMigrations(): array
    {
        $grammar = $this->connection->getGrammar();
        $rows = $this->connection->execute(
            'SELECT ' . $grammar->quote('Version') . ' FROM ' . $grammar->quote($this->migrationsTable) . ' ORDER BY ' . $grammar->quote('Version')
        )->all();

        return array_map('strval', array_column($rows, 'Version'));
    }

    private function recordMigration(string $version, string $description): void
    {
        $grammar = $this->connection->getGrammar();
        $this->connection->execute(
            'INSERT INTO ' . $grammar->quote($this->migrationsTable) . ' ('
            . $grammar->quote('Version') . ', ' . $grammar->quote('Description') . ', ' . $grammar->quote('AppliedAt') . ') VALUES (?, ?, ?)',
            [$version, substr($description, 0, 255), date('Y-m-d H:i:s')]
        );
    }

    private function removeMigration(string $version): void
    {
        $grammar = $this->connection->getGrammar();
        $this->connection->execute(
            'DELETE FROM ' . $grammar->quote($this->migrationsTable) . ' WHERE ' . $grammar->quote('Version') . ' = ?',
            [$version]
        );
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
