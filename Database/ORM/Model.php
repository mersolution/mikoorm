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

use Closure;
use Miko\Database\ConnectionInterface;
use Miko\Database\ConnectionResolver;
use Miko\Database\DatabaseInterface;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\ORM\Events\ModelEvent;
use Miko\Database\ORM\Relations\BelongsTo;
use Miko\Database\ORM\Relations\BelongsToMany;
use Miko\Database\ORM\Relations\HasMany;
use Miko\Database\ORM\Relations\HasOne;
use Miko\Database\ORM\Relations\Relation;
use Miko\Database\ORM\Traits\HasEvents;
use Miko\Database\ORM\Traits\HasMigration;
use Miko\Database\Query\Grammar;

/**
 * ORM Model base class
 *
 * class User extends Model
 * {
 *     use HasTimestamps;            // optional: CreatedDate / UpdatedDate
 *     protected static string $table = 'users';
 *     protected array $fillable = ['Name', 'Email'];
 * }
 */
abstract class Model implements \JsonSerializable
{
    use HasEvents, HasMigration;

    /**
     * Table name (optional: #[Table('x')] or "tbl{ClassName}s" is used when not set)
     */
    protected static string $table;

    /**
     * Primary key column
     */
    protected string $primaryKey = 'Id';

    /**
     * Auto-increment primary key (lastInsertId is read after insert)
     */
    protected bool $incrementing = true;

    /**
     * Primary key type: 'int' or 'string'
     */
    protected string $keyType = 'int';

    /**
     * Legacy database instance (Model::setDatabase)
     */
    protected static ?DatabaseInterface $database = null;

    /**
     * Per model class connection (or Closure resolving one)
     *
     * @var array<string, ConnectionInterface|Closure>
     */
    protected static array $connectionCache = [];

    protected array $attributes = [];
    protected array $original = [];

    /**
     * True when the model is stored in the database
     */
    public bool $exists = false;

    /**
     * True right after an INSERT
     */
    public bool $wasRecentlyCreated = false;

    protected array $relations = [];

    /**
     * Relations always eager loaded with query()
     */
    protected array $with = [];

    /**
     * Global scopes per model class
     */
    protected static array $globalScopes = [];

    /**
     * Attribute casts: int, integer, float, double, decimal, string, bool, boolean,
     * array, json, object, date, datetime, timestamp
     */
    protected array $casts = [];

    protected array $hidden = [];
    protected array $visible = [];

    /**
     * Mass assignable attributes. When empty, every attribute except the
     * primary key and $guarded ones can be mass assigned.
     */
    protected array $fillable = [];

    /**
     * Attributes that are never mass assignable (['*'] = none)
     */
    protected array $guarded = [];

    protected array $appends = [];

    protected static array $booted = [];

    private static array $tableCache = [];
    private static array $relationMethodCache = [];
    private static ?string $libraryRoot = null;

    public function __construct(array $attributes = [])
    {
        $this->bootIfNotBooted();
        $this->fill($attributes);
    }

    // ========================================
    // Boot
    // ========================================

    protected function bootIfNotBooted(): void
    {
        if (!isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;
            static::bootTraits();
            static::boot();
        }
    }

    protected static function bootTraits(): void
    {
        $class = static::class;
        $traits = [];

        do {
            $traits = array_merge($traits, class_uses($class) ?: []);
        } while ($class = get_parent_class($class));

        foreach (array_unique($traits) as $trait) {
            $method = 'boot' . (new \ReflectionClass($trait))->getShortName();
            if (method_exists(static::class, $method)) {
                forward_static_call([static::class, $method]);
            }
        }
    }

    /**
     * Override for custom boot logic (register scopes, events...)
     */
    protected static function boot(): void
    {
    }

    // ========================================
    // Connection
    // ========================================

    /**
     * Legacy: a DatabaseInterface used by all models
     */
    public static function setDatabase(DatabaseInterface $database): void
    {
        static::$database = $database;
    }

    /**
     * Connection used by this model class
     */
    public function getConnection(): ConnectionInterface
    {
        return static::resolveConnection();
    }

    public static function resolveConnection(): ConnectionInterface
    {
        $class = static::class;
        $connection = static::$connectionCache[$class] ?? null;

        if ($connection instanceof Closure) {
            $connection = $connection();
            static::$connectionCache[$class] = $connection;
        }

        if ($connection instanceof ConnectionInterface) {
            return $connection;
        }

        if (static::$database !== null) {
            return static::$database->connection();
        }

        return ConnectionResolver::default();
    }

    /**
     * Use a specific connection (or a Closure returning one) for this model class
     */
    public static function setConnection(ConnectionInterface|Closure $connection): void
    {
        static::$connectionCache[static::class] = $connection;
    }

    public static function clearConnectionCache(): void
    {
        unset(static::$connectionCache[static::class]);
    }

    // ========================================
    // Table / keys
    // ========================================

    public static function getTable(): string
    {
        $class = static::class;
        if (isset(self::$tableCache[$class])) {
            return self::$tableCache[$class];
        }

        $property = new \ReflectionProperty($class, 'table');
        $name = $property->isInitialized() ? $property->getValue() : null;

        if (!is_string($name) || $name === '') {
            $attributes = (new \ReflectionClass($class))->getAttributes(Table::class);
            if ($attributes !== []) {
                $name = $attributes[0]->newInstance()->name;
            } else {
                $name = 'tbl' . (new \ReflectionClass($class))->getShortName() . 's';
            }
        }

        Grammar::assertTable($name);
        return self::$tableCache[$class] = $name;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getQualifiedKeyName(): string
    {
        return static::getTable() . '.' . $this->primaryKey;
    }

    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    public function getIncrementing(): bool
    {
        return $this->incrementing;
    }

    /**
     * Default foreign key name pointing to this model: User + Id -> UserId (user_id for "id")
     */
    public function getForeignKeyName(): string
    {
        $short = (new \ReflectionClass($this))->getShortName();

        if ($this->primaryKey === 'id') {
            return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short)) . '_id';
        }

        return $short . ucfirst($this->primaryKey);
    }

    // ========================================
    // Query entry points
    // ========================================

    public static function query(): QueryBuilder
    {
        $model = new static();
        $query = new QueryBuilder($model);

        if ($model->with !== []) {
            $query->with($model->with);
        }

        return $query;
    }

    public function newQuery(): QueryBuilder
    {
        return static::query();
    }

    public static function where(mixed $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::query()->where(...func_get_args());
    }

    public static function find(mixed $id): static|array|null
    {
        return static::query()->find($id);
    }

    public static function findMany(array $ids): array
    {
        return static::query()->findMany($ids);
    }

    public static function findOrFail(mixed $id): static
    {
        return static::query()->findOrFail($id);
    }

    /**
     * @return static[]
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * all() as a Future; other *Async() methods are forwarded to the query (User::countAsync(), User::findAsync(5))
     *
     * @return \Miko\Core\Async\Future<static[]>
     */
    public static function allAsync(): \Miko\Core\Async\Future
    {
        return static::query()->getAsync();
    }

    public static function first(): ?static
    {
        return static::query()->first();
    }

    public static function firstOrFail(): static
    {
        return static::query()->firstOrFail();
    }

    public static function single(): ?static
    {
        return static::query()->single();
    }

    public static function singleOrFail(): static
    {
        return static::query()->singleOrFail();
    }

    public static function exists(): bool
    {
        return static::query()->exists();
    }

    public static function any(): bool
    {
        return static::query()->exists();
    }

    public static function doesntExist(): bool
    {
        return static::query()->doesntExist();
    }

    public static function count(): int
    {
        return static::query()->count();
    }

    public static function sum(string $column): int|float
    {
        return static::query()->sum($column);
    }

    public static function avg(string $column): int|float
    {
        return static::query()->avg($column);
    }

    public static function min(string $column): mixed
    {
        return static::query()->min($column);
    }

    public static function max(string $column): mixed
    {
        return static::query()->max($column);
    }

    public static function pluck(string $column, ?string $key = null): array
    {
        return static::query()->pluck($column, $key);
    }

    public static function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        return static::query()->orderBy($column, $direction);
    }

    public static function limit(int $limit): QueryBuilder
    {
        return static::query()->limit($limit);
    }

    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        return static::query()->where($attributes)->first() ?? static::create(array_merge($attributes, $values));
    }

    public static function firstOrNew(array $attributes, array $values = []): static
    {
        return static::query()->where($attributes)->first() ?? new static(array_merge($attributes, $values));
    }

    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $model = static::query()->where($attributes)->first();

        if ($model !== null) {
            $model->fill($values)->save();
            return $model;
        }

        return static::create(array_merge($attributes, $values));
    }

    /**
     * Create and save (respects $fillable)
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Create and save ignoring $fillable / $guarded
     */
    public static function forceCreate(array $attributes): static
    {
        $model = (new static())->forceFill($attributes);
        $model->save();
        return $model;
    }

    public static function createMany(array $records): array
    {
        return array_map(fn(array $attributes) => static::create($attributes), $records);
    }

    /**
     * Bulk insert (no model events, chunked to the driver parameter limit)
     */
    public static function insert(array $records): bool
    {
        BulkOperations::insert(static::class, $records);
        return true;
    }

    /**
     * Delete models by primary key (fires events, respects soft deletes)
     */
    public static function destroy(mixed $ids): int
    {
        $ids = is_array($ids) ? $ids : func_get_args();
        if ($ids === []) {
            return 0;
        }

        $count = 0;
        foreach (static::query()->findMany($ids) as $model) {
            if ($model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Register an observer for this model
     */
    public static function observe(string|Observer $observer): void
    {
        ObserverManager::register(static::class, $observer);
    }

    /**
     * Local scopes and query builder methods: User::active(), User::whereIn(...)
     */
    public static function __callStatic(string $method, array $parameters)
    {
        return (new static())->$method(...$parameters);
    }

    /**
     * Mass write methods are only allowed on an explicit query (User::where(...)->delete())
     */
    private const QUERY_ONLY_METHODS = ['update', 'delete', 'forceDelete', 'restore', 'increment', 'decrement', 'insert'];

    public function __call(string $method, array $parameters)
    {
        if ($this->hasLocalScope($method)) {
            $query = static::query();
            $this->callLocalScope($method, $query, $parameters);
            return $query;
        }

        if (in_array($method, self::QUERY_ONLY_METHODS, true)) {
            throw new \BadMethodCallException(sprintf(
                '%s::%s() runs on a query. Use %s::query()->%s(...) or %s::where(...)->%s(...).',
                static::class, $method, static::class, $method, static::class, $method
            ));
        }

        $query = static::query();
        if (!method_exists($query, $method)) {
            throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s()', static::class, $method));
        }

        return $query->$method(...$parameters);
    }

    public function hasLocalScope(string $name): bool
    {
        return method_exists($this, 'scope' . ucfirst($name));
    }

    public function callLocalScope(string $name, QueryBuilder $query, array $parameters = []): mixed
    {
        return $this->{'scope' . ucfirst($name)}($query, ...$parameters);
    }

    // ========================================
    // Persistence
    // ========================================

    public function save(): bool
    {
        if ($this->fireModelEvent(ModelEvent::SAVING) === false) {
            return false;
        }

        $saved = $this->exists ? $this->performUpdate() : $this->performInsert();

        if ($saved) {
            $this->fireModelEvent(ModelEvent::SAVED);
            $this->syncOriginal();
        }

        return $saved;
    }

    /**
     * Fill (respects $fillable) and save
     */
    public function update(array $attributes = []): bool
    {
        if (!$this->exists) {
            return false;
        }

        return $this->fill($attributes)->save();
    }

    /**
     * Update the UpdatedDate column
     */
    public function touch(): bool
    {
        if (!$this->usesTimestamps() || !$this->exists) {
            return false;
        }

        $this->setUpdatedAt($this->freshTimestampString());
        return $this->save();
    }

    protected function performInsert(): bool
    {
        if ($this->fireModelEvent(ModelEvent::CREATING) === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $connection = $this->getConnection();
        $grammar = $connection->getGrammar();
        $table = $grammar->wrapTable(static::getTable());
        $attributes = $this->getAttributesForDatabase();

        $autoKey = $this->incrementing && ($this->getKey() === null || $this->getKey() === '');
        if ($autoKey) {
            unset($attributes[$this->primaryKey]);
        }

        if ($attributes === []) {
            $sql = $grammar->insertDefaultValues($table);
            $values = [];
        } else {
            $columns = array_map(fn($c) => $grammar->wrap(Grammar::assertReference((string) $c)), array_keys($attributes));
            $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($attributes), '?')) . ')';
            $values = array_values($attributes);
        }

        if ($autoKey && $connection->getDriverName() === 'pgsql') {
            $row = $connection->execute($sql . ' RETURNING ' . $grammar->wrap($this->primaryKey), $values)->first();
            $id = $row === null ? null : ($row[$this->primaryKey] ?? reset($row));
        } else {
            $connection->execute($sql, $values);
            $id = $autoKey ? $connection->lastInsertId() : null;
        }

        if ($autoKey) {
            $this->attributes[$this->primaryKey] = ($this->keyType === 'int' && is_numeric($id)) ? (int) $id : $id;
        }

        $this->exists = true;
        $this->wasRecentlyCreated = true;

        $this->fireModelEvent(ModelEvent::CREATED);

        return true;
    }

    protected function performUpdate(): bool
    {
        if ($this->fireModelEvent(ModelEvent::UPDATING) === false) {
            return false;
        }

        $dirty = $this->getDirty();
        if ($dirty === []) {
            return true;
        }

        if ($this->usesTimestamps() && !array_key_exists($this->getUpdatedAtColumn(), $dirty)) {
            $this->updateTimestamps();
            $dirty = $this->getDirty();
        }

        $connection = $this->getConnection();
        $grammar = $connection->getGrammar();
        $sets = [];
        $values = [];

        foreach ($dirty as $column => $value) {
            $sets[] = $grammar->wrap(Grammar::assertReference((string) $column)) . ' = ?';
            $values[] = $this->toDatabaseValue((string) $column, $value);
        }

        $values[] = $this->getOriginalKey();

        $connection->execute(
            'UPDATE ' . $grammar->wrapTable(static::getTable()) . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . $grammar->wrap($this->primaryKey) . ' = ?',
            $values
        );

        $this->fireModelEvent(ModelEvent::UPDATED);

        return true;
    }

    /**
     * Delete the model (soft delete when the model uses SoftDeletes)
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if ($this->fireModelEvent(ModelEvent::DELETING) === false) {
            return false;
        }

        $this->performDeleteOnModel();

        $this->fireModelEvent(ModelEvent::DELETED);

        return true;
    }

    /**
     * Physically delete (SoftDeletes overrides this)
     */
    public function forceDelete(): bool
    {
        return $this->delete();
    }

    protected function performDeleteOnModel(): void
    {
        $grammar = $this->getConnection()->getGrammar();

        $this->getConnection()->execute(
            'DELETE FROM ' . $grammar->wrapTable(static::getTable()) . ' WHERE ' . $grammar->wrap($this->primaryKey) . ' = ?',
            [$this->getOriginalKey()]
        );

        $this->exists = false;
    }

    /**
     * Atomic increment of one column (UPDATE ... SET col = col + ?)
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): bool
    {
        if (!$this->exists) {
            return false;
        }

        $connection = $this->getConnection();
        $grammar = $connection->getGrammar();
        $wrapped = $grammar->wrap(Grammar::assertReference($column));
        $sets = ["{$wrapped} = {$wrapped} + ?"];
        $values = [$amount];

        if ($this->usesTimestamps() && !array_key_exists($this->getUpdatedAtColumn(), $extra)) {
            $extra[$this->getUpdatedAtColumn()] = $this->freshTimestampString();
        }

        foreach ($extra as $key => $value) {
            $sets[] = $grammar->wrap(Grammar::assertReference((string) $key)) . ' = ?';
            $values[] = $this->toDatabaseValue((string) $key, $value);
        }

        $values[] = $this->getOriginalKey();

        $connection->execute(
            'UPDATE ' . $grammar->wrapTable(static::getTable()) . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . $grammar->wrap($this->primaryKey) . ' = ?',
            $values
        );

        $current = $this->attributes[$column] ?? 0;
        $this->attributes[$column] = (is_numeric($current) ? $current + 0 : 0) + $amount;
        $this->original[$column] = $this->attributes[$column];

        foreach ($extra as $key => $value) {
            $this->attributes[$key] = $value;
            $this->original[$key] = $value;
        }

        return true;
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): bool
    {
        return $this->increment($column, -$amount, $extra);
    }

    protected function getOriginalKey(): mixed
    {
        return $this->original[$this->primaryKey] ?? $this->getKey();
    }

    // ========================================
    // Hydration
    // ========================================

    /**
     * Model from a database row
     */
    public static function hydrate(array $row): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->original = $row;
        $model->exists = true;
        $model->fireModelEvent(ModelEvent::RETRIEVED);

        return $model;
    }

    /**
     * @return static[]
     */
    public static function hydrateMany(array $rows): array
    {
        $models = [];
        foreach ($rows as $row) {
            $models[] = static::hydrate($row);
        }
        return $models;
    }

    public function setRawAttributes(array $attributes, bool $sync = false): static
    {
        $this->attributes = $attributes;
        if ($sync) {
            $this->syncOriginal();
        }
        return $this;
    }

    public function syncOriginal(): static
    {
        $this->original = $this->attributes;
        return $this;
    }

    // ========================================
    // Dirty checking
    // ========================================

    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || !$this->originalIsEquivalent($key, $value, $this->original[$key])) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();
        return $key === null ? $dirty !== [] : array_key_exists($key, $dirty);
    }

    public function isClean(?string $key = null): bool
    {
        return !$this->isDirty($key);
    }

    protected function originalIsEquivalent(string $key, mixed $current, mixed $original): bool
    {
        if ($current === $original) {
            return true;
        }

        if ($current === null || $original === null) {
            return false;
        }

        if (is_bool($current) || is_bool($original)) {
            return (int) (bool) $current === (int) (bool) $original
                && (is_bool($current) ? in_array((string) $original, ['0', '1', ''], true) : in_array((string) $current, ['0', '1', ''], true));
        }

        if (is_numeric($current) && is_numeric($original)) {
            return (string) $current === (string) $original;
        }

        if (is_array($current) && is_string($original)) {
            return json_decode($original, true) === $current;
        }

        return false;
    }

    // ========================================
    // Attribute access
    // ========================================

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->getAttribute($key) !== null;
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    /**
     * Attribute (accessor + cast applied), loaded relation or lazy relation
     */
    public function getAttribute(string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        $accessor = $this->accessorMethod('get', $key);
        if ($accessor !== null) {
            return $this->$accessor($this->attributes[$key] ?? null);
        }

        if (array_key_exists($key, $this->attributes)) {
            return $this->castAttribute($key, $this->attributes[$key]);
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        if ($this->isRelationMethod($key)) {
            return $this->relations[$key] = $this->getRelationInstance($key)->getResults();
        }

        return null;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $mutator = $this->accessorMethod('set', $key);
        if ($mutator !== null) {
            $this->$mutator($value);
            return;
        }

        $this->attributes[$key] = $value;
    }

    private static array $accessorCache = [];

    /**
     * get{Name}Attribute / set{Name}Attribute declared in your model (library methods excluded)
     */
    protected function accessorMethod(string $prefix, string $key): ?string
    {
        $class = static::class;
        $cacheKey = $prefix . ':' . $key;

        if (!array_key_exists($cacheKey, self::$accessorCache[$class] ?? [])) {
            $method = $prefix . $this->studly($key) . 'Attribute';
            $found = null;

            if ($key !== '' && method_exists($this, $method)) {
                $file = (new \ReflectionMethod($this, $method))->getFileName();
                if ($file === false || !self::isLibraryFile($file)) {
                    $found = $method;
                }
            }

            self::$accessorCache[$class][$cacheKey] = $found;
        }

        return self::$accessorCache[$class][$cacheKey];
    }

    /**
     * Raw stored value (no accessor, no cast)
     */
    public function getAttributeValue(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Set a raw value (no mutator)
     */
    public function setAttributeValue(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Raw attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Attributes converted for the database (casts, JSON, dates, enums)
     */
    public function getAttributesForDatabase(): array
    {
        $result = [];
        foreach ($this->attributes as $key => $value) {
            $result[$key] = $this->toDatabaseValue((string) $key, $value);
        }
        return $result;
    }

    /**
     * Remove attributes with a prefix and return them (prefix stripped)
     */
    public function pullAttributes(string $prefix): array
    {
        $pulled = [];
        foreach (array_keys($this->attributes) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                $pulled[substr((string) $key, strlen($prefix))] = $this->attributes[$key];
                unset($this->attributes[$key], $this->original[$key]);
            }
        }
        return $pulled;
    }

    protected function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    // ========================================
    // Relations
    // ========================================

    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null, ?string $relation = null): BelongsTo
    {
        $relation ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'belongsTo';
        return new BelongsTo($this, $related, $foreignKey, $ownerKey, $relation);
    }

    protected function belongsToMany(
        string $related,
        string $pivotTable,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): BelongsToMany {
        return new BelongsToMany($this, $related, $pivotTable, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey);
    }

    /**
     * Relation object from a relation method (throws when the method is not a relation)
     */
    public function getRelationInstance(string $name): Relation
    {
        if (!$this->isRelationMethod($name)) {
            throw new \BadMethodCallException(sprintf('Call to undefined relationship [%s] on model [%s].', $name, static::class));
        }

        $relation = $this->$name();
        if (!$relation instanceof Relation) {
            throw new \LogicException(sprintf('%s::%s() must return a relationship instance.', static::class, $name));
        }

        return $relation;
    }

    /**
     * Only methods declared in your model (not in the library) without
     * required parameters can be loaded as relations by property access.
     */
    protected function isRelationMethod(string $key): bool
    {
        $class = static::class;
        if (isset(self::$relationMethodCache[$class][$key])) {
            return self::$relationMethodCache[$class][$key];
        }

        $result = false;
        if (method_exists($this, $key)) {
            $method = new \ReflectionMethod($this, $key);
            $file = $method->getFileName();

            if (!$method->isStatic()
                && $method->getNumberOfRequiredParameters() === 0
                && !($file !== false && self::isLibraryFile($file))
            ) {
                $type = $method->getReturnType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $result = is_a($type->getName(), Relation::class, true);
                } else {
                    $result = $type === null;
                }
            }
        }

        return self::$relationMethodCache[$class][$key] = $result;
    }

    /**
     * Methods declared in the library itself (Database/, Core/) are never relations or accessors
     */
    private static function isLibraryFile(string $file): bool
    {
        if (self::$libraryRoot === null) {
            self::$libraryRoot = self::normalizePath(dirname(__DIR__, 2)) . '/';
        }

        $path = self::normalizePath($file);
        $root = self::$libraryRoot;

        return str_starts_with($path, $root . (PHP_OS_FAMILY === 'Windows' ? 'database/' : 'Database/'))
            || str_starts_with($path, $root . (PHP_OS_FAMILY === 'Windows' ? 'core/' : 'Core/'));
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    public function setRelation(string $relation, mixed $value): static
    {
        $this->relations[$relation] = $value;
        return $this;
    }

    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation] ?? null;
    }

    public function getRelations(): array
    {
        return $this->relations;
    }

    public function relationLoaded(string $relation): bool
    {
        return array_key_exists($relation, $this->relations);
    }

    public function unsetRelation(string $relation): static
    {
        unset($this->relations[$relation]);
        return $this;
    }

    /**
     * Eager load relations into this model: $user->load('posts.comments')
     */
    public function load(string|array ...$relations): static
    {
        $list = count($relations) === 1 && is_array($relations[0]) ? $relations[0] : $relations;
        static::query()->with($list)->eagerLoadRelations([$this]);
        return $this;
    }

    /**
     * Load only relations that are not loaded yet
     */
    public function loadMissing(string ...$relations): static
    {
        $missing = array_values(array_filter($relations, fn($r) => !$this->relationLoaded(explode('.', $r)[0])));
        return $missing === [] ? $this : $this->load($missing);
    }

    // ========================================
    // Casting
    // ========================================

    protected function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null || !isset($this->casts[$key])) {
            return $value;
        }

        [$type, $argument] = array_pad(explode(':', strtolower($this->casts[$key]), 2), 2, null);

        switch ($type) {
            case 'int':
            case 'integer':
                return (int) $value;

            case 'float':
            case 'double':
            case 'real':
                return (float) $value;

            case 'decimal':
                return number_format((float) $value, (int) ($argument ?? 2), '.', '');

            case 'string':
                return (string) $value;

            case 'bool':
            case 'boolean':
                return is_string($value) ? !in_array(strtolower($value), ['0', '', 'false', 'f', 'no', 'off'], true) : (bool) $value;

            case 'array':
            case 'json':
                if (is_array($value)) {
                    return $value;
                }
                return is_string($value) ? (json_decode($value, true) ?? []) : (array) $value;

            case 'object':
                if (is_object($value)) {
                    return $value;
                }
                return is_string($value) ? json_decode($value) : (object) $value;

            case 'date':
                $time = $value instanceof \DateTimeInterface ? $value->getTimestamp() : strtotime((string) $value);
                return $time === false ? $value : date('Y-m-d', $time);

            case 'datetime':
            case 'timestamp':
                $time = $value instanceof \DateTimeInterface ? $value->getTimestamp() : strtotime((string) $value);
                return $time === false ? $value : date('Y-m-d H:i:s', $time);

            default:
                return $value;
        }
    }

    /**
     * Value converted for the database
     */
    public function toDatabaseValue(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $cast = isset($this->casts[$key]) ? strtolower(explode(':', $this->casts[$key])[0]) : null;

        switch ($cast) {
            case 'array':
            case 'json':
            case 'object':
                if (is_string($value)) {
                    // Already JSON text (e.g. set by JsonColumnTrait)
                    json_decode($value);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $value;
                    }
                }
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            case 'bool':
            case 'boolean':
                return $this->castAttribute($key, $value) ? 1 : 0;

            case 'date':
            case 'datetime':
            case 'timestamp':
                if ($value instanceof \DateTimeInterface) {
                    return $value->format($cast === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
                }
                return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value) || $value instanceof \JsonSerializable || $value instanceof \stdClass) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    public function getCastedAttribute(string $key): mixed
    {
        return $this->castAttribute($key, $this->attributes[$key] ?? null);
    }

    public function hasCast(string $key): bool
    {
        return isset($this->casts[$key]);
    }

    public function getCasts(): array
    {
        return $this->casts;
    }

    // ========================================
    // Timestamps / soft deletes
    // ========================================

    /**
     * True when the model uses HasTimestamps and $timestamps is on
     */
    public function usesTimestamps(): bool
    {
        return method_exists($this, 'updateTimestamps') && property_exists($this, 'timestamps') && $this->timestamps;
    }

    public function usesSoftDeletes(): bool
    {
        return method_exists($this, 'getDeletedAtColumn');
    }

    public function freshTimestampString(): string
    {
        return date('Y-m-d H:i:s');
    }

    // ========================================
    // Hidden / visible
    // ========================================

    public function setHidden(array $hidden): static
    {
        $this->hidden = $hidden;
        return $this;
    }

    public function getHidden(): array
    {
        return $this->hidden;
    }

    public function setVisible(array $visible): static
    {
        $this->visible = $visible;
        return $this;
    }

    public function getVisible(): array
    {
        return $this->visible;
    }

    public function makeVisible(array|string $attributes): static
    {
        $attributes = (array) $attributes;
        $this->hidden = array_values(array_diff($this->hidden, $attributes));

        if ($this->visible !== []) {
            $this->visible = array_values(array_unique(array_merge($this->visible, $attributes)));
        }

        return $this;
    }

    public function makeHidden(array|string $attributes): static
    {
        $this->hidden = array_values(array_unique(array_merge($this->hidden, (array) $attributes)));
        return $this;
    }

    // ========================================
    // Mass assignment
    // ========================================

    public function getFillable(): array
    {
        return $this->fillable;
    }

    public function getGuarded(): array
    {
        return $this->guarded;
    }

    public function isFillable(string $key): bool
    {
        if (in_array($key, $this->fillable, true)) {
            return true;
        }

        if ($this->isGuarded($key)) {
            return false;
        }

        return $this->fillable === [] && Grammar::isIdentifier($key);
    }

    public function isGuarded(string $key): bool
    {
        if (in_array($key, $this->fillable, true)) {
            return false;
        }

        return in_array('*', $this->guarded, true)
            || in_array($key, $this->guarded, true)
            || $key === $this->primaryKey;
    }

    /**
     * Mass assign allowed attributes (others are ignored)
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (is_string($key) && $this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * Mass assign ignoring $fillable / $guarded
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (is_string($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    // ========================================
    // Serialization
    // ========================================

    public function toArray(): array
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    protected function attributesToArray(): array
    {
        $attributes = [];

        foreach (array_keys($this->attributes) as $key) {
            $attributes[$key] = $this->getAttribute((string) $key);
        }

        foreach ($this->appends as $key) {
            $accessor = $this->accessorMethod('get', $key);
            if ($accessor !== null) {
                $attributes[$key] = $this->$accessor($this->attributes[$key] ?? null);
            }
        }

        if ($this->visible !== []) {
            $attributes = array_intersect_key($attributes, array_flip($this->visible));
        }

        if ($this->hidden !== []) {
            $attributes = array_diff_key($attributes, array_flip($this->hidden));
        }

        return $attributes;
    }

    protected function relationsToArray(): array
    {
        $relations = [];

        foreach ($this->relations as $key => $value) {
            if (in_array($key, $this->hidden, true) || ($this->visible !== [] && !in_array($key, $this->visible, true))) {
                continue;
            }

            if ($value instanceof self) {
                $relations[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $relations[$key] = array_map(fn($item) => $item instanceof self ? $item->toArray() : $item, $value);
            } else {
                $relations[$key] = $value;
            }
        }

        return $relations;
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getAppends(): array
    {
        return $this->appends;
    }

    public function setAppends(array $appends): static
    {
        $this->appends = $appends;
        return $this;
    }

    public function append(array|string $attributes): static
    {
        $this->appends = array_values(array_unique(array_merge($this->appends, (array) $attributes)));
        return $this;
    }

    // ========================================
    // Replication / refresh
    // ========================================

    public function replicate(array $except = []): static
    {
        $defaults = [$this->primaryKey, 'CreatedDate', 'UpdatedDate'];
        if (method_exists($this, 'getCreatedAtColumn')) {
            $defaults[] = $this->getCreatedAtColumn();
            $defaults[] = $this->getUpdatedAtColumn();
        }

        $attributes = array_diff_key($this->attributes, array_flip(array_merge($defaults, $except)));

        return (new static())->forceFill($attributes);
    }

    public function replicateAndSave(array $except = []): static
    {
        $clone = $this->replicate($except);
        $clone->save();
        return $clone;
    }

    /**
     * New instance of this record from the database (ignores global scopes)
     */
    public function fresh(): ?static
    {
        if (!$this->exists) {
            return null;
        }

        return static::query()->withoutGlobalScopes()->find($this->getKey());
    }

    /**
     * Reload attributes from the database
     */
    public function refresh(): static
    {
        $fresh = $this->fresh();

        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->original;
            $this->relations = [];
        }

        return $this;
    }

    // ========================================
    // Global scopes
    // ========================================

    public static function addGlobalScope(string $name, callable $scope): void
    {
        static::$globalScopes[static::class][$name] = $scope;
    }

    public static function removeGlobalScope(string $name): void
    {
        unset(static::$globalScopes[static::class][$name]);
    }

    public static function getGlobalScopes(): array
    {
        return static::$globalScopes[static::class] ?? [];
    }

    public static function clearGlobalScopes(): void
    {
        static::$globalScopes[static::class] = [];
    }

    // ========================================
    // Utility
    // ========================================

    public function is(?Model $model): bool
    {
        return $model !== null
            && $this->getKey() !== null
            && (string) $this->getKey() === (string) $model->getKey()
            && static::getTable() === $model::getTable();
    }

    public function isNot(?Model $model): bool
    {
        return !$this->is($model);
    }

    public function getOriginal(?string $key = null): mixed
    {
        return $key === null ? $this->original : ($this->original[$key] ?? null);
    }

    public function only(array|string $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getAttribute($key);
        }
        return $result;
    }

    public function except(array|string $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        return array_diff_key($this->attributes, array_flip($keys));
    }
}
