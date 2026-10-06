<?php
/**
 * MikoORM test suite
 *
 *   php tests/run.php                 (SQLite in memory)
 *
 * Another database (tables are created with the miko_t_ prefix and dropped at the end):
 *   MIKO_TEST_DRIVER=mysql MIKO_TEST_HOST=127.0.0.1 MIKO_TEST_PORT=3306
 *   MIKO_TEST_DATABASE=miko_test MIKO_TEST_USERNAME=root MIKO_TEST_PASSWORD=secret php tests/run.php
 */

namespace MikoTests;

use Miko\Core\Async\Async;
use Miko\Core\Async\Deferred;
use Miko\Core\Async\Future;
use Miko\Core\Config;
use Miko\Core\Validation\Validator;
use Miko\Database\Async\AsyncConnection;
use Miko\Database\Async\AsyncTimeoutException;
use Miko\Database\Async\MyWireDriver;
use Miko\Database\Async\MyWireLink;
use Miko\Database\Async\PgWireDriver;
use Miko\Database\Async\PgWireLink;
use Miko\Database\Async\SqlBinder;
use Miko\Database\Async\TdsDriver;
use Miko\Database\Async\TdsLink;
use Miko\Database\Bulk\BulkInsert;
use Miko\Database\Bulk\BulkUpdate;
use Miko\Database\Connection;
use Miko\Database\ConnectionFactory;
use Miko\Database\ConnectionResolver;
use Miko\Database\DatabaseManager;
use Miko\Database\DB;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\Exceptions\QueryException;
use Miko\Database\Factories\Factory;
use Miko\Database\Log\QueryLogger;
use Miko\Database\Migration\Migration;
use Miko\Database\Migration\Migrator;
use Miko\Database\Migration\Schema;
use Miko\Database\Migration\TableBuilder;
use Miko\Database\Monitor\HealthCheck;
use Miko\Database\ORM\BulkOperations;
use Miko\Database\ORM\ConnectionPool;
use Miko\Database\ORM\DbContext;
use Miko\Database\ORM\Email;
use Miko\Database\ORM\Events\ModelEvent;
use Miko\Database\ORM\MaxLength;
use Miko\Database\ORM\MikoSet;
use Miko\Database\ORM\Model;
use Miko\Database\ORM\ModelMetadata;
use Miko\Database\ORM\ModelValidator;
use Miko\Database\ORM\Observer;
use Miko\Database\ORM\ObserverManager;
use Miko\Database\ORM\Relations\BelongsTo;
use Miko\Database\ORM\Relations\BelongsToMany;
use Miko\Database\ORM\Relations\HasMany;
use Miko\Database\ORM\Relations\HasOne;
use Miko\Database\ORM\Required;
use Miko\Database\ORM\Traits\HasTimestamps;
use Miko\Database\ORM\Traits\SoftDeletes;
use Miko\Database\ORM\Transaction;
use Miko\Database\ORM\UniqueValue;
use Miko\Database\Pagination\Paginator;
use Miko\Database\Query\FluentQueryBuilder;
use Miko\Database\Query\Grammar;
use Miko\Database\Query\QueryBuilder as TableQuery;
use Miko\Database\Query\RawQuery;
use Miko\Database\Seeders\Seeder;
use Miko\Library\Crypto;
use Miko\Library\Security;
use Miko\Log\Logger;
use Miko\Security\FormCrypt;

require __DIR__ . '/../autoload.php';

// ============================================================================
// Mini test framework
// ============================================================================

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failed = [];

    public static function group(string $name): void
    {
        echo "\n{$name}\n";
    }

    public static function test(string $name, callable $fn): void
    {
        try {
            $fn();
            self::$pass++;
            echo "  ok    {$name}\n";
        } catch (\Throwable $e) {
            self::$fail++;
            self::$failed[] = $name;
            echo "  FAIL  {$name}\n        " . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }
}

function same(mixed $expected, mixed $actual, string $label = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(($label !== '' ? "{$label}: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ok(bool $condition, string $label = 'assertion failed'): void
{
    if (!$condition) {
        throw new \RuntimeException($label);
    }
}

function throws(callable $fn, string $class = \Throwable::class): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $class) {
            throw new \RuntimeException("expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
        }
        return $e;
    }
    throw new \RuntimeException("expected {$class}, nothing was thrown");
}

function names(array $models, string $key = 'Name'): array
{
    return array_map(fn($m) => $m->getAttributeValue($key), $models);
}

function queryCount(callable $fn): int
{
    $before = QueryLogger::getQueryCount();
    $fn();
    return QueryLogger::getQueryCount() - $before;
}

// ============================================================================
// Models
// ============================================================================

class User extends Model
{
    use HasTimestamps;

    protected static string $table = 'miko_t_users';
    protected array $fillable = ['Name', 'Email', 'Settings', 'Active'];
    protected array $hidden = ['Password'];
    protected array $casts = ['Settings' => 'json', 'Active' => 'bool'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'miko_t_user_roles')->withPivot('Level');
    }

    public static bool $mailSent = false;

    public function notifyAll()
    {
        self::$mailSent = true;
        return 'untyped, not a relation';
    }

    public function scopeActive($query): void
    {
        $query->where('Active', 1);
    }

    public function sendMail(): string
    {
        self::$mailSent = true;
        return 'sent';
    }

    protected static function defineSchema(TableBuilder $table): void
    {
        $table->id();
        $table->string('Name', 100);
        $table->string('Email', 150)->nullable()->unique();
        $table->string('Password', 255)->nullable();
        $table->string('Role', 30)->nullable();
        $table->json('Settings')->nullable();
        $table->boolean('Active')->default(true);
        $table->timestamps();
    }
}

class Post extends Model
{
    use SoftDeletes, HasTimestamps;

    protected static string $table = 'miko_t_posts';
    protected array $fillable = ['UserId', 'Title', 'Views', 'Published'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    protected static function defineSchema(TableBuilder $table): void
    {
        $table->id();
        $table->integer('UserId')->nullable()->index();
        $table->string('Title', 100);
        $table->integer('Views')->default(0);
        $table->dateTime('Published')->nullable();
        $table->softDeletes();
        $table->timestamps();
    }
}

class Comment extends Model
{
    protected static string $table = 'miko_t_comments';
    protected array $fillable = ['Body'];

    protected static function defineSchema(TableBuilder $table): void
    {
        $table->id();
        $table->integer('PostId');
        $table->string('Body', 100);
    }
}

class Profile extends Model
{
    protected static string $table = 'miko_t_profiles';
    protected array $fillable = ['UserId', 'Bio'];

    protected static function defineSchema(TableBuilder $table): void
    {
        $table->id();
        $table->integer('UserId');
        $table->string('Bio', 100);
    }
}

class Role extends Model
{
    protected static string $table = 'miko_t_roles';
    protected array $fillable = ['Name'];

    protected static function defineSchema(TableBuilder $table): void
    {
        $table->id();
        $table->string('Name', 50);
    }
}

class Tag extends Model
{
    protected static string $table = 'miko_t_tags';
    protected string $primaryKey = 'Code';
    protected bool $incrementing = false;
    protected string $keyType = 'string';
    protected array $fillable = ['Code', 'Name'];

    protected static function defineSchema(TableBuilder $table): void
    {
        $table->string('Code', 20)->notNull()->primary();
        $table->string('Name', 50)->nullable();
    }
}

class Wide extends Model
{
    protected static string $table = 'miko_t_wide';

    protected static function defineSchema(TableBuilder $table): void
    {
        $table->id();
        for ($i = 1; $i <= 40; $i++) {
            $table->integer("C{$i}")->nullable();
        }
    }
}

class ValidatedUser extends Model
{
    protected static string $table = 'miko_t_users';

    #[Required, MaxLength(5)]
    protected $Name;

    #[Email, UniqueValue]
    protected $Email;
}

class AttributeModel extends Model
{
    protected static string $table = 'miko_t_attr';

    #[\Miko\Database\ORM\Column(type: 'VARCHAR', length: 40, nullable: false)]
    protected $Title;

    #[\Miko\Database\ORM\Column(type: 'INT')]
    protected $Amount;
}

class UserObserver extends Observer
{
    public static array $log = [];

    public function creating(Model $model)
    {
        self::$log[] = 'creating:' . $model->Name;
        return $model->Name !== 'blocked';
    }

    public function created(Model $model): void
    {
        self::$log[] = 'created:' . $model->Name;
    }
}

class UserFactory extends Factory
{
    protected function model(): string
    {
        return User::class;
    }

    public function definition(): array
    {
        return ['Name' => 'factory', 'Role' => 'guest'];
    }
}

class TestSeeder extends Seeder
{
    public function run(): void
    {
        $this->insert('miko_t_roles', [['Name' => 's1'], ['Name' => 's2']]);
    }

    public function truncateTable(string $table): void
    {
        $this->truncate($table);
    }
}

class CreateMigTable extends Migration
{
    public function version(): string
    {
        return '2026_01_01_000001';
    }

    public function up(Schema $schema): void
    {
        $schema->create('miko_t_mig', function (TableBuilder $table) {
            $table->id();
            $table->string('X', 10);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('miko_t_mig');
    }
}

class CtxItem extends Model
{
    protected static string $table = 'ctx_items';
    protected array $fillable = ['Name'];

    protected static function defineSchema(TableBuilder $table): void
    {
        $table->id();
        $table->string('Name', 50);
    }
}

class TestContext extends DbContext
{
    public static string $file = '';

    public MikoSet $CtxItems;
    protected array $models = ['CtxItems' => CtxItem::class];

    protected function getConfig(): array
    {
        return ['driver' => 'sqlite', 'database' => self::$file];
    }
}

// ============================================================================
// Setup
// ============================================================================

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'miko-tests-' . getmypid();
@mkdir($tmp, 0777, true);
Logger::setLogDir($tmp . DIRECTORY_SEPARATOR . 'Log');
QueryLogger::enable();
QueryLogger::setLogFile(null);

$config = ['driver' => getenv('MIKO_TEST_DRIVER') ?: 'sqlite'];
if ($config['driver'] === 'sqlite') {
    $config['database'] = getenv('MIKO_TEST_DATABASE') ?: ':memory:';
} else {
    foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
        $value = getenv('MIKO_TEST_' . strtoupper($key));
        if ($value !== false) {
            $config[$key] = $value;
        }
    }
}

$connection = ConnectionFactory::make($config);
ConnectionResolver::setDefault($connection);
$driver = $connection->getDriverName();
echo 'MikoORM ' . \Miko\Core\Version::VERSION . " tests on {$driver} " . $connection->getServerVersion() . "\n";

const MODELS = [User::class, Post::class, Comment::class, Profile::class, Role::class, Tag::class, Wide::class];

function dropAll(Connection $connection): void
{
    $schema = new Schema($connection);
    foreach (['miko_t_user_roles', 'miko_t_attr', 'miko_t_mig', 'miko_t_migrations', 'miko_t_wide', 'miko_t_tags', 'miko_t_roles', 'miko_t_profiles', 'miko_t_comments', 'miko_t_posts', 'miko_t_users'] as $table) {
        $schema->dropIfExists($table);
    }
}

function seed(Connection $connection): void
{
    dropAll($connection);
    $schema = new Schema($connection);

    foreach (MODELS as $model) {
        $model::createTableUsing($schema);
    }

    $schema->create('miko_t_user_roles', function (TableBuilder $table) {
        $table->integer('UserId')->notNull();
        $table->integer('RoleId')->notNull();
        $table->string('Level', 20)->nullable();
        $table->primary(['UserId', 'RoleId']);
    });

    $q = fn(string $table) => DB::table($table);
    $q('miko_t_users')->insertBatch([
        ['Name' => 'alice', 'Email' => 'a@x', 'Active' => 1, 'CreatedDate' => '2026-01-01 10:00:00'],
        ['Name' => 'bob', 'Email' => 'b@x', 'Active' => 1, 'CreatedDate' => '2026-01-02 10:00:00'],
        ['Name' => 'carol', 'Email' => 'c@x', 'Active' => 0, 'CreatedDate' => '2026-01-03 10:00:00'],
    ]);
    $q('miko_t_posts')->insertBatch([
        ['UserId' => 1, 'Title' => 'a1', 'Views' => 10, 'Published' => '2026-03-15 08:30:00'],
        ['UserId' => 1, 'Title' => 'a2', 'Views' => 20, 'Published' => '2026-03-16 23:59:59'],
        ['UserId' => 2, 'Title' => 'b1', 'Views' => 30, 'Published' => '2025-12-31 12:00:00'],
    ]);
    $q('miko_t_comments')->insertBatch([
        ['PostId' => 1, 'Body' => 'c1'],
        ['PostId' => 1, 'Body' => 'c2'],
        ['PostId' => 3, 'Body' => 'c3'],
    ]);
    $q('miko_t_profiles')->insert(['UserId' => 1, 'Bio' => 'bio-a']);
    $q('miko_t_roles')->insertBatch([['Name' => 'admin'], ['Name' => 'editor']]);
    $q('miko_t_user_roles')->insertBatch([
        ['UserId' => 1, 'RoleId' => 1, 'Level' => 'high'],
        ['UserId' => 1, 'RoleId' => 2, 'Level' => 'low'],
        ['UserId' => 2, 'RoleId' => 2, 'Level' => 'mid'],
    ]);
}

seed($connection);

// ============================================================================
// 1. Relations
// ============================================================================

T::group('Relations');

T::test('hasMany returns only the parent rows', function () {
    same(['b1'], names(User::find(2)->posts, 'Title'));
    same(['a1', 'a2'], names(User::find(1)->posts()->orderBy('Id')->get(), 'Title'));
});

T::test('belongsTo returns the owner', function () {
    same('bob', Post::find(3)->user->Name);
    same('alice', Post::find(1)->user->Name);
});

T::test('hasOne returns the related row or null', function () {
    same('bio-a', User::find(1)->profile->Bio);
    same(null, User::find(2)->profile);
});

T::test('eager loading maps every parent (belongsTo / hasMany / hasOne)', function () {
    $posts = Post::query()->with('user')->orderBy('Id')->get();
    same(['alice', 'alice', 'bob'], array_map(fn($p) => $p->user->Name, $posts));

    $users = User::query()->with('posts', 'profile')->orderBy('Id')->get();
    same([2, 1, 0], array_map(fn($u) => count($u->posts), $users));
    same(['bio-a', null, null], array_map(fn($u) => $u->profile?->Bio, $users));
});

T::test('nested eager loading uses one query per level', function () {
    $count = queryCount(function () use (&$users) {
        $users = User::query()->with('posts.comments')->orderBy('Id')->get();
    });
    same(3, $count, 'queries');
    same(['c1', 'c2'], names($users[0]->posts[0]->comments, 'Body'));
    same(['c3'], names($users[1]->posts[0]->comments, 'Body'));
    same(0, queryCount(fn() => $users[0]->posts), 'lazy access after eager load');
});

T::test('constrained eager loading', function () {
    $users = User::query()->with(['posts' => fn($q) => $q->where('Title', 'a2')])->orderBy('Id')->get();
    same(['a2'], names($users[0]->posts, 'Title'));
    same([], $users[1]->posts);
});

T::test('relation query can be chained', function () {
    same(1, User::find(1)->posts()->where('Title', 'a1')->count());
    same(true, User::find(1)->posts()->where('Views', '>', 15)->exists());
});

T::test('belongsToMany lazy, eager, pivot data', function () {
    $roles = User::find(1)->roles()->orderBy('miko_t_roles.Id')->get();
    same(['admin', 'editor'], names($roles));
    same('high', $roles[0]->pivot['Level'], 'pivot Level');
    same(false, array_key_exists('pivot_UserId', $roles[0]->getAttributes()), 'pivot columns removed from attributes');

    $users = User::query()->with('roles')->orderBy('Id')->get();
    same([2, 1, 0], array_map(fn($u) => count($u->roles), $users));
});

T::test('belongsToMany attach / sync / detach', function () {
    $carol = User::find(3);
    $carol->roles()->attach([1 => ['Level' => 'x'], 2 => []]);
    same(2, count($carol->roles()->get()));

    $changes = $carol->roles()->sync([2]);
    same(['1'], array_map('strval', $changes['detached']));
    same([2], array_map('intval', array_map(fn($r) => $r->Id, $carol->roles()->get())));

    same(1, $carol->roles()->detach());
    same([], $carol->roles()->get());
});

T::test('hasMany create sets the foreign key even when it is not fillable', function () {
    $comment = Post::find(3)->comments()->create(['Body' => 'new']);
    same(3, (int) $comment->PostId);
    same(2, count(Post::find(3)->comments));
});

T::test('relations of an unsaved model return empty without a query', function () {
    $user = new User(['Name' => 'tmp']);
    same(0, queryCount(fn() => $user->posts));
    same([], $user->posts);
});

// ============================================================================
// 2. Model writes / attribute access
// ============================================================================

T::group('Model writes and attribute access');

T::test('$model->update() changes only that row', function () {
    User::find(1)->update(['Email' => 'alice@new']);
    same(1, User::where('Email', 'alice@new')->count());
    same('b@x', User::find(2)->Email);
});

T::test('reading a method name as property never runs it', function () {
    $user = User::find(2);
    same(null, $user->delete);
    same(null, $user->save);
    same(true, User::where('Id', 2)->exists(), 'row still there');

    // Typed non-relation methods are never called by property access
    same(null, $user->sendMail);
    same(false, User::$mailSent);

    // Untyped methods are treated like relations and must return one
    throws(fn() => $user->notifyAll, \LogicException::class);
});

T::test('$model->exists is a property, User::exists() a query', function () {
    same(true, User::find(1)->exists);
    same(false, (new User())->exists);
    same(true, User::exists());
    same(true, User::any());
    same(false, User::doesntExist());
});

T::test('mass writes through the model instance are blocked', function () {
    throws(fn() => User::restore(), \BadMethodCallException::class);
    throws(fn() => User::query()->nope(), \BadMethodCallException::class);
});

T::test('create() respects $fillable and guards the primary key', function () {
    $user = User::create(['Name' => 'eve', 'Email' => 'e@x', 'Role' => 'admin', 'Id' => 999]);
    $row = DB::table('miko_t_users')->where('Name', 'eve')->first();
    same(null, $row['Role']);
    ok((int) $row['Id'] !== 999, 'Id not mass assigned');
    same((int) $row['Id'], $user->Id);
});

T::test('invalid column names never reach SQL', function () {
    $user = (new User())->forceFill(['Name' => 'x', 'Name) VALUES (1); --' => 'y']);
    throws(fn() => $user->save(), DatabaseException::class);
    throws(fn() => User::where('Name = 1 OR 1', 1)->get(), DatabaseException::class);
    throws(fn() => User::where('Name', 'OR 1=1 --', 'x')->get(), DatabaseException::class);
    throws(fn() => User::query()->orderBy('Name; DROP TABLE x')->get(), DatabaseException::class);
    throws(fn() => User::query()->select('Name, (SELECT 1)')->get(), DatabaseException::class);
});

T::test('increment / decrement are atomic and touch UpdatedDate', function () {
    $post = Post::find(1);
    DB::table('miko_t_posts')->where('Id', 1)->update(['Views' => 50]); // changed behind the model
    ok($post->increment('Views', 5));
    same(55, (int) DB::table('miko_t_posts')->where('Id', 1)->value('Views'));
    ok($post->UpdatedDate !== null, 'UpdatedDate set');
    $post->decrement('Views', 5);
    same(50, (int) DB::table('miko_t_posts')->where('Id', 1)->value('Views'));
});

T::test('single(), singleOrFail()', function () {
    same('bob', User::where('Name', 'bob')->single()->Name);
    same(null, User::where('Name', 'nobody')->single());
    throws(fn() => User::single(), DatabaseException::class);
});

T::test('json_encode() of a model uses toArray() and hides $hidden', function () {
    DB::table('miko_t_users')->where('Id', 2)->update(['Password' => 'secret']);
    $json = json_encode(User::find(2));
    ok(str_contains($json, '"Name":"bob"'), $json);
    ok(!str_contains($json, 'secret'), 'password hidden');
});

T::test('string primary key is kept after insert', function () {
    $tag = Tag::create(['Code' => 'abc', 'Name' => 'x']);
    same('abc', $tag->getKey());
    same('x', Tag::find('abc')->Name);
});

T::test('models without HasTimestamps insert into tables without date columns', function () {
    $role = Role::create(['Name' => 'viewer']);
    ok($role->Id > 0);
});

T::test('casts are applied on read and write', function () {
    $user = User::create(['Name' => 'cast', 'Settings' => ['theme' => 'dark'], 'Active' => false]);
    $raw = DB::table('miko_t_users')->where('Id', $user->Id)->first();
    ok(is_string($raw['Settings']), 'stored as JSON text');
    // PostgreSQL jsonb and MySQL JSON normalise the spacing, so compare the decoded value
    same(['theme' => 'dark'], json_decode($raw['Settings'], true));
    same(0, (int) $raw['Active']);
    $fresh = User::find($user->Id);
    same(['theme' => 'dark'], $fresh->Settings);
    same(false, $fresh->Active);
});

T::test('dirty check treats "5" and 5 as equal (no needless UPDATE)', function () {
    $post = Post::find(2);
    $post->Views = (string) $post->Views;
    same([], $post->getDirty());
    same(0, queryCount(fn() => $post->save()));
});

T::test('fresh() / refresh() / replicate()', function () {
    $user = User::find(2);
    DB::table('miko_t_users')->where('Id', 2)->update(['Name' => 'bobby']);
    same('bobby', $user->fresh()->Name);
    $user->refresh();
    same('bobby', $user->Name);
    $copy = $user->replicate();
    same(null, $copy->Id);
    same('bobby', $copy->Name);
    DB::table('miko_t_users')->where('Id', 2)->update(['Name' => 'bob']);
});

T::test('model events can cancel, observers work (void return types allowed)', function () {
    User::observe(UserObserver::class);
    UserObserver::$log = [];
    $blocked = new User(['Name' => 'blocked']);
    same(false, $blocked->save());
    same(false, $blocked->exists);
    User::create(['Name' => 'watched']);
    same(['creating:blocked', 'creating:watched', 'created:watched'], UserObserver::$log);
    ObserverManager::flush(User::class);

    $retrieved = 0;
    User::retrieved(function () use (&$retrieved) { $retrieved++; });
    User::query()->limit(2)->get();
    same(2, $retrieved);
    ModelEvent::clearListeners(User::class);
});

T::test('ModelValidator validates attribute rules', function () {
    $validator = new ModelValidator();
    $user = new ValidatedUser();
    $user->Name = 'toolongname';
    $user->Email = 'b@x';
    same(false, $validator->validate($user));
    $errors = $validator->getErrors();
    ok(isset($errors['Name']) && isset($errors['Email']), json_encode($errors));

    $user->Name = 'ok';
    $user->Email = 'free@example.com';
    same(true, $validator->validate($user), json_encode($validator->getErrors()));
});

T::test('ModelMetadata ignores library properties and builds tables', function () {
    $columns = array_keys(ModelMetadata::for(AttributeModel::class)->columns);
    same(['Title', 'Amount'], $columns);
    AttributeModel::migrate();
    same(true, AttributeModel::tableExists());
    $item = AttributeModel::create(['Title' => 't', 'Amount' => 3]);
    same(3, (int) AttributeModel::find($item->Id)->Amount);
});

T::test('migration helpers: migrate() / recreateTable()', function () {
    same(false, User::migrate(), 'already exists');
    Tag::recreateTable();
    same(0, Tag::count());
});

// ============================================================================
// 3. Scopes and soft deletes
// ============================================================================

T::group('Scopes and soft deletes');

seed($connection);

T::test('orWhere cannot escape the soft delete scope', function () {
    Post::find(3)->delete();
    $query = Post::where('Title', 'zzz')->orWhere('Views', 30);
    same([], $query->get());
    ok(str_contains($query->toSql(), ') AND ('), $query->toSql());
});

T::test('withTrashed / onlyTrashed / find', function () {
    same(3, Post::withTrashed()->count());
    same(1, Post::onlyTrashed()->count());
    same(null, Post::find(3));
    same('b1', Post::withTrashed()->find(3)->Title);
});

T::test('soft delete, trashed(), restore()', function () {
    $post = Post::withTrashed()->find(3);
    same(true, $post->trashed());
    ok($post->restore());
    same(false, $post->trashed());
    same('b1', Post::find(3)->Title);
});

T::test('query delete() soft deletes, forceDelete() removes', function () {
    same(1, Post::where('Title', 'a2')->delete());
    same(3, (int) DB::table('miko_t_posts')->count(), 'row kept');
    same(1, Post::onlyTrashed()->forceDelete());
    same(2, (int) DB::table('miko_t_posts')->count());
});

T::test('destroy() soft deletes through models', function () {
    same(1, Post::destroy(1));
    same(1, Post::onlyTrashed()->count());
    same(1, Post::onlyTrashed()->restore());
});

T::test('custom global scope and withoutGlobalScope()', function () {
    User::addGlobalScope('active', fn($q) => $q->where('Active', 1));
    same(2, User::count());
    same(3, User::query()->withoutGlobalScope('active')->count());
    same(3, User::query()->withoutGlobalScopes()->count());
    same(['alice', 'bob'], names(User::where('Name', 'carol')->orWhere('Active', 1)->orderBy('Id')->get()));
    User::removeGlobalScope('active');
});

T::test('local scope via model and builder', function () {
    same(2, User::active()->count());
    same(1, User::where('Name', 'alice')->active()->count());
});

// ============================================================================
// 4. Transactions
// ============================================================================

T::group('Transactions');

T::test('Throwable inside run() rolls back and resets the level', function () use ($connection) {
    try {
        Transaction::run(function () {
            Role::create(['Name' => 'tx1']);
            intdiv(1, 0);
        });
    } catch (\DivisionByZeroError $e) {
    }
    same(0, Transaction::getLevel());
    same(false, $connection->inTransaction());
    same(0, Role::where('Name', 'tx1')->count());
});

T::test('model writes inside run() commit together', function () {
    Transaction::run(function () {
        Role::create(['Name' => 'tx2']);
        Role::create(['Name' => 'tx3']);
    });
    same(2, Role::whereIn('Name', ['tx2', 'tx3'])->count());
});

T::test('nested run() uses savepoints', function () {
    Transaction::run(function () {
        Role::create(['Name' => 'outer']);
        try {
            Transaction::run(function () {
                Role::create(['Name' => 'inner']);
                throw new \RuntimeException('inner fails');
            });
        } catch (\RuntimeException $e) {
        }
        same(1, Transaction::getLevel());
    });
    same(1, Role::where('Name', 'outer')->count());
    same(0, Role::where('Name', 'inner')->count());
});

T::test('DB::transaction and tryRun', function () {
    same('done', DB::transaction(fn() => 'done'));
    $error = null;
    same(false, Transaction::tryRun(function () { throw new \LogicException('x'); }, $error));
    ok($error instanceof \LogicException);
});

// ============================================================================
// 5. ORM query builder
// ============================================================================

T::group('ORM query builder');

seed($connection);

T::test('count() with groupBy / having', function () {
    same(3, Post::query()->groupBy('Title')->count());
    same(1, Post::query()->groupBy('UserId')->having('COUNT(*)', '>', 1)->count());
});

T::test('skip() without take(), latest()', function () {
    same(['bob', 'carol'], names(User::query()->orderBy('Id')->skip(1)->get()));
    same('carol', User::query()->latest()->first()->Name);
});

T::test('paginate()', function () {
    $page = User::query()->orderBy('Id')->paginate(2, 2);
    same(3, $page['total']);
    same(2, $page['last_page']);
    same(['carol'], names($page['data']));
    same(3, $page['from']);
    same(3, $page['to']);
    same(0, User::where('Name', 'none')->paginate(10)['from']);
});

T::test('chunkById(), chunk(), each(), cursor()', function () {
    $seen = [];
    User::query()->chunkById(2, function ($users) use (&$seen) {
        array_push($seen, ...names($users));
    });
    same(['alice', 'bob', 'carol'], $seen);

    $pages = 0;
    User::query()->chunk(2, function () use (&$pages) { $pages++; });
    same(2, $pages);

    $count = 0;
    foreach (User::query()->cursor() as $user) {
        ok($user instanceof User);
        $count++;
    }
    same(3, $count);
});

T::test('whereDate / whereYear compile to index friendly ranges', function () {
    $query = Post::query()->whereDate('Published', '2026-03-16');
    ok(!preg_match('/date\(|DATE\(/', $query->toSql()), $query->toSql());
    same(['a2'], names($query->get(), 'Title'));
    same(2, Post::query()->whereYear('Published', 2026)->count());
    same(1, Post::query()->whereDate('Published', '<', '2026-01-01')->count());
    same(2, Post::query()->whereMonth('Published', 3)->count());
});

T::test('whereLike treats % and _ in the value literally', function () {
    User::create(['Name' => 'a_b']);
    User::create(['Name' => 'axb']);
    same(['a_b'], names(User::query()->whereLike('Name', 'a_b')->get()));
    same(2, User::query()->whereLike('Name', 'a_b', false)->count());
    same(['alice'], names(User::query()->whereStartsWith('Name', 'al')->get()));
});

T::test('exists() runs SELECT 1 ... LIMIT 1 instead of COUNT', function () {
    QueryLogger::clear();
    User::where('Name', 'alice')->exists();
    $sql = QueryLogger::getQueries()[0]['sql'];
    ok(!str_contains($sql, 'COUNT') && str_contains($sql, '1 AS miko_exists'), $sql);
});

T::test('pluck(), value(), sum(), whereIn([])', function () {
    same(['alice', 'bob', 'carol'], User::query()->orderBy('Id')->limit(3)->pluck('Name'));
    same(['alice' => 'a@x'], User::where('Name', 'alice')->pluck('Email', 'Name'));
    same('bob', User::where('Id', 2)->value('Name'));
    same(60, Post::sum('Views'));
    same(0, User::query()->whereIn('Id', [])->count());
    same(3, User::query()->whereNotIn('Id', [])->where('Id', '<=', 3)->count());
});

T::test('nested where groups and array where', function () {
    $users = User::where('Active', 1)->where(fn($q) => $q->where('Name', 'alice')->orWhere('Name', 'carol'))->get();
    same(['alice'], names($users));
    same(['bob'], names(User::where(['Name' => 'bob', 'Active' => 1])->get()));
});

T::test('joins with validated columns', function () {
    $rows = User::query()
        ->select('miko_t_users.Name', 'miko_t_posts.Title as PostTitle')
        ->join('miko_t_posts', 'miko_t_posts.UserId', '=', 'miko_t_users.Id')
        ->orderBy('miko_t_posts.Id')
        ->get();
    same(['a1', 'a2', 'b1'], names($rows, 'PostTitle'));
});

// ============================================================================
// 6. Table query builder / fluent / raw
// ============================================================================

T::group('Table query builder');

T::test('having() before where() still binds the right values', function () {
    $rows = DB::table('miko_t_posts')->select('UserId', 'COUNT(*) as c')->groupBy('UserId')
        ->having('COUNT(*)', '>=', 2)->where('Title', '!=', 'zzz')->get();
    same(1, count($rows));
    same(1, (int) $rows[0]['UserId']);
});

T::test('selectRaw / whereRaw bindings in any order, ? inside literals ignored', function () use ($connection) {
    // raw SQL is sent as written: quote mixed-case names yourself (PostgreSQL folds unquoted names to lower case)
    $w = fn(string $name) => $connection->getGrammar()->wrap($name);
    $rows = DB::table('miko_t_posts')
        ->where('Views', '>', 5)
        ->selectRaw($w('Views') . ' + ? AS ' . $w('Bumped'), [100])
        ->whereRaw($w('Title') . " <> '??' AND " . $w('Views') . ' < ?', [25])
        ->orderBy('Id')
        ->get();
    same([110, 120], array_map(fn($r) => (int) $r['Bumped'], $rows));
});

T::test('union with bindings on both sides', function () use ($connection) {
    $a = (new FluentQueryBuilder($connection))->from('miko_t_posts')->select('Title')->where('Views', '=', 10);
    $b = (new FluentQueryBuilder($connection))->from('miko_t_posts')->select('Title')->where('Views', '=', 30);
    $a->union($b);
    $titles = array_column($a->get(), 'Title');
    sort($titles);
    same(['a1', 'b1'], $titles);
    same(2, $a->count());
    same(true, $a->exists());
    same(2, count($a->get()), 'second run does not duplicate bindings');
});

T::test('insert / update / increment / delete / updateOrInsert', function () {
    $table = fn() => DB::table('miko_t_roles');
    ok($table()->insert(['Name' => 'q1']));
    same(1, $table()->where('Name', 'q1')->update(['Name' => 'q2']));
    $table()->updateOrInsert(['Name' => 'q3'], []);
    $table()->updateOrInsert(['Name' => 'q3'], []);
    same(1, $table()->where('Name', 'q3')->count());
    same(2, $table()->whereIn('Name', ['q2', 'q3'])->delete());
    same(1, DB::table('miko_t_posts')->where('Id', 1)->increment('Views', 2));
    same(12, (int) DB::table('miko_t_posts')->where('Id', 1)->value('Views'));
});

T::test('paginate, whereDate, whereLike, where(col, value)', function () {
    $page = DB::table('miko_t_users')->orderBy('Id')->paginate(2, 1);
    same(5, $page['pagination']['total']);
    same(1, count($page['data']));
    same(1, DB::table('miko_t_posts')->whereDate('Published', '2026-03-15')->count());
    same(1, DB::table('miko_t_users')->whereLike('Name', 'a_b')->count());
    same(1, DB::table('miko_t_users')->where('Name', 'bob')->count());
});

T::test('injection attempts are rejected', function () {
    throws(fn() => DB::table('miko_t_users')->where('Name', 'OR 1=1', 'x')->get(), DatabaseException::class);
    throws(fn() => DB::table('miko_t_users; DROP TABLE x')->get(), DatabaseException::class);
    throws(fn() => DB::table('miko_t_users')->orderBy('Name DESC, (SELECT 1)')->get(), DatabaseException::class);
});

T::test('RawQuery ignores :words inside string literals', function () use ($connection) {
    $w = fn(string $name) => $connection->getGrammar()->wrap($name);
    $sql = "SELECT ':skip' AS lit, {$w('Name')} FROM miko_t_users WHERE {$w('Id')} = :id";
    $row = RawQuery::make($connection, $sql)->bind('id', 2)->first();
    same('bob', $row['Name']);
});

T::test('Connection::paginate and Paginator::rawPaginate', function () use ($connection) {
    $id = $connection->getGrammar()->wrap('Id');
    same(5, $connection->paginate("SELECT * FROM miko_t_users ORDER BY {$id}", [], 1, 2)['total']);
    $result = Paginator::rawPaginate(DB::table('miko_t_users'), "SELECT * FROM miko_t_users WHERE {$id} > ?", [1], 2, 2);
    same(4, $result['pagination']['total']);
    same(2, count($result['data']));
});

// ============================================================================
// 7. Bulk operations
// ============================================================================

T::group('Bulk operations');

T::test('BulkOperations::insert keeps $hidden columns of models', function () {
    $user = (new User())->forceFill(['Name' => 'zed', 'Password' => 'secret']);
    same(1, BulkOperations::insert(User::class, [$user]));
    same('secret', DB::table('miko_t_users')->where('Name', 'zed')->value('Password'));
});

T::test('BulkOperations::insert chunks by the parameter limit (1000 x 40 columns)', function () {
    $row = [];
    for ($i = 1; $i <= 40; $i++) {
        $row["C{$i}"] = $i;
    }
    same(1000, BulkOperations::insert(Wide::class, array_fill(0, 1000, $row)));
    same(1000, Wide::count());
});

T::test('BulkOperations update / upsert / delete', function () {
    same(2, BulkOperations::update(Post::class, [['Id' => 1, 'Title' => 'A1'], ['Id' => 2, 'Title' => 'A2']]));
    same(['A1', 'A2'], names(Post::query()->whereIn('Id', [1, 2])->orderBy('Id')->get(), 'Title'));

    BulkOperations::upsert(Tag::class, [['Code' => 'u1', 'Name' => 'one']], 'Code');
    BulkOperations::upsert(Tag::class, [['Code' => 'u1', 'Name' => 'uno'], ['Code' => 'u2', 'Name' => 'two']], 'Code');
    same(['uno', 'two'], names(Tag::query()->whereIn('Code', ['u1', 'u2'])->orderBy('Code')->get()));

    same(1, BulkOperations::delete(Post::class, [2]));
    same(1, Post::onlyTrashed()->count(), 'soft deleted');
});

T::test('BulkInsert maps associative rows by column name', function () use ($connection) {
    (new BulkInsert($connection))->into('miko_t_users')->columns(['Name', 'Email'])
        ->addRow(['Email' => 'swap@x', 'Name' => 'swapname'])
        ->addRow(['listname', 'list@x'])
        ->execute();
    same('swapname', DB::table('miko_t_users')->where('Email', 'swap@x')->value('Name'));
    same('listname', DB::table('miko_t_users')->where('Email', 'list@x')->value('Name'));
});

T::test('BulkUpdate', function () use ($connection) {
    $affected = (new BulkUpdate($connection))->table('miko_t_roles')->keyColumn('Id')
        ->addUpdate(1, ['Name' => 'ADMIN'])->addUpdate(2, ['Name' => 'EDITOR'])->execute();
    same(2, $affected);
    same('EDITOR', DB::table('miko_t_roles')->where('Id', 2)->value('Name'));
});

// ============================================================================
// 8. Async (parallel on MySQL / MariaDB / PostgreSQL, one by one elsewhere)
// ============================================================================

$parallel = AsyncConnection::isParallel($connection);
T::group('Async (' . ($parallel ? 'parallel connections' : 'runs on the main connection') . ')');

/**
 * Comparable form of models: class, raw attributes (types included), relations sorted by name
 */
function dump(mixed $value): mixed
{
    if ($value instanceof Model) {
        $relations = array_map(__NAMESPACE__ . '\dump', $value->getRelations());
        ksort($relations);
        return ['@' => get_class($value), 'attributes' => $value->getAttributes(), 'relations' => $relations];
    }
    return is_array($value) ? array_map(__NAMESPACE__ . '\dump', $value) : $value;
}

function seconds(callable $fn): float
{
    $start = microtime(true);
    $fn();
    return microtime(true) - $start;
}

$sleepSql = fn(float $seconds): string => match ($driver) {
    'pgsql' => "SELECT pg_sleep({$seconds}) AS s",
    'sqlsrv' => sprintf("WAITFOR DELAY '00:00:%06.3f'; SELECT 1 AS s", $seconds),
    default => "SELECT SLEEP({$seconds}) AS s",
};
$w = fn(string $name): string => $connection->getGrammar()->wrap($name);

T::test('driver support is reported correctly', function () use ($driver, $parallel) {
    // the PHP extension (mysqli / pgsql) or the built-in PHP client (SQL Server: always built-in)
    $expected = in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true);
    same($expected, $parallel);
    same($parallel, DB::supportsParallelQueries());
});

T::test('getAsync() returns the same models as get(), types included', function () {
    $queries = [
        fn() => User::query()->orderBy('Id'),
        fn() => Post::query()->where('Views', '>', 0)->orderByDesc('Id'),
        fn() => Post::withTrashed()->orderBy('Id'),
        fn() => User::query()->active()->whereNotNull('Name')->orderBy('Id'),
    ];
    foreach ($queries as $i => $make) {
        same(dump($make()->get()), dump($make()->getAsync()->await()), "query {$i}");
    }
    same(dump(User::all()), dump(User::allAsync()->await()), 'allAsync');
});

T::test('first / find / count / exists / aggregates / value / pluck / paginate match', function () {
    same(dump(User::query()->orderBy('Id')->first()), dump(User::query()->orderBy('Id')->firstAsync()->await()));
    same(dump(User::find(1)), dump(User::findAsync(1)->await()));
    same(null, User::findAsync(999999)->await());
    same(dump(User::findMany([1, 2])), dump(User::findManyAsync([1, 2])->await()));
    same([], User::findManyAsync([])->await());
    same(User::count(), User::countAsync()->await());
    same(User::query()->count('Email'), User::query()->countAsync('Email')->await());
    same(Post::query()->groupBy('UserId')->count(), Post::query()->groupBy('UserId')->countAsync()->await(), 'grouped count');
    same(User::query()->exists(), User::query()->existsAsync()->await());
    same(false, User::where('Name', 'nobody-here')->existsAsync()->await());
    same(Post::query()->sum('Views'), Post::query()->sumAsync('Views')->await());
    same(Post::query()->avg('Views'), Post::query()->avgAsync('Views')->await());
    same(Post::query()->min('Title'), Post::query()->minAsync('Title')->await());
    same(Post::query()->max('Views'), Post::query()->maxAsync('Views')->await());
    same(User::query()->orderBy('Id')->value('Name'), User::query()->orderBy('Id')->valueAsync('Name')->await());
    same(User::query()->orderBy('Id')->pluck('Name', 'Id'), User::query()->orderBy('Id')->pluckAsync('Name', 'Id')->await());
    same(dump(User::query()->orderBy('Id')->paginate(2, 2)), dump(User::query()->orderBy('Id')->paginateAsync(2, 2)->await()));
});

T::test('eager loading: same relations, one query per relation, a level loads in parallel', function () {
    $make = fn() => User::query()->with('posts.comments', 'profile', 'roles')->orderBy('Id');
    $sync = $make()->get();
    $async = null;
    $count = queryCount(function () use ($make, &$async) {
        $async = $make()->getAsync()->await();
    });
    same(dump($sync), dump($async));
    same(5, $count, 'users, posts, comments, profile, roles');
});

T::test('relation methods: posts()->getAsync(), countAsync(), belongsToMany with pivot', function () {
    same(dump(User::find(1)->posts()->get()), dump(User::find(1)->posts()->getAsync()->await()));
    same(User::find(1)->posts()->count(), User::find(1)->posts()->countAsync()->await());
    same(dump(User::find(1)->roles()->get()), dump(User::find(1)->roles()->getAsync()->await()));
    same(dump(User::find(1)->roles()->first()), dump(User::find(1)->roles()->firstAsync()->await()));
});

T::test('table builder, RawQuery and union builder: async matches sync', function () use ($connection, $w) {
    $make = fn() => DB::table('miko_t_posts')->where('Views', '>=', 0)->orderBy('Id');
    same($make()->get(), $make()->getAsync()->await());
    same($make()->first(), $make()->firstAsync()->await());
    same($make()->count(), $make()->countAsync()->await());
    same($make()->exists(), $make()->existsAsync()->await());
    same($make()->sum('Views'), $make()->sumAsync('Views')->await());
    same($make()->avg('Views'), $make()->avgAsync('Views')->await());
    same($make()->min('Views'), $make()->minAsync('Views')->await());
    same($make()->max('Title'), $make()->maxAsync('Title')->await());
    same($make()->value('Title'), $make()->valueAsync('Title')->await());
    same($make()->pluck('Title', 'Id'), $make()->pluckAsync('Title', 'Id')->await());
    same($make()->paginate(1, 2), $make()->paginateAsync(1, 2)->await());
    same(DB::table('miko_t_posts')->find(1), DB::table('miko_t_posts')->findAsync(1)->await());

    $raw = fn() => RawQuery::make($connection, "SELECT {$w('Title')} FROM miko_t_posts WHERE {$w('Id')} = :id")->bind('id', 1);
    same($raw()->get(), $raw()->getAsync()->await());
    same($raw()->value(), $raw()->valueAsync()->await());
    throws(fn() => RawQuery::make($connection, 'DELETE FROM miko_t_posts')->getAsync(), DatabaseException::class);

    $union = function () use ($connection) {
        $a = (new FluentQueryBuilder($connection))->from('miko_t_posts')->select('Title')->where('Views', '>', 15);
        $b = (new FluentQueryBuilder($connection))->from('miko_t_posts')->select('Title')->where('Views', '<', 15);
        return $a->union($b);
    };
    $sorted = function (array $rows): array {
        $titles = array_column($rows, 'Title');
        sort($titles);
        return $titles;
    };
    same($sorted($union()->get()), $sorted($union()->getAsync()->await()));
    same($union()->count(), $union()->countAsync()->await());
    same($union()->exists(), $union()->existsAsync()->await());
});

T::test('values: quotes, backslashes, unicode, null / bool / int / float, named and repeated placeholders', function () use ($w) {
    $weird = "O'Re\\il\"ly %_ İşçi ? :x -- /*";
    DB::table('miko_t_roles')->insert(['Name' => $weird]);
    $byName = fn() => DB::table('miko_t_roles')->where('Name', $weird);
    same(1, count($byName()->getAsync()->await()));
    same($byName()->get(), $byName()->getAsync()->await());

    $named = "SELECT {$w('Id')} FROM miko_t_roles WHERE {$w('Name')} = :n OR ({$w('Name')} = :n AND ':n' <> 'x')";
    same(DB::query($named, ['n' => $weird]), DB::queryAsync($named, ['n' => $weird])->await());

    $types = "SELECT ? AS n, ? AS b, ? AS i, ? AS f, '?:x' AS lit";
    $values = [null, true, 7, 1.5];
    same(DB::query($types, $values), DB::queryAsync($types, $values)->await());
    same(DB::scalar('SELECT COUNT(*) FROM miko_t_users'), DB::scalarAsync('SELECT COUNT(*) FROM miko_t_users')->await());
    same(DB::first('SELECT 1 AS one'), DB::firstAsync('SELECT 1 AS one')->await());
    DB::table('miko_t_roles')->where('Name', $weird)->delete();
});

T::test('errors: same QueryException codes as sync; Async::all throws, allSettled reports', function () {
    $bad = 'SELECT NoSuchColumn FROM miko_t_users';
    $sync = throws(fn() => DB::query($bad), QueryException::class);

    $settled = Async::allSettled(['bad' => DB::queryAsync($bad), 'good' => User::countAsync(), 'plain' => 5]);
    same('rejected', $settled['bad']['status']);
    $async = $settled['bad']['reason'];
    ok($async instanceof QueryException, 'QueryException');
    same($sync->getSqlState(), $async->getSqlState(), 'SQLSTATE');
    same($sync->getCode(), $async->getCode(), 'driver code');
    same($bad, $async->getSql());
    same(['status' => 'fulfilled', 'value' => User::count()], $settled['good']);
    same(['status' => 'fulfilled', 'value' => 5], $settled['plain']);

    throws(fn() => Async::all([User::countAsync(), DB::queryAsync($bad)]), QueryException::class);
    same(['n' => User::count(), 'x' => 5, 'f' => 'v'], Async::all(['n' => User::countAsync(), 'x' => 5, 'f' => Future::resolved('v')]));
    // the links are still usable after an error
    same(User::count(), User::countAsync()->await());
});

T::test('inside a transaction async queries run on the main connection (see uncommitted rows)', function () {
    throws(fn() => Transaction::run(function () {
        User::create(['Name' => 'in-tx-async']);
        same(1, User::where('Name', 'in-tx-async')->countAsync()->await());
        same(1, count(User::where('Name', 'in-tx-async')->getAsync()->await()));
        throw new \RuntimeException('roll back');
    }), \RuntimeException::class);
    same(0, User::where('Name', 'in-tx-async')->countAsync()->await());
});

T::test('futures: then / catch / finally, nested await, unknown future', function () {
    same((User::count() + 1) * 2, User::countAsync()->then(fn($n) => $n + 1)->then(fn($n) => Future::resolved($n * 2))->await());
    same('recovered', DB::queryAsync('SELECT NoSuchColumn FROM miko_t_users')->catch(fn() => 'recovered')->await());
    $flag = false;
    User::countAsync()->finally(function () use (&$flag) {
        $flag = true;
    })->await();
    ok($flag, 'finally ran');
    same(User::count() + Post::query()->count(), User::countAsync()->then(fn($n) => $n + Post::query()->countAsync()->await())->await());
    throws(fn() => (new Deferred())->future()->await(), \LogicException::class);
});

T::test('query log marks async queries; enabled=false runs on the main connection', function () use ($parallel) {
    User::countAsync()->await();
    $queries = QueryLogger::getQueries();
    same($parallel, end($queries)['async']);

    Async::configure(['enabled' => 'false']);
    try {
        same(false, DB::supportsParallelQueries());
        same(User::count(), User::countAsync()->await());
        $queries = QueryLogger::getQueries();
        same(false, end($queries)['async']);
    } finally {
        Async::resetSettings();
    }
});

T::test('SqlBinder: placeholders outside literals, comments, quoted names and casts', function () {
    $marks = fn(array $parts) => array_values(array_filter($parts, 'is_array'));
    same(
        [['?', 0], [':', 'a'], ['?', 1]],
        $marks(SqlBinder::parse("SELECT '?', \"?\", `?`, x := 1, -- ?\n # :a\n /* ? */ ? , :a, :b, ? FROM t WHERE c = 'it\\'s ?'", 'mysql', [':a' => 1]))
    );
    same(
        [['?', 0], [':', 'a'], ['?', 1]],
        $marks(SqlBinder::parse("SELECT '?', \"?\", x::text, -- ?\n /* :a */ ? , :a, :b, $$ ? $$, \$t\$ :a \$t\$, ?, '??' FROM t", 'pgsql', ['a' => 1]))
    );
    same(['SELECT ? AS q, ', ['?', 0]], SqlBinder::parse('SELECT ?? AS q, ?', 'mysql', []));
    throws(fn() => SqlBinder::assertCount(SqlBinder::parse('SELECT ?, ?', 'mysql', []), [1]), DatabaseException::class);
});

T::test('parallel: 3 queries of 0.5 s take about 0.5 s', function () use ($parallel, $sleepSql) {
    if (!$parallel) {
        echo "        (skipped: no parallel driver)\n";
        return;
    }
    $sql = $sleepSql(0.5);
    $elapsed = seconds(fn() => Async::all([DB::queryAsync($sql), DB::queryAsync($sql), DB::queryAsync($sql)]));
    $log = array_map(fn(array $q) => json_encode(array_intersect_key($q, ['time_ms' => 1, 'timestamp' => 1, 'async' => 1])), array_slice(QueryLogger::getQueries(), -3));
    ok($elapsed < 1.2, sprintf('took %.2f s (one by one: 1.5 s); in transaction: %s, links: %d, last queries: %s',
        $elapsed, var_export(DB::connection()->inTransaction(), true), DB::connection()->asyncDriver()?->openLinks() ?? -1, implode(' ', $log)));
});

T::test('parallel: max_connections limits running queries', function () use ($parallel, $sleepSql) {
    if (!$parallel) {
        echo "        (skipped: no parallel driver)\n";
        return;
    }
    $sql = $sleepSql(0.4);
    Async::configure(['max_connections' => 2]);
    try {
        $elapsed = seconds(fn() => Async::all([DB::queryAsync($sql), DB::queryAsync($sql), DB::queryAsync($sql), DB::queryAsync($sql)]));
    } finally {
        Async::resetSettings();
    }
    ok($elapsed >= 0.75 && $elapsed < 1.5, sprintf('4 x 0.4 s with 2 connections took %.2f s', $elapsed));
});

T::test('timeout cancels the query on the server, the connection stays usable', function () use ($parallel, $sleepSql) {
    if (!$parallel) {
        echo "        (skipped: no parallel driver)\n";
        return;
    }
    Async::configure(['timeout' => 0.3]);
    try {
        $error = null;
        $elapsed = seconds(function () use ($sleepSql, &$error) {
            $error = throws(fn() => DB::queryAsync($sleepSql(5))->await(), AsyncTimeoutException::class);
        });
        same('HYT00', $error->getSqlState());
        ok($elapsed < 2.0, sprintf('cancelled after %.2f s', $elapsed));
        same(User::count(), User::countAsync()->await(), 'next query on the same links');
    } finally {
        Async::resetSettings();
    }
});

T::test('disconnect() closes the async connections', function () use ($config, $parallel) {
    $other = ConnectionFactory::make($config);
    $expected = $other->execute('SELECT 1 AS one')->all();
    same($expected, AsyncConnection::select($other, 'SELECT 1 AS one')->await());
    same($parallel ? 1 : 0, $other->asyncDriver()?->openLinks() ?? 0);
    $other->disconnect();
    $other->reconnect();
    same($expected, AsyncConnection::select($other, 'SELECT 1 AS one')->await());
});

T::test('built-in client (no PHP extension needed): same rows, types, errors, cancel as PDO', function () use ($driver, $config, $sleepSql) {
    $clients = [
        'pgsql' => [PgWireDriver::class, PgWireLink::class],
        'mysql' => [MyWireDriver::class, MyWireLink::class],
        'sqlsrv' => [TdsDriver::class, TdsLink::class],
    ];
    if (!isset($clients[$driver])) {
        echo "        (skipped: no built-in client for {$driver})\n";
        return;
    }
    [$driverClass, $linkClass] = $clients[$driver];

    $digits = '(SELECT 0 AS n' . implode('', array_map(fn($i) => " UNION ALL SELECT {$i}", range(1, 9))) . ')';
    $cases = match ($driver) {
        'pgsql' => [
            ["SELECT 1::int2 AS a, 2::int4 AS b, 9007199254740993::int8 AS c, 7::oid AS o, true AS t, false AS f, NULL::int AS n,
              1.5::float8 AS d, 12.30::numeric AS m, 'İşçi ''q'' \\ ?' AS s, '{\"k\": [1, 2]}'::jsonb AS j, DATE '2026-10-06' AS dt", []],
            ['SELECT :x AS a, :x || :y AS b', ['x' => 'v', 'y' => 'w']],
            ['SELECT g AS id, md5(g::text) AS h FROM generate_series(1, 5000) g', []],
        ],
        'mysql' => [
            ["SELECT 1 AS a, CAST(9007199254740993 AS SIGNED) AS c, CAST(18446744073709551615 AS UNSIGNED) AS u, 1.5 AS m,
              1.5e0 AS d, 'İşçi ''q'' \\\\ ?' AS s, DATE '2026-10-06' AS dt, b'101' AS bits, NULL AS n, X'00FF41' AS bin", []],
            ['SELECT :x AS a, CONCAT(:x, :y) AS b', ['x' => 'v', 'y' => 'w']],
            ['SELECT ? AS nul', ["a\0b\x1a\r\n"]],
            ["SELECT a.n * 100 + b.n * 10 + c.n AS id, MD5(a.n * 100 + b.n * 10 + c.n) AS h FROM {$digits} a CROSS JOIN {$digits} b CROSS JOIN {$digits} c ORDER BY id", []],
        ],
        'sqlsrv' => [
            ["SELECT 1 AS a, CAST(9007199254740993 AS bigint) AS c, CAST(1.5 AS float) AS d, CAST(1.1 AS real) AS r, CAST(12.30 AS decimal(10,2)) AS m,
              CAST(-0.5 AS decimal(5,2)) AS half, CAST(12.5 AS money) AS cash, N'İşçi ''q'' \\ ?' AS s, CAST('2026-10-06' AS date) AS dt,
              CAST('2026-10-06 12:34:56.997' AS datetime) AS ts, CAST('2026-10-06 12:34:56.1234567 +03:00' AS datetimeoffset) AS tz,
              CAST(1 AS bit) AS b, NULL AS n, 0x00FF41 AS bin, CAST('6F9619FF-8B86-D011-B42D-00C04FC964FF' AS uniqueidentifier) AS g,
              CAST('<a b=\"1\"/>' AS xml) AS x, REPLICATE(CAST(N'ş' AS nvarchar(max)), 5000) AS big", []],
            ['SELECT :x AS a, :x + :y AS b', ['x' => 'v', 'y' => 'w']],
            ['SELECT TOP 3000 a.object_id AS id, a.name AS n FROM sys.all_objects a CROSS JOIN sys.all_objects b ORDER BY a.object_id, b.object_id', []],
            ['SELECT CAST(1 AS sql_variant) AS v', []], // read again on the main connection
        ],
    };
    $cases[] = ['SELECT ? AS n, ? AS b, ? AS i, ? AS f, ? AS s', [null, true, 7, 1.5, "O'Re\\il\"ly İşçi -- /*"]];
    $cases[] = ["SELECT 1 AS dup, 2 AS dup, '' AS blank", []];
    $cases[] = ['SELECT 1 AS one FROM miko_t_users WHERE 1 = 0', []];

    Async::configure([$driver . '_driver' => 'php']);
    try {
        $db = ConnectionFactory::make($config);
        ok($db->asyncDriver() instanceof $driverClass, 'built-in client in use');
        $sync = fn(string $sql, array $b = []) => $db->execute($sql, $b)->all();
        $async = fn(string $sql, array $b = []) => AsyncConnection::select($db, $sql, $b)->await();

        foreach ($cases as $i => [$sql, $bindings]) {
            same($sync($sql, $bindings), $async($sql, $bindings), "case {$i}");
        }
        if ($driver === 'pgsql') {
            ok(!$db->asyncDriver()->canRun('SELECT ? AS v', ["a\0b"]), 'NUL bytes run on the main connection');
            same("\x00\xffA", $async("SELECT '\\x00ff41'::bytea AS bin")[0]['bin'], 'bytea');
        }

        $bad = 'SELECT NoSuchColumn FROM miko_t_users';
        $syncError = throws(fn() => $sync($bad), QueryException::class);
        $asyncError = throws(fn() => $async($bad), QueryException::class);
        same($syncError->getSqlState(), $asyncError->getSqlState(), 'SQLSTATE');
        same($syncError->getCode(), $asyncError->getCode(), 'driver code');

        $elapsed = seconds(fn() => Async::all([DB::queryAsync('SELECT 1'), AsyncConnection::select($db, $sleepSql(0.5)),
            AsyncConnection::select($db, $sleepSql(0.5)), AsyncConnection::select($db, $sleepSql(0.5))]));
        ok($elapsed < 1.2, sprintf('3 x 0.5 s took %.2f s', $elapsed));

        Async::configure(['timeout' => 0.3]);
        $error = throws(fn() => AsyncConnection::select($db, $sleepSql(5))->await(), AsyncTimeoutException::class);
        same('HYT00', $error->getSqlState());
        Async::configure(['timeout' => 0]);
        same([['one' => 1]], $async('SELECT 1 AS one'), 'link usable after cancel');
        ok($db->asyncDriver()->openLinks() >= 1 && $db->asyncDriver()->openLinks() <= 4, 'links reused');

        // wrong password: login error; async queries fall back to the main connection
        throws(fn() => $linkClass::open(['password' => 'wrong-password'] + $config), DatabaseException::class);
        $fallback = new Connection($db->getPdo(), ['password' => 'wrong-password'] + $config);
        same([['one' => 1]], AsyncConnection::select($fallback, 'SELECT 1 AS one')->await());
        same([['two' => 2]], AsyncConnection::select($fallback, 'SELECT 2 AS two')->await());
        same(0, $fallback->asyncDriver()->openLinks());
        $db->disconnect();
    } finally {
        Async::resetSettings();
    }
});

// ============================================================================
// 9. Connection, schema, migrations, infrastructure
// ============================================================================

T::group('Connection and infrastructure');

T::test('SQL Server: nullable unique keys become filtered indexes, ORDER BY subqueries get OFFSET', function () {
    $builder = new TableBuilder('t', 'sqlsrv');
    $builder->id();
    $builder->string('Email', 50)->nullable()->unique();
    $builder->string('Code', 10)->notNull()->unique();
    $create = $builder->build();
    ok(str_contains($create, 'CONSTRAINT [uq_t_Code] UNIQUE ([Code])'), 'NOT NULL column keeps the constraint');
    ok(!str_contains($create, 'uq_t_Email'), 'nullable column has no constraint');
    same(['CREATE UNIQUE INDEX [uq_t_Email] ON [t] ([Email]) WHERE [Email] IS NOT NULL'], $builder->getIndexStatements());

    $mysql = new TableBuilder('t', 'mysql');
    $mysql->string('Email', 50)->nullable()->unique();
    ok(str_contains($mysql->build(), 'CONSTRAINT `uq_t_Email` UNIQUE (`Email`)'), 'other drivers unchanged');

    $g = Grammar::for('sqlsrv');
    same('SELECT * FROM t ORDER BY Name OFFSET 0 ROWS', $g->derivedTable('SELECT * FROM t ORDER BY Name'));
    same('SELECT * FROM t ORDER BY Name OFFSET 5 ROWS', $g->derivedTable('SELECT * FROM t ORDER BY Name OFFSET 5 ROWS'));
    same('SELECT ROW_NUMBER() OVER (ORDER BY Id) AS rn FROM t', $g->derivedTable('SELECT ROW_NUMBER() OVER (ORDER BY Id) AS rn FROM t'));
    same('SELECT * FROM t ORDER BY Name', Grammar::for('mysql')->derivedTable('SELECT * FROM t ORDER BY Name'));
    same(false, Grammar::hasOrderBy('SELECT ROW_NUMBER() OVER (ORDER BY Id) FROM t'));
    same(true, Grammar::hasOrderBy('SELECT * FROM t ORDER BY LEN(Name), Id'));
});

T::test('QueryException detects duplicate keys', function () {
    $e = throws(fn() => User::create(['Name' => 'dup', 'Email' => 'b@x']), QueryException::class);
    same(true, $e->isDuplicateEntry());
});

T::test('HealthCheck reports a healthy connection', function () use ($connection) {
    $result = (new HealthCheck($connection))->run();
    same('ok', $result['checks']['connection']['status']);
});

T::test('disconnect() / reconnect()', function () use ($tmp) {
    $file = $tmp . DIRECTORY_SEPARATOR . 'reconnect.sqlite';
    $c = ConnectionFactory::make(['driver' => 'sqlite', 'database' => $file]);
    $c->exec('CREATE TABLE r (Id INTEGER)');
    $c->disconnect();
    throws(fn() => $c->getPdo(), DatabaseException::class);
    $c->reconnect();
    same(0, $c->toIntScalar('SELECT COUNT(*) FROM r'));
    $c->disconnect();
});

T::test('Schema: table(), hasColumn(), renameColumn(), dropColumn()', function () use ($connection) {
    $schema = new Schema($connection);
    $schema->table('miko_t_roles', fn(TableBuilder $t) => $t->string('Color', 20)->nullable());
    same(true, $schema->hasColumn('miko_t_roles', 'Color'));
    $schema->renameColumn('miko_t_roles', 'Color', 'Colour');
    same(true, $schema->hasColumn('miko_t_roles', 'Colour'));
    $schema->dropColumn('miko_t_roles', 'Colour');
    same(false, $schema->hasColumn('miko_t_roles', 'Colour'));
});

T::test('Migrator migrate / status / rollback', function () use ($connection) {
    $migrator = (new Migrator($connection, 'miko_t_migrations'))->add(new CreateMigTable());
    $result = $migrator->migrate();
    same(true, $result->success, implode('; ', $result->errors));
    same(['2026_01_01_000001'], $result->applied);
    same(1, $migrator->status()->appliedCount);
    same(true, (new Schema($connection))->hasTable('miko_t_mig'));
    same(true, $migrator->rollback()->success);
    same(false, (new Schema($connection))->hasTable('miko_t_mig'));
});

T::test('Seeder insert / truncate, Factory', function () use ($connection) {
    $seeder = new TestSeeder($connection);
    $before = Role::count();
    $seeder->run();
    same($before + 2, Role::count());
    $seeder->truncateTable('miko_t_roles');
    same(0, Role::count());
    same('guest', UserFactory::new()->make()->Role);
});

T::test('DbContext: quick start without Config::load()', function () use ($tmp) {
    TestContext::$file = $tmp . DIRECTORY_SEPARATOR . 'context.sqlite';
    $context = new TestContext();
    same(['ctx_items'], $context->ensureCreated());
    same([], $context->ensureCreated());
    CtxItem::create(['Name' => 'ctx']);
    same(1, $context->CtxItems->count());
    same(1, (int) (new \PDO('sqlite:' . TestContext::$file))->query('SELECT COUNT(*) FROM ctx_items')->fetchColumn());
    CtxItem::clearConnectionCache();
});

T::test('DbContext singular names', function () {
    ok(in_array('Address', DbContext::singularCandidates('Addresses'), true));
    ok(in_array('Status', DbContext::singularCandidates('Statuses'), true));
    same('Category', DbContext::singularCandidates('Categories')[0]);
    same('User', DbContext::singularCandidates('Users')[0]);
});

T::test('ORM ConnectionPool fails fast when exhausted', function () {
    ConnectionPool::setConfig(['driver' => 'sqlite', 'database' => ':memory:']);
    ConnectionPool::configure(0, 2);
    $a = ConnectionPool::acquire();
    ConnectionPool::acquire();
    $start = microtime(true);
    throws(fn() => ConnectionPool::acquire(), DatabaseException::class);
    ok(microtime(true) - $start < 1, 'no 30 second wait');
    ConnectionPool::release($a);
    same($a, ConnectionPool::acquire());
    ConnectionPool::clear();
});

T::test('DatabaseManager pins one connection and runs transactions', function () {
    $manager = DatabaseManager::fromConfig([
        'default' => 'sqlite',
        'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
    ]);
    same($manager->connection(), $manager->connection());
    same(5, $manager->transaction(fn() => 5));
    $manager->release();
});

T::test('Grammar: limits, quoting, operators per driver', function () {
    same(' ORDER BY (SELECT NULL) OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', Grammar::for('sqlsrv')->compileLimit(10, 20, false));
    same(' LIMIT 18446744073709551615 OFFSET 5', Grammar::for('mysql')->compileLimit(null, 5, false));
    same(' OFFSET 5', Grammar::for('pgsql')->compileLimit(null, 5, true));
    same('[dbo].[Users] [u]', Grammar::for('sqlsrv')->wrapTable('dbo.Users u'));
    same('COUNT(DISTINCT "t"."Id") AS "n"', Grammar::for('pgsql')->wrap('count(distinct t.Id) as n'));
    throws(fn() => Grammar::operator('= 1 OR'), DatabaseException::class);
});

T::test('TableBuilder DDL per driver', function () {
    $build = function (string $driver): string {
        $t = new TableBuilder('Items', $driver);
        $t->id();
        $t->string('Name', 50)->notNull();
        $t->boolean('On')->default(true);
        $t->json('Data')->nullable();
        return $t->build();
    };
    ok(str_contains($build('mysql'), 'AUTO_INCREMENT') && str_contains($build('mysql'), 'ENGINE=InnoDB'));
    ok(str_contains($build('pgsql'), 'SERIAL') && str_contains($build('pgsql'), 'JSONB') && str_contains($build('pgsql'), 'DEFAULT TRUE'));
    ok(str_contains($build('sqlsrv'), 'IDENTITY(1,1)') && str_contains($build('sqlsrv'), 'BIT'));
    ok(str_contains($build('sqlite'), 'INTEGER PRIMARY KEY AUTOINCREMENT'));
});

T::test('Config::env: .env file and real environment variables', function () use ($tmp) {
    $file = $tmp . DIRECTORY_SEPARATOR . 'test.env';
    file_put_contents($file, "MIKO_T_KEY=from-file # comment\nexport MIKO_T_QUOTED=\"a # b\"\n");
    Config::setEnvFile($file);
    same('from-file', Config::env('MIKO_T_KEY'));
    same('a # b', Config::env('MIKO_T_QUOTED'));
    putenv('MIKO_T_KEY=from-env');
    same('from-env', Config::env('MIKO_T_KEY'));
    putenv('MIKO_T_KEY');
    Config::setEnvFile(null);
});

T::test('Logger masks binding values', function () {
    same(['string(6)', 'int', null], Logger::maskBindings(['secret', 5, null]));
});

T::test('QueryCache caches null results', function () {
    $calls = 0;
    $fn = function () use (&$calls) { $calls++; return null; };
    \Miko\Database\Cache\QueryCache::remember('miko_t_null', $fn);
    \Miko\Database\Cache\QueryCache::remember('miko_t_null', $fn);
    same(1, $calls);
});

// ============================================================================
// 9. Security helpers
// ============================================================================

T::group('Security helpers');

T::test('FormCrypt: round trip, tamper detection, no fallback key', function () {
    $property = new \ReflectionProperty(FormCrypt::class, 'key');
    $property->setValue(null, null);
    throws(fn() => FormCrypt::encrypt('x'), \RuntimeException::class);

    FormCrypt::setKey(str_repeat('k', 32));
    $payload = FormCrypt::encrypt('merhaba');
    same('merhaba', FormCrypt::decrypt($payload));
    $tampered = substr($payload, 0, -2) . (substr($payload, -2, 1) === 'A' ? 'B' : 'A') . substr($payload, -1);
    same(false, FormCrypt::decrypt($tampered));
});

T::test('Crypto: authenticated encryption', function () {
    $key = str_repeat('z', 32);
    $payload = Crypto::encrypt('data', $key);
    same('data', Crypto::decrypt($payload, $key));
    throws(fn() => Crypto::decrypt($payload, str_repeat('y', 32)), \RuntimeException::class);
    same(7, strlen(Crypto::randomHex(7)));
});

T::test('Security::isValidTcNo accepts valid numbers', function () {
    same(true, Security::isValidTcNo('19090909018'));
    same(true, Security::isValidTcNo('10000000146'));
    same(false, Security::isValidTcNo('10000000147'));
});

T::test('Security::getClientIp trusts forwarding headers only from proxies', function () {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
    Security::setTrustedProxies([]);
    same('10.0.0.5', Security::getClientIp());
    Security::setTrustedProxies(['10.0.0.0/8']);
    same('1.2.3.4', Security::getClientIp());
    Security::setTrustedProxies([]);
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
});

T::test('Validator: "0" is a value, regex with commas', function () {
    same(true, (new Validator(['x' => '0']))->validate(['x' => 'in:0,1']));
    same(false, (new Validator(['x' => '5']))->validate(['x' => 'in:0,1']));
    same(true, (new Validator(['c' => '12']))->validate(['c' => 'regex:/^\d{1,3}$/']));
    same(true, Validator::isValidIBAN('TR330006100519786457841326'));
});

// ============================================================================
// Done
// ============================================================================

if ($driver !== 'sqlite' || ($config['database'] ?? '') !== ':memory:') {
    dropAll($connection);
}

// after a failure show the connection log (e.g. why an async connection could not be opened)
if (T::$fail > 0) {
    foreach (glob($tmp . DIRECTORY_SEPARATOR . 'Log' . DIRECTORY_SEPARATOR . '*connection*') ?: [] as $log) {
        echo "\n--- " . basename($log) . "\n" . implode("\n", array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -20)) . "\n";
    }
}

// remove the temporary folder (log files, test SQLite files)
gc_collect_cycles();
$files = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($files as $file) {
    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
}
@rmdir($tmp);

echo "\n" . T::$pass . ' passed, ' . T::$fail . " failed\n";
if (T::$fail > 0) {
    echo 'Failed: ' . implode(' | ', T::$failed) . "\n";
}

exit(T::$fail > 0 ? 1 : 0);
