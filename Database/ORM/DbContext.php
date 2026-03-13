<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\ORM;

use Miko\Database\ConnectionInterface;
use Miko\Database\Connection;
use Miko\Database\Migration\Schema;
use Miko\Database\Migration\Migrator;
use Miko\Database\Migration\TableBuilder;
use Miko\Core\Config;
use ReflectionClass;
use ReflectionProperty;
use PDO;

/**
 * DbContext - Database context with auto-migration support
 * Similar to Entity Framework DbContext / mersolutionCore DbContext
 * 
 * Usage:
 *   class AppDbContext extends DbContext {
 *       public MikoSet $Users;    // Auto-creates tblUsers table
 *       public MikoSet $Products; // Auto-creates tblProducts table
 *   }
 *   
 *   $db = new AppDbContext();
 *   $db->ensureCreated(); // Creates all tables from MikoSet properties
 */
abstract class DbContext
{
    protected ?ConnectionInterface $connection = null;
    protected ?PDO $pdo = null;
    protected array $modelTypes = [];
    protected array $mikoSets = [];

    public function __construct(?ConnectionInterface $connection = null)
    {
        if ($connection) {
            $this->connection = $connection;
            $this->pdo = $connection->getPdo();
        }
        
        $this->discoverModels();
    }

    /**
     * Get or create connection
     */
    protected function getConnection(): ConnectionInterface
    {
        if ($this->connection === null) {
            $config = $this->getConfig();
            
            // Ensure database exists
            Migrator::ensureDatabaseExists($config);
            
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'] ?? 3306,
                $config['database'],
                $config['charset'] ?? 'utf8mb4'
            );
            
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            $this->connection = new Connection($this->pdo, $config);
        }
        
        return $this->connection;
    }

    /**
     * Get database config - override in child class or use default from env
     */
    protected function getConfig(): array
    {
        return [
            'host' => Config::env('DB_HOST_LOCAL', 'localhost'),
            'port' => (int) Config::env('DB_PORT', 3306),
            'database' => Config::env('DB_DATABASE_LOCAL', 'database'),
            'username' => Config::env('DB_USERNAME_LOCAL', 'root'),
            'password' => Config::env('DB_PASSWORD_LOCAL', ''),
            'charset' => 'utf8mb4'
        ];
    }

    /**
     * Discover all MikoSet properties and their model types
     */
    protected function discoverModels(): void
    {
        $reflection = new ReflectionClass($this);
        
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            $type = $property->getType();
            
            if ($type && $type->getName() === MikoSet::class) {
                // Get model class from property name (e.g., Users -> User)
                $modelClass = $this->resolveModelClass($propertyName);
                
                if ($modelClass && class_exists($modelClass)) {
                    $this->modelTypes[$propertyName] = $modelClass;
                    
                    // Create MikoSet instance
                    $mikoSet = new MikoSet($modelClass);
                    $this->mikoSets[$propertyName] = $mikoSet;
                    $property->setValue($this, $mikoSet);
                }
            }
        }
    }

    /**
     * Resolve model class from property name
     * Override this method to customize model resolution
     */
    protected function resolveModelClass(string $propertyName): ?string
    {
        // Try common patterns:
        // Users -> User, Products -> Product, Categories -> Category
        $singular = rtrim($propertyName, 's');
        if (substr($propertyName, -3) === 'ies') {
            $singular = substr($propertyName, 0, -3) . 'y';
        }
        
        // Check in common namespaces
        $namespaces = [
            'KobiLite\\Models\\',
            'App\\Models\\',
            'Models\\',
            ''
        ];
        
        foreach ($namespaces as $ns) {
            $class = $ns . $singular;
            if (class_exists($class)) {
                return $class;
            }
        }
        
        return null;
    }

    /**
     * Ensure database and all tables are created (like EF EnsureCreated)
     */
    public function ensureCreated(): void
    {
        $connection = $this->getConnection();
        $schema = new Schema($connection);
        
        foreach ($this->modelTypes as $propertyName => $modelClass) {
            $this->createTableForModel($schema, $modelClass);
        }
        
        echo "✓ Database ensured\n";
    }

    /**
     * Drop all tables
     */
    public function ensureDeleted(): void
    {
        $connection = $this->getConnection();
        $schema = new Schema($connection);
        
        // Drop in reverse order (for foreign keys)
        foreach (array_reverse($this->modelTypes) as $modelClass) {
            $tableName = $this->getTableName($modelClass);
            if ($schema->hasTable($tableName)) {
                $schema->dropIfExists($tableName);
                echo "✓ Dropped: {$tableName}\n";
            }
        }
    }

    /**
     * Drop and recreate all tables (fresh start)
     */
    public function ensureFresh(): void
    {
        $this->ensureDeleted();
        $this->ensureCreated();
    }

    /**
     * Create table for a model class (auto-migration from model definition)
     */
    protected function createTableForModel(Schema $schema, string $modelClass): void
    {
        $tableName = $this->getTableName($modelClass);
        
        // Check if table already exists
        if ($schema->hasTable($tableName)) {
            return;
        }
        
        // Check if model has defineSchema method
        if (method_exists($modelClass, 'defineSchema')) {
            $schema->create($tableName, function (TableBuilder $table) use ($modelClass) {
                $modelClass::defineSchema($table);
            });
            echo "✓ Created: {$tableName}\n";
            return;
        }
        
        // Auto-generate from model metadata/attributes
        $metadata = ModelMetadata::for($modelClass);
        
        $schema->create($tableName, function (TableBuilder $table) use ($metadata) {
            foreach ($metadata->columns as $propertyName => $column) {
                $colName = $column['name'];
                $type = $column['type'];
                $nullable = $column['nullable'];
                $default = $column['default'];
                $length = $column['length'];
                $precision = $column['precision'];
                $scale = $column['scale'];
                
                // Primary key
                if ($propertyName === $metadata->primaryKey) {
                    if ($metadata->primaryKeyAutoIncrement) {
                        $table->id($colName);
                    } else {
                        $col = $table->integer($colName);
                        $col->notNull();
                    }
                    continue;
                }
                
                // Determine column type
                $col = $this->createColumn($table, $colName, $type, $length, $precision, $scale);
                
                if ($col) {
                    if ($nullable) {
                        $col->nullable();
                    } else {
                        $col->notNull();
                    }
                    
                    if ($default !== null) {
                        $col->default($default);
                    }
                }
            }
        });
        
        echo "✓ Created: {$tableName}\n";
    }

    /**
     * Create column based on type
     */
    protected function createColumn(TableBuilder $table, string $name, ?string $type, ?int $length, ?int $precision, ?int $scale)
    {
        if ($type === null) {
            return $table->string($name, $length ?? 255);
        }
        
        $type = strtoupper($type);
        
        switch ($type) {
            case 'INT':
            case 'INTEGER':
                return $table->integer($name);
            case 'BIGINT':
                return $table->bigInteger($name);
            case 'SMALLINT':
                return $table->smallInteger($name);
            case 'TINYINT':
                return $table->tinyInteger($name);
            case 'VARCHAR':
            case 'STRING':
                return $table->string($name, $length ?? 255);
            case 'TEXT':
                return $table->text($name);
            case 'MEDIUMTEXT':
                return $table->mediumText($name);
            case 'LONGTEXT':
                return $table->longText($name);
            case 'DECIMAL':
                return $table->decimal($name, $precision ?? 10, $scale ?? 2);
            case 'FLOAT':
                return $table->float($name);
            case 'DOUBLE':
                return $table->double($name);
            case 'BOOLEAN':
            case 'BOOL':
                return $table->boolean($name);
            case 'DATE':
                return $table->date($name);
            case 'DATETIME':
                return $table->dateTime($name);
            case 'TIMESTAMP':
                return $table->timestamp($name);
            case 'TIME':
                return $table->time($name);
            case 'JSON':
                return $table->json($name);
            case 'BLOB':
            case 'BINARY':
                return $table->binary($name);
            default:
                return $table->string($name, $length ?? 255);
        }
    }

    /**
     * Get table name for model class
     */
    protected function getTableName(string $modelClass): string
    {
        // Check for static $table property
        if (property_exists($modelClass, 'table')) {
            $reflection = new ReflectionClass($modelClass);
            $prop = $reflection->getProperty('table');
            $prop->setAccessible(true);
            return $prop->getValue();
        }
        
        // Check for Table attribute
        $reflection = new ReflectionClass($modelClass);
        $tableAttrs = $reflection->getAttributes(Table::class);
        if (!empty($tableAttrs)) {
            $table = $tableAttrs[0]->newInstance();
            return $table->name;
        }
        
        // Generate from class name
        $shortName = $reflection->getShortName();
        return 'tbl' . $shortName . 's';
    }

    /**
     * Get MikoSet by name
     */
    public function __get(string $name): ?MikoSet
    {
        return $this->mikoSets[$name] ?? null;
    }
}
