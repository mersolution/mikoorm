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
use Miko\Database\DatabaseInterface;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\ORM\Relations\HasOne;
use Miko\Database\ORM\Relations\HasMany;
use Miko\Database\ORM\Relations\BelongsTo;
use Miko\Database\ORM\Relations\BelongsToMany;
use Miko\Database\ORM\Traits\HasEvents;
use Miko\Database\ORM\Traits\HasTimestamps;
use Miko\Database\ORM\Traits\HasMigration;
use Miko\Database\ORM\Events\ModelEvent;
use Miko\Core\Config;
use Miko\Core\Database\DatabaseConfig;

/**
 * Modern ORM Model base class - replaces old ORM
 * 
 * Supports Code-First migrations like Entity Framework:
 * - Define schema in defineSchema() method
 * - Call Model::up() to create table
 * - Call Model::down() to drop table
 * - Call Model::migrate() to create if not exists
 */
abstract class Model
{
    use HasEvents, HasTimestamps, HasMigration;
    /**
     * Table name
     */
    protected static string $table;

    /**
     * Primary key column
     */
    protected string $primaryKey = 'id';

    /**
     * Database connection
     */
    protected static ?DatabaseInterface $database = null;

    /**
     * Connection instance cache (per model class)
     */
    protected static array $connectionCache = [];

    /**
     * Model attributes
     */
    protected array $attributes = [];

    /**
     * Original attributes (for dirty checking)
     */
    protected array $original = [];

    /**
     * Indicates if model exists in database
     */
    protected bool $exists = false;

    /**
     * Query builder instance
     */
    protected ?QueryBuilder $query = null;

    /**
     * Loaded relationships
     */
    protected array $relations = [];

    /**
     * Relationships to eager load
     */
    protected array $with = [];

    /**
     * Global scopes
     */
    protected static array $globalScopes = [];

    /**
     * Attributes that should be cast to native types
     * 
     * Supported: 'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean',
     *            'array', 'json', 'object', 'date', 'datetime', 'timestamp'
     */
    protected array $casts = [];

    /**
     * Attributes that should be hidden for serialization
     */
    protected array $hidden = [];

    /**
     * Attributes that should be visible for serialization
     */
    protected array $visible = [];

    /**
     * Attributes that are mass assignable
     */
    protected array $fillable = [];

    /**
     * Attributes that are not mass assignable
     */
    protected array $guarded = ['id'];

    /**
     * Accessors to append to the model's array form
     */
    protected array $appends = [];

    /**
     * Set the database instance
     */
    public static function setDatabase(DatabaseInterface $database): void
    {
        static::$database = $database;
    }

    /**
     * Get database connection
     */
    public function getConnection(): ConnectionInterface
    {
        $class = static::class;
        
        // Önce cache'e bak (model bazlı)
        if (isset(static::$connectionCache[$class])) {
            return static::$connectionCache[$class];
        }

        // Eski sistem (backward compatibility)
        if (static::$database !== null) {
            static::$connectionCache[$class] = static::$database->connection();
            return static::$connectionCache[$class];
        }

        // Yeni Config sistemi (otomatik)
        try {
            static::$connectionCache[$class] = DatabaseConfig::createConnection();
            return static::$connectionCache[$class];
        } catch (\Exception $e) {
            throw new DatabaseException('Database connection failed. Make sure Config is loaded or call Model::setDatabase(). Error: ' . $e->getMessage());
        }
    }

    /**
     * Set a specific connection for this model
     */
    public static function setConnection(ConnectionInterface $connection): void
    {
        static::$connectionCache[static::class] = $connection;
    }

    /**
     * Clear connection cache
     */
    public static function clearConnectionCache(): void
    {
        unset(static::$connectionCache[static::class]);
    }

    /**
     * Get table name
     */
    public static function getTable(): string
    {
        return static::validateTableName(static::$table);
    }

    /**
     * Validate table name to prevent SQL injection
     */
    protected static function validateTableName(string $table): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new DatabaseException("Invalid table name: {$table}");
        }
        return $table;
    }

    /**
     * Find a model by primary key
     */
    public static function find(mixed $id): ?static
    {
        $instance = new static();
        
        $result = $instance->getConnection()->execute(
            "SELECT * FROM " . static::$table . " WHERE {$instance->primaryKey} = ? LIMIT 1",
            [$id]
        );

        $data = $result->first();

        if ($data === null) {
            return null;
        }

        return static::hydrate($data);
    }

    /**
     * Find multiple models by primary keys
     */
    public static function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $instance = new static();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $result = $instance->getConnection()->execute(
            "SELECT * FROM " . static::$table . " WHERE {$instance->primaryKey} IN ({$placeholders})",
            $ids
        );

        return array_map([static::class, 'hydrate'], $result->all());
    }

    /**
     * Get all models
     */
    public static function all(): array
    {
        $instance = new static();
        
        $result = $instance->getConnection()->execute(
            "SELECT * FROM " . static::$table
        );

        return array_map([static::class, 'hydrate'], $result->all());
    }

    /**
     * Create a new query builder
     */
    public static function query(): QueryBuilder
    {
        $query = new QueryBuilder(new static());
        
        // Apply global scopes
        static::applyGlobalScopes($query);
        
        return $query;
    }

    /**
     * Where clause
     */
    public static function where(string $column, string $operator, mixed $value): QueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    /**
     * Call local scope
     */
    public static function __callStatic(string $method, array $parameters)
    {
        return (new static())->$method(...$parameters);
    }

    /**
     * Handle dynamic method calls
     */
    public function __call(string $method, array $parameters)
    {
        // Check if it's a scope method
        $scopeMethod = 'scope' . ucfirst($method);
        
        if (method_exists($this, $scopeMethod)) {
            $query = static::query();
            array_unshift($parameters, $query);
            $this->$scopeMethod(...$parameters);
            return $query;
        }

        throw new DatabaseException("Method {$method} does not exist on " . static::class);
    }

    /**
     * Order by clause
     */
    public static function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        return static::query()->orderBy($column, $direction);
    }

    /**
     * Limit clause
     */
    public static function limit(int $limit): QueryBuilder
    {
        return static::query()->limit($limit);
    }

    /**
     * Get first result
     */
    public static function first(): ?static
    {
        return static::query()->first();
    }

    /**
     * Get first result or throw exception
     */
    public static function firstOrFail(): static
    {
        $result = static::first();
        
        if ($result === null) {
            throw new DatabaseException('No records found in ' . static::$table);
        }
        
        return $result;
    }

    /**
     * Get single result (throws if more than one exists)
     */
    public static function single(): ?static
    {
        $instance = new static();
        $result = $instance->getConnection()->query(
            "SELECT * FROM " . static::$table . " LIMIT 2"
        );
        
        $rows = $result->all();
        
        if (count($rows) > 1) {
            throw new DatabaseException('More than one record found in ' . static::$table);
        }
        
        if (count($rows) === 0) {
            return null;
        }
        
        return static::hydrate($rows[0]);
    }

    /**
     * Get single result or throw exception
     */
    public static function singleOrFail(): static
    {
        $result = static::single();
        
        if ($result === null) {
            throw new DatabaseException('No records found in ' . static::$table);
        }
        
        return $result;
    }

    /**
     * Check if any records match the query
     */
    public static function any(): bool
    {
        return static::exists();
    }

    /**
     * Find by ID or throw exception
     */
    public static function findOrFail(mixed $id): static
    {
        $result = static::find($id);
        
        if ($result === null) {
            throw new DatabaseException("No record found with ID {$id} in " . static::$table);
        }
        
        return $result;
    }

    /**
     * Find first matching record or create new one
     */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        $instance = new static();
        $query = static::query();
        
        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }
        
        $result = $query->first();
        
        if ($result !== null) {
            return $result;
        }
        
        return static::create(array_merge($attributes, $values));
    }

    /**
     * Find first matching record or instantiate new one (without saving)
     */
    public static function firstOrNew(array $attributes, array $values = []): static
    {
        $query = static::query();
        
        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }
        
        $result = $query->first();
        
        if ($result !== null) {
            return $result;
        }
        
        return new static(array_merge($attributes, $values));
    }

    /**
     * Update existing record or create new one
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $query = static::query();
        
        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }
        
        $result = $query->first();
        
        if ($result !== null) {
            $result->fill($values);
            $result->save();
            return $result;
        }
        
        return static::create(array_merge($attributes, $values));
    }

    /**
     * Create a new model
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Create multiple models at once
     */
    public static function createMany(array $records): array
    {
        $models = [];
        foreach ($records as $attributes) {
            $models[] = static::create($attributes);
        }
        return $models;
    }

    /**
     * Insert multiple records (bulk insert, no model events)
     */
    public static function insert(array $records): bool
    {
        if (empty($records)) {
            return true;
        }

        $instance = new static();
        $connection = $instance->getConnection();
        
        $columns = array_keys($records[0]);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($records), $placeholders));
        
        $sql = "INSERT INTO " . static::$table . " (" . implode(', ', $columns) . ") VALUES {$allPlaceholders}";
        
        $bindings = [];
        foreach ($records as $record) {
            foreach ($columns as $column) {
                $bindings[] = $record[$column] ?? null;
            }
        }

        $connection->execute($sql, $bindings);
        return true;
    }

    /**
     * Delete multiple records by IDs
     */
    public static function destroy(mixed $ids): int
    {
        $ids = is_array($ids) ? $ids : func_get_args();
        
        if (empty($ids)) {
            return 0;
        }

        $instance = new static();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        
        $sql = "DELETE FROM " . static::$table . " WHERE {$instance->primaryKey} IN ({$placeholders})";
        
        $instance->getConnection()->execute($sql, $ids);
        
        return count($ids);
    }

    /**
     * Check if any records exist
     */
    public static function exists(): bool
    {
        $instance = new static();
        $result = $instance->getConnection()->query(
            "SELECT 1 FROM " . static::$table . " LIMIT 1"
        );
        return count($result->all()) > 0;
    }

    /**
     * Check if no records exist
     */
    public static function doesntExist(): bool
    {
        return !static::exists();
    }

    /**
     * Count all records
     */
    public static function count(): int
    {
        return static::query()->count();
    }

    /**
     * Sum a column
     */
    public static function sum(string $column): int|float
    {
        return static::query()->sum($column);
    }

    /**
     * Average a column
     */
    public static function avg(string $column): int|float
    {
        return static::query()->avg($column);
    }

    /**
     * Min value of a column
     */
    public static function min(string $column): mixed
    {
        return static::query()->min($column);
    }

    /**
     * Max value of a column
     */
    public static function max(string $column): mixed
    {
        return static::query()->max($column);
    }

    /**
     * Pluck a single column's value from all records
     */
    public static function pluck(string $column, ?string $key = null): array
    {
        return static::query()->pluck($column, $key);
    }

    /**
     * Increment a column's value
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): bool
    {
        if (!$this->exists) {
            return false;
        }

        $this->attributes[$column] = ($this->attributes[$column] ?? 0) + $amount;
        
        foreach ($extra as $key => $value) {
            $this->attributes[$key] = $value;
        }

        $columns = [$column => $this->attributes[$column]] + $extra;
        
        if ($this->usesTimestamps()) {
            $columns['updated_at'] = date('Y-m-d H:i:s');
            $this->attributes['updated_at'] = $columns['updated_at'];
        }

        $setClauses = [];
        $bindings = [];
        foreach ($columns as $col => $val) {
            $setClauses[] = "{$col} = ?";
            $bindings[] = $val;
        }
        $bindings[] = $this->getKey();

        $sql = "UPDATE " . static::$table . " SET " . implode(', ', $setClauses) . 
               " WHERE {$this->primaryKey} = ?";

        $this->getConnection()->execute($sql, $bindings);
        $this->original = $this->attributes;

        return true;
    }

    /**
     * Decrement a column's value
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): bool
    {
        return $this->increment($column, -$amount, $extra);
    }

    /**
     * Save the model
     */
    public function save(): bool
    {
        // Fire saving event
        if ($this->fireModelEvent(ModelEvent::SAVING) === false) {
            return false;
        }

        if ($this->exists) {
            $saved = $this->performUpdate();
        } else {
            $saved = $this->performInsert();
        }

        if ($saved) {
            // Fire saved event
            $this->fireModelEvent(ModelEvent::SAVED);
        }

        return $saved;
    }

    /**
     * Insert new model
     */
    protected function performInsert(): bool
    {
        // Fire creating event
        if ($this->fireModelEvent(ModelEvent::CREATING) === false) {
            return false;
        }

        // Update timestamps
        $this->updateTimestamps();

        $columns = array_keys($this->attributes);
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($this->attributes);

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            static::$table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $connection = $this->getConnection();
        $connection->execute($sql, $values);

        // Get last insert ID
        $this->attributes[$this->primaryKey] = $connection->lastInsertId();
        $this->exists = true;
        $this->original = $this->attributes;

        // Fire created event
        $this->fireModelEvent(ModelEvent::CREATED);

        return true;
    }

    /**
     * Update existing model
     */
    protected function performUpdate(): bool
    {
        // Fire updating event
        if ($this->fireModelEvent(ModelEvent::UPDATING) === false) {
            return false;
        }

        // Update timestamps
        $this->updateTimestamps();

        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return true; // Nothing to update
        }

        $sets = array_map(fn($col) => "$col = ?", array_keys($dirty));
        $values = array_values($dirty);
        $values[] = $this->attributes[$this->primaryKey];

        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s = ?",
            static::$table,
            implode(', ', $sets),
            $this->primaryKey
        );

        $this->getConnection()->execute($sql, $values);
        $this->original = $this->attributes;

        // Fire updated event
        $this->fireModelEvent(ModelEvent::UPDATED);

        return true;
    }

    /**
     * Delete the model
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        // Fire deleting event
        if ($this->fireModelEvent(ModelEvent::DELETING) === false) {
            return false;
        }

        $sql = sprintf(
            "DELETE FROM %s WHERE %s = ?",
            static::$table,
            $this->primaryKey
        );

        $this->getConnection()->execute($sql, [$this->attributes[$this->primaryKey]]);
        $this->exists = false;

        // Fire deleted event
        $this->fireModelEvent(ModelEvent::DELETED);

        return true;
    }

    /**
     * Get dirty attributes (changed since last save)
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Check if model is dirty
     */
    public function isDirty(): bool
    {
        return !empty($this->getDirty());
    }

    /**
     * Hydrate model from array
     */
    public static function hydrate(array $data): static
    {
        $model = new static();
        $model->attributes = $data;
        $model->original = $data;
        $model->exists = true;

        return $model;
    }

    /**
     * Booted models cache
     */
    protected static array $booted = [];

    /**
     * Constructor
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
        $this->bootIfNotBooted();
    }

    /**
     * Boot the model if not already booted
     */
    protected function bootIfNotBooted(): void
    {
        if (!isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;
            static::bootTraits();
            static::boot();
        }
    }

    /**
     * Boot all traits on the model
     */
    protected static function bootTraits(): void
    {
        $class = static::class;
        $traits = [];
        
        // Get all traits including parent classes
        do {
            $traits = array_merge($traits, class_uses($class) ?: []);
        } while ($class = get_parent_class($class));
        
        foreach ($traits as $trait) {
            $method = 'boot' . (new \ReflectionClass($trait))->getShortName();
            if (method_exists(static::class, $method)) {
                forward_static_call([static::class, $method]);
            }
        }
    }

    /**
     * Boot the model (override in child classes)
     */
    protected static function boot(): void
    {
        // Override in child classes for custom boot logic
    }

    /**
     * Get attribute or relationship
     */
    public function __get(string $key): mixed
    {
        // Check if it's a loaded relationship (cached)
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        // Check for accessor method
        $accessor = 'get' . $this->studly($key) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor($this->attributes[$key] ?? null);
        }

        // Check if it's an attribute
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        // Try to load relationship dynamically (lazy loading)
        // WARNING: This can cause N+1 queries! Use with() for eager loading
        if (method_exists($this, $key)) {
            $relation = $this->$key();
            if ($relation instanceof \Miko\Database\ORM\Relations\Relation) {
                // Cache the result to prevent multiple queries
                $this->relations[$key] = $relation->getResults();
                return $this->relations[$key];
            }
        }

        return null;
    }

    /**
     * Set attribute
     */
    public function __set(string $key, mixed $value): void
    {
        // Check for mutator method
        $mutator = 'set' . $this->studly($key) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $this->$mutator($value);
            return;
        }

        $this->attributes[$key] = $value;
    }

    /**
     * Check if attribute exists
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Define a one-to-one relationship
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a one-to-many relationship
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define an inverse one-to-one or one-to-many relationship
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Define a many-to-many relationship
     */
    protected function belongsToMany(
        string $related,
        string $pivotTable,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): BelongsToMany {
        return new BelongsToMany(
            $this,
            $related,
            $pivotTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey
        );
    }

    /**
     * Set a relationship value
     */
    public function setRelation(string $relation, mixed $value): self
    {
        $this->relations[$relation] = $value;
        return $this;
    }

    /**
     * Get a relationship value
     */
    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation] ?? null;
    }

    /**
     * Check if relationship is loaded
     */
    public function relationLoaded(string $relation): bool
    {
        return array_key_exists($relation, $this->relations);
    }

    /**
     * Load relationships
     */
    public function load(string|array $relations): self
    {
        $relations = is_array($relations) ? $relations : func_get_args();

        foreach ($relations as $relation) {
            if (!$this->relationLoaded($relation)) {
                if (method_exists($this, $relation)) {
                    $relationObj = $this->$relation();
                    if ($relationObj instanceof \Miko\Database\ORM\Relations\Relation) {
                        $this->relations[$relation] = $relationObj->getResults();
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Get primary key name
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get primary key value
     */
    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    /**
     * Convert string to studly case (PascalCase)
     */
    protected function studly(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }

    /**
     * Get a plain attribute (without accessor)
     */
    public function getAttributeValue(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Set a plain attribute (without mutator)
     */
    public function setAttributeValue(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    // ========================================
    // Attribute Casting
    // ========================================

    /**
     * Cast an attribute to a native PHP type
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $castType = $this->casts[$key] ?? null;

        if ($castType === null) {
            return $value;
        }

        switch ($castType) {
            case 'int':
            case 'integer':
                return (int) $value;

            case 'float':
            case 'double':
                return (float) $value;

            case 'string':
                return (string) $value;

            case 'bool':
            case 'boolean':
                return (bool) $value;

            case 'array':
            case 'json':
                if (is_array($value)) {
                    return $value;
                }
                return json_decode($value, true) ?? [];

            case 'object':
                if (is_object($value)) {
                    return $value;
                }
                return json_decode($value);

            case 'date':
                if ($value instanceof \DateTime) {
                    return $value->format('Y-m-d');
                }
                return date('Y-m-d', strtotime($value));

            case 'datetime':
            case 'timestamp':
                if ($value instanceof \DateTime) {
                    return $value->format('Y-m-d H:i:s');
                }
                return date('Y-m-d H:i:s', strtotime($value));

            default:
                return $value;
        }
    }

    /**
     * Prepare a value for database storage
     */
    protected function castAttributeForDatabase(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $castType = $this->casts[$key] ?? null;

        if ($castType === null) {
            return $value;
        }

        switch ($castType) {
            case 'array':
            case 'json':
            case 'object':
                return json_encode($value);

            case 'bool':
            case 'boolean':
                return $value ? 1 : 0;

            default:
                return $value;
        }
    }

    /**
     * Get casted attribute
     */
    public function getCastedAttribute(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;
        return $this->castAttribute($key, $value);
    }

    /**
     * Check if attribute has a cast
     */
    public function hasCast(string $key): bool
    {
        return isset($this->casts[$key]);
    }

    /**
     * Get all casts
     */
    public function getCasts(): array
    {
        return $this->casts;
    }

    // ========================================
    // Hidden / Visible Attributes
    // ========================================

    /**
     * Set hidden attributes
     */
    public function setHidden(array $hidden): self
    {
        $this->hidden = $hidden;
        return $this;
    }

    /**
     * Get hidden attributes
     */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    /**
     * Set visible attributes
     */
    public function setVisible(array $visible): self
    {
        $this->visible = $visible;
        return $this;
    }

    /**
     * Get visible attributes
     */
    public function getVisible(): array
    {
        return $this->visible;
    }

    /**
     * Make attributes visible temporarily
     */
    public function makeVisible(array|string $attributes): self
    {
        $attributes = is_array($attributes) ? $attributes : [$attributes];
        
        $this->hidden = array_diff($this->hidden, $attributes);
        
        if (!empty($this->visible)) {
            $this->visible = array_merge($this->visible, $attributes);
        }
        
        return $this;
    }

    /**
     * Make attributes hidden temporarily
     */
    public function makeHidden(array|string $attributes): self
    {
        $attributes = is_array($attributes) ? $attributes : [$attributes];
        $this->hidden = array_merge($this->hidden, $attributes);
        return $this;
    }

    // ========================================
    // Fillable / Guarded (Mass Assignment)
    // ========================================

    /**
     * Get fillable attributes
     */
    public function getFillable(): array
    {
        return $this->fillable;
    }

    /**
     * Get guarded attributes
     */
    public function getGuarded(): array
    {
        return $this->guarded;
    }

    /**
     * Check if attribute is fillable
     */
    public function isFillable(string $key): bool
    {
        // If fillable is defined, check if key is in it
        if (!empty($this->fillable)) {
            return in_array($key, $this->fillable);
        }

        // If guarded is ['*'], nothing is fillable
        if ($this->guarded === ['*']) {
            return false;
        }

        // Otherwise, check if key is not guarded
        return !in_array($key, $this->guarded);
    }

    /**
     * Check if attribute is guarded
     */
    public function isGuarded(string $key): bool
    {
        return !$this->isFillable($key);
    }

    /**
     * Fill model with mass assignable attributes
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    /**
     * Force fill model (bypass guarded)
     */
    public function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    // ========================================
    // Serialization (toArray / toJson)
    // ========================================

    /**
     * Convert model to array
     */
    public function toArray(): array
    {
        $attributes = $this->attributesToArray();
        $relations = $this->relationsToArray();

        return array_merge($attributes, $relations);
    }

    /**
     * Convert attributes to array (with hidden/visible filtering)
     */
    protected function attributesToArray(): array
    {
        $attributes = [];

        foreach ($this->attributes as $key => $value) {
            // Apply casting
            if (isset($this->casts[$key])) {
                $value = $this->castAttribute($key, $value);
            }

            // Check accessor
            $accessor = 'get' . $this->studly($key) . 'Attribute';
            if (method_exists($this, $accessor)) {
                $value = $this->$accessor($value);
            }

            $attributes[$key] = $value;
        }

        // Add appended attributes
        foreach ($this->appends as $key) {
            $accessor = 'get' . $this->studly($key) . 'Attribute';
            if (method_exists($this, $accessor)) {
                $attributes[$key] = $this->$accessor(null);
            }
        }

        // Filter by visible
        if (!empty($this->visible)) {
            $attributes = array_intersect_key($attributes, array_flip($this->visible));
        }

        // Filter by hidden
        if (!empty($this->hidden)) {
            $attributes = array_diff_key($attributes, array_flip($this->hidden));
        }

        return $attributes;
    }

    /**
     * Convert relations to array
     */
    protected function relationsToArray(): array
    {
        $relations = [];

        foreach ($this->relations as $key => $value) {
            // Skip hidden relations
            if (in_array($key, $this->hidden)) {
                continue;
            }

            // Check visible filter
            if (!empty($this->visible) && !in_array($key, $this->visible)) {
                continue;
            }

            if ($value instanceof Model) {
                $relations[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $relations[$key] = array_map(function ($item) {
                    return $item instanceof Model ? $item->toArray() : $item;
                }, $value);
            } else {
                $relations[$key] = $value;
            }
        }

        return $relations;
    }

    /**
     * Convert model to JSON
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Convert model to string (JSON)
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Specify data for JSON serialization
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // ========================================
    // Appends
    // ========================================

    /**
     * Get the appends array
     */
    public function getAppends(): array
    {
        return $this->appends;
    }

    /**
     * Set the appends array
     */
    public function setAppends(array $appends): self
    {
        $this->appends = $appends;
        return $this;
    }

    /**
     * Append attributes to the model's array form
     */
    public function append(array|string $attributes): self
    {
        $attributes = is_array($attributes) ? $attributes : [$attributes];
        $this->appends = array_unique(array_merge($this->appends, $attributes));
        return $this;
    }

    // ========================================
    // Model Replication
    // ========================================

    /**
     * Clone the model into a new, non-existing instance
     */
    public function replicate(array $except = []): static
    {
        $defaults = [
            $this->primaryKey,
            'CreatedDate',
            'UpdatedDate',
        ];

        $except = array_unique(array_merge($defaults, $except));

        $attributes = array_diff_key($this->attributes, array_flip($except));

        $instance = new static($attributes);
        $instance->exists = false;

        return $instance;
    }

    /**
     * Clone the model and save it to the database
     */
    public function replicateAndSave(array $except = []): static
    {
        $clone = $this->replicate($except);
        $clone->save();
        return $clone;
    }

    // ========================================
    // Global Scopes
    // ========================================

    /**
     * Register a global scope
     */
    public static function addGlobalScope(string $name, callable $scope): void
    {
        static::$globalScopes[static::class][$name] = $scope;
    }

    /**
     * Remove a global scope
     */
    public static function removeGlobalScope(string $name): void
    {
        unset(static::$globalScopes[static::class][$name]);
    }

    /**
     * Get all global scopes for this model
     */
    public static function getGlobalScopes(): array
    {
        return static::$globalScopes[static::class] ?? [];
    }

    /**
     * Clear all global scopes for this model
     */
    public static function clearGlobalScopes(): void
    {
        static::$globalScopes[static::class] = [];
    }

    /**
     * Apply global scopes to query builder
     */
    protected static function applyGlobalScopes(QueryBuilder $query): void
    {
        foreach (static::getGlobalScopes() as $scope) {
            $scope($query);
        }
    }

    // ========================================
    // Fresh / Refresh
    // ========================================

    /**
     * Reload a fresh model instance from the database
     */
    public function fresh(): ?static
    {
        if (!$this->exists) {
            return null;
        }

        return static::find($this->getKey());
    }

    /**
     * Reload the current model instance with fresh attributes
     */
    public function refresh(): self
    {
        if (!$this->exists) {
            return $this;
        }

        $fresh = static::find($this->getKey());

        if ($fresh) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->original;
            $this->relations = [];
        }

        return $this;
    }

    // ========================================
    // Utility Methods
    // ========================================

    /**
     * Determine if two models have the same ID and belong to the same table
     */
    public function is(?Model $model): bool
    {
        return $model !== null
            && $this->getKey() === $model->getKey()
            && static::getTable() === $model::getTable();
    }

    /**
     * Determine if two models are not the same
     */
    public function isNot(?Model $model): bool
    {
        return !$this->is($model);
    }

    /**
     * Get the model's original attribute values
     */
    public function getOriginal(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->original;
        }

        return $this->original[$key] ?? null;
    }

    /**
     * Get only the specified attributes
     */
    public function only(array|string $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->getAttribute($key);
        }
        
        return $results;
    }

    /**
     * Get all attributes except the specified ones
     */
    public function except(array|string $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        
        return array_diff_key($this->attributes, array_flip($keys));
    }
}
