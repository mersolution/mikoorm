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

use Miko\Core\Database\DatabaseConfig;
use Miko\Database\ConnectionFactory;
use Miko\Database\ConnectionInterface;
use Miko\Database\ConnectionResolver;
use Miko\Database\Migration\Migrator;
use Miko\Database\Migration\Schema;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * DbContext - Database context with auto-migration support
 * Similar to Entity Framework DbContext / mersolutionCore DbContext
 *
 *   class AppDbContext extends DbContext {
 *       public MikoSet $Users;    // -> App\Models\User / Models\User / User
 *       public MikoSet $Products;
 *
 *       protected function getConfig(): array { return [...]; }  // optional
 *   }
 *
 *   $db = new AppDbContext();
 *   $db->ensureCreated();    // run once at setup / deploy, not on every request
 *   User::create([...]);     // models of the context use the context connection
 */
abstract class DbContext
{
    protected ?ConnectionInterface $connection = null;
    protected array $modelTypes = [];
    protected array $mikoSets = [];

    /**
     * Optional explicit property => model class map
     */
    protected array $models = [];

    public function __construct(?ConnectionInterface $connection = null)
    {
        $this->connection = $connection;
        $this->discoverModels();

        $resolver = fn(): ConnectionInterface => $this->getConnection();

        foreach ($this->modelTypes as $modelClass) {
            $modelClass::setConnection($resolver);
        }

        if (!ConnectionResolver::hasDefault()) {
            ConnectionResolver::setDefault($resolver);
        }
    }

    /**
     * Connection of this context (created on first use)
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection ??= ConnectionFactory::make($this->getConfig());
    }

    /**
     * Connection config - override in your context.
     * Default: the "default" connection of Config/Database.php (.env DB_* keys).
     */
    protected function getConfig(): array
    {
        return DatabaseConfig::connectionConfig();
    }

    protected function discoverModels(): void
    {
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();

            if (!$type instanceof ReflectionNamedType || $type->getName() !== MikoSet::class) {
                continue;
            }

            $propertyName = $property->getName();
            $modelClass = $this->resolveModelClass($propertyName);

            if ($modelClass === null) {
                continue;
            }

            $this->modelTypes[$propertyName] = $modelClass;
            $this->mikoSets[$propertyName] = new MikoSet($modelClass);
            $property->setValue($this, $this->mikoSets[$propertyName]);
        }
    }

    /**
     * Property name to model class: Users -> User, Categories -> Category,
     * Addresses -> Address, Statuses -> Status. Override or use $models for others.
     */
    protected function resolveModelClass(string $propertyName): ?string
    {
        if (isset($this->models[$propertyName])) {
            return class_exists($this->models[$propertyName]) ? $this->models[$propertyName] : null;
        }

        $namespace = (new ReflectionClass($this))->getNamespaceName();
        $namespaces = array_unique(['App\\Models\\', 'Models\\', $namespace !== '' ? $namespace . '\\' : '', '']);

        foreach (self::singularCandidates($propertyName) as $candidate) {
            foreach ($namespaces as $ns) {
                $class = $ns . $candidate;
                if (class_exists($class) && is_subclass_of($class, Model::class)) {
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * Singular forms to try, most specific first
     */
    public static function singularCandidates(string $name): array
    {
        $candidates = [];

        if (preg_match('/ies$/i', $name)) {
            $candidates[] = substr($name, 0, -3) . 'y';
        }
        if (preg_match('/(ss|x|ch|sh|z|us)es$/i', $name)) {
            $candidates[] = substr($name, 0, -2);
        }
        if (preg_match('/[^s]s$/i', $name) || preg_match('/ses$/i', $name)) {
            $candidates[] = substr($name, 0, -1);
        }
        $candidates[] = $name;

        return array_values(array_unique($candidates));
    }

    /**
     * Create the database (MySQL) and every missing table
     *
     * @return string[] created table names
     */
    public function ensureCreated(): array
    {
        if ($this->connection === null) {
            $config = $this->getConfig();
            if (($config['driver'] ?? 'mysql') === 'mysql' && !empty($config['database'])) {
                Migrator::ensureDatabaseExists($config);
            }
        }

        $schema = new Schema($this->getConnection());
        $created = [];

        foreach ($this->modelTypes as $modelClass) {
            $table = $modelClass::getTable();
            if (!$schema->hasTable($table)) {
                $modelClass::createTableUsing($schema);
                $created[] = $table;
            }
        }

        return $created;
    }

    /**
     * Drop every table of the context (reverse order)
     *
     * @return string[] dropped table names
     */
    public function ensureDeleted(): array
    {
        $schema = new Schema($this->getConnection());
        $dropped = [];

        foreach (array_reverse($this->modelTypes) as $modelClass) {
            $table = $modelClass::getTable();
            if ($schema->hasTable($table)) {
                $schema->dropIfExists($table);
                $dropped[] = $table;
            }
        }

        return $dropped;
    }

    /**
     * Drop and recreate all tables
     */
    public function ensureFresh(): array
    {
        $this->ensureDeleted();
        return $this->ensureCreated();
    }

    public function getModelTypes(): array
    {
        return $this->modelTypes;
    }

    public function __get(string $name): ?MikoSet
    {
        return $this->mikoSets[$name] ?? null;
    }
}
