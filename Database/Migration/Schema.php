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
use PDO;

/**
 * Schema - Database schema operations
 * Similar to mersolutionCore Schema class
 */
class Schema
{
    private ConnectionInterface $connection;
    private PDO $pdo;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
    }

    /**
     * Create a new table
     */
    public function createTable(string $tableName, callable $callback): void
    {
        $builder = new TableBuilder($tableName);
        $callback($builder);
        
        $sql = $builder->build();
        $this->pdo->exec($sql);

        // Create indexes
        foreach ($builder->getIndexStatements() as $indexSql) {
            $this->pdo->exec($indexSql);
        }
    }

    /**
     * Alias for createTable
     */
    public function create(string $tableName, callable $callback): void
    {
        $this->createTable($tableName, $callback);
    }

    /**
     * Drop a table
     */
    public function dropTable(string $tableName): void
    {
        $this->pdo->exec("DROP TABLE `{$tableName}`");
    }

    /**
     * Drop a table if exists
     */
    public function dropTableIfExists(string $tableName): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
    }

    /**
     * Alias for dropTableIfExists
     */
    public function dropIfExists(string $tableName): void
    {
        $this->dropTableIfExists($tableName);
    }

    /**
     * Rename a table
     */
    public function renameTable(string $oldName, string $newName): void
    {
        $this->pdo->exec("RENAME TABLE `{$oldName}` TO `{$newName}`");
    }

    /**
     * Check if table exists
     */
    public function hasTable(string $tableName): bool
    {
        $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return $stmt->fetch() !== false;
    }

    /**
     * Add a column to existing table
     */
    public function addColumn(string $tableName, string $columnName, string $type, array $options = []): void
    {
        $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` {$type}";
        
        if (!($options['nullable'] ?? true)) {
            $sql .= ' NOT NULL';
        }
        
        if (isset($options['default'])) {
            $default = is_string($options['default']) ? "'{$options['default']}'" : $options['default'];
            $sql .= " DEFAULT {$default}";
        }
        
        if (isset($options['after'])) {
            $sql .= " AFTER `{$options['after']}`";
        }
        
        $this->pdo->exec($sql);
    }

    /**
     * Drop a column from table
     */
    public function dropColumn(string $tableName, string $columnName): void
    {
        $this->pdo->exec("ALTER TABLE `{$tableName}` DROP COLUMN `{$columnName}`");
    }

    /**
     * Rename a column
     */
    public function renameColumn(string $tableName, string $oldName, string $newName, string $type): void
    {
        $this->pdo->exec("ALTER TABLE `{$tableName}` CHANGE `{$oldName}` `{$newName}` {$type}");
    }

    /**
     * Modify a column
     */
    public function modifyColumn(string $tableName, string $columnName, string $type, array $options = []): void
    {
        $sql = "ALTER TABLE `{$tableName}` MODIFY COLUMN `{$columnName}` {$type}";
        
        if (!($options['nullable'] ?? true)) {
            $sql .= ' NOT NULL';
        }
        
        if (isset($options['default'])) {
            $default = is_string($options['default']) ? "'{$options['default']}'" : $options['default'];
            $sql .= " DEFAULT {$default}";
        }
        
        $this->pdo->exec($sql);
    }

    /**
     * Check if column exists
     */
    public function hasColumn(string $tableName, string $columnName): bool
    {
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE ?");
        $stmt->execute([$columnName]);
        return $stmt->fetch() !== false;
    }

    /**
     * Create an index
     */
    public function createIndex(string $tableName, string $indexName, array $columns): void
    {
        $cols = '`' . implode('`, `', $columns) . '`';
        $this->pdo->exec("CREATE INDEX `{$indexName}` ON `{$tableName}` ({$cols})");
    }

    /**
     * Create a unique index
     */
    public function createUniqueIndex(string $tableName, string $indexName, array $columns): void
    {
        $cols = '`' . implode('`, `', $columns) . '`';
        $this->pdo->exec("CREATE UNIQUE INDEX `{$indexName}` ON `{$tableName}` ({$cols})");
    }

    /**
     * Drop an index
     */
    public function dropIndex(string $tableName, string $indexName): void
    {
        $this->pdo->exec("DROP INDEX `{$indexName}` ON `{$tableName}`");
    }

    /**
     * Add foreign key
     */
    public function addForeignKey(string $tableName, string $column, string $referencesTable, string $referencesColumn = 'Id', string $onDelete = 'CASCADE'): void
    {
        $fkName = "fk_{$tableName}_{$column}";
        $sql = "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$fkName}` " .
               "FOREIGN KEY (`{$column}`) REFERENCES `{$referencesTable}` (`{$referencesColumn}`) " .
               "ON DELETE {$onDelete}";
        $this->pdo->exec($sql);
    }

    /**
     * Drop foreign key
     */
    public function dropForeignKey(string $tableName, string $fkName): void
    {
        $this->pdo->exec("ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$fkName}`");
    }

    /**
     * Execute raw SQL
     */
    public function raw(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /**
     * Get connection
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
