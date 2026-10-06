# MikoORM

[![Version](https://img.shields.io/badge/version-2.1.0-0ea5e9?style=for-the-badge)](https://mikoorm.com)
[![Docs](https://img.shields.io/badge/docs-mikoorm.com-0ea5e9?style=for-the-badge&logo=gitbook&logoColor=white)](https://mikoorm.com/docs/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://mikoorm.com)
[![License](https://img.shields.io/badge/license-MIT-84cc16?style=for-the-badge&logo=opensourceinitiative&logoColor=white)](LICENSE)

> **Miko ORM** (`mikoorm`) **v2.1.0** is a **PHP ORM Framework** with a fluent, injection-safe query builder for modern PHP applications.

Explore the full documentation at [mikoorm.com](https://mikoorm.com).

---

## v2.1.0

- **Async queries**: every read has an `...Async()` twin (`getAsync()`, `countAsync()`, `paginateAsync()`, `User::findAsync(5)`, `DB::queryAsync()` ...) that returns a `Future`. On MySQL / MariaDB, PostgreSQL and SQL Server independent queries run **in parallel** on extra connections: three 1 s queries take about 1 s. Normal methods are unchanged; use async only where it helps.
- **No extension to install**: MikoORM has its own clients for the MySQL, PostgreSQL and SQL Server (TDS) protocols, written in PHP. They are used when `mysqli` / `pgsql` are missing - and always for SQL Server, whose `pdo_sqlsrv` has no async API. Same results as PDO, same speed.
- **One wait for everything**: `Async::all([...])` waits for queries and HTTP requests (`$http->getAsync()`) together.
- **SQL Server tested live** (SQL Server 2022): unique keys on nullable columns behave like on the other databases, `upsert()` via `MERGE`, int / float columns come back as numbers, parallel async queries, `encrypt` / `trust_server_certificate` options for ODBC Driver 18.
- Test suite: 101 tests pass on SQLite, MariaDB 12.2, MySQL 8.0, PostgreSQL 18.6 and SQL Server 2022.

---

## v2.0.0

2.0 is a correctness and security release. Highlights:

- **Relations work as expected**: `hasOne`, `hasMany`, `belongsTo`, `belongsToMany` return only the parent's rows; eager loading supports nesting (`posts.comments`) and constraints
- **Injection-safe builders**: every column, table and operator is validated and quoted per driver; values are always bound
- **Mass assignment protection**: `new Model($data)` and `create()` respect `$fillable`; the primary key is never mass assignable
- **Global scopes / soft deletes cannot be bypassed** with `orWhere()`; `withTrashed()`, `onlyTrashed()`, `withoutGlobalScope()` work
- **One shared connection** for models, `Transaction`, `DB` and builders; nested transactions use savepoints
- **Real multi-database SQL** for MySQL/MariaDB, PostgreSQL, SQLite and SQL Server (LIMIT/OFFSET, date functions, DDL, upsert)
- **Faster**: `exists()` uses `LIMIT 1`, date filters use index friendly ranges, bulk inserts are chunked to the driver parameter limit, one `INIT_COMMAND` per MySQL connection
- **Authenticated encryption** (`FormCrypt`, `Crypto`), no built-in fallback key
- **HTTP clients**: keep-alive `HttpClient` with parallel `pool()`, retries and downloads; `XmlClient` and `SoapClient` with real timeouts and safe error handling
- **Test suites**: `php tests/run.php` (ORM), `php tests/http.php` (HTTP clients)

See [CHANGELOG.md](CHANGELOG.md) for every change and the **upgrade notes** for breaking changes.

---

## Why mikoorm?

* **PHP ORM Framework:** Models, `DbContext`, `MikoSet`, relations, observers, and code-first tables.
* **Fluent Query Builder:** Chainable methods for readable queries, including grouped `where(fn($q) => ...)`.
* **Multi-Database:** MySQL, MariaDB, PostgreSQL, SQLite and SQL Server through PDO drivers.
* **Minimal Setup:** Require `autoload.php`, configure a connection, call `ensureCreated()` once.
* **Built-in Pool & Cache:** Named connection pool via `DatabaseManager`, plus query cache and APCu.

---

## Core Features

* **ORM** - `Model` with CRUD, relations, soft deletes, timestamps, casts and lifecycle events
* **Fluent Query Builder** - WHERE (grouped closures, dates, LIKE), JOIN, ORDER, GROUP, HAVING, LIMIT, aggregates, pagination, chunking, cursors
* **Multi-Database** - MySQL, MariaDB, PostgreSQL, SQLite, SQL Server
* **DbContext & MikoSet** - typed entity sets, `ensureCreated()`
* **Migrations** - code-first schema with `Schema`, `Migrator`, `TableBuilder` (driver specific DDL)
* **Caching** - `QueryCache` and `ApcuCache`
* **Validation** - `Validator` and PHP 8 attributes (`ModelValidator`)
* **Transactions** - `Transaction::run()` with automatic savepoints for nested calls
* **Bulk Operations** - bulk insert, update, upsert, delete
* **Raw Queries** - `DB::query()`, `execute()`, `scalar()`, `first()`, `DB::table()`
* **JSON Columns** - `JsonColumnTrait` with dot-notation access, `json` casts
* **Observers & Events** - `Model::observe()` and model lifecycle hooks
* **Global Scopes** - `addGlobalScope()` / `withoutGlobalScope()`
* **Connection Pool** - `ConnectionPool` + `DatabaseManager`
* **Cryptography** - `Crypto`, `FormCrypt`, `Security`
* **Utilities** - `TextHelper`, `HttpClient`, `SoapClient`, `XmlClient`, `JwtHelper`, `JsonResponse`, `Cors`

---

## Getting Started

### Requirements

| Runtime | Minimum |
| :--- | :--- |
| **PHP** | 8.1+ |
| **PDO** | pdo_mysql, pdo_pgsql, pdo_sqlite or pdo_sqlsrv |

### Installation

```php
require_once 'Miko/autoload.php';
```

Optional: send PHP errors and uncaught exceptions to `Log/application.log`:

```php
define('MIKO_REGISTER_ERROR_HANDLERS', true);
require_once 'Miko/autoload.php';
```

### Configuration

`Miko/Config/Database.php` reads `.env` (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`; the older `DB_*_LOCAL` names still work). The `.env` file is looked up in this order:

1. `MIKO_ENV_FILE` / `Config::setEnvFile()`
2. `<project>/.env`
3. `<project>/Env/Config.env`
4. `<project>/Miko/.env`

Real environment variables (Docker, `SetEnv`) win over the `.env` file.

---

## Quick Start

### 1. Define a Model

```php
use Miko\Database\ORM\Model;
use Miko\Database\ORM\Relations\HasMany;
use Miko\Database\ORM\Traits\HasTimestamps;
use Miko\Database\Migration\TableBuilder;

class User extends Model
{
    use HasTimestamps;                      // CreatedDate / UpdatedDate (opt-in)

    protected static string $table = 'users';
    protected string $primaryKey = 'Id';    // default

    protected array $fillable = ['Name', 'Email'];
    protected array $hidden = ['Password'];
    protected array $casts = ['Settings' => 'json', 'Active' => 'bool'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);  // Post.UserId -> User.Id
    }

    protected static function defineSchema(TableBuilder $table): void
    {
        $table->id();
        $table->string('Name', 100);
        $table->string('Email', 150)->unique();
        $table->string('Password')->nullable();
        $table->json('Settings')->nullable();
        $table->boolean('Active')->default(true);
        $table->timestamps();
    }
}
```

### 2. Create a DbContext

```php
use Miko\Database\ORM\DbContext;
use Miko\Database\ORM\MikoSet;

class AppDbContext extends DbContext
{
    public MikoSet $Users;   // -> App\Models\User, Models\User or User

    // Optional - default is the "default" connection of Config/Database.php
    protected function getConfig(): array
    {
        return [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'database' => 'app',
            'username' => 'root',
            'password' => '',
        ];
    }
}

$db = new AppDbContext();
$db->ensureCreated();   // setup / deploy step: creates the database (MySQL) and missing tables
```

Models of the context use the context connection automatically.

### 3. CRUD

```php
$user = User::create(['Name' => 'John Doe', 'Email' => 'john@example.com']);

$user = User::find(1);
$users = User::where('Active', true)->get();

$user->update(['Name' => 'John Updated']);   // only this row

$user->Email = 'new@example.com';
$user->save();

$user->delete();

User::where('Active', false)->delete();       // mass delete goes through a query
```

### 4. Fluent Query Builder

```php
$staff = User::where('Active', true)
    ->where(fn($q) => $q->where('Role', 'admin')->orWhere('Role', 'moderator'))
    ->orderBy('Name')
    ->take(10)
    ->get();
// WHERE `Active` = ? AND (`Role` = ? OR `Role` = ?)

$page   = User::where('Active', true)->paginate(20, 1);   // data, total, last_page, ...
$count  = User::count();
$exists = User::where('Email', 'john@example.com')->exists();
$names  = User::query()->pluck('Name');
$march  = Post::query()->whereDate('Published', '2026-03-15')->get();  // index friendly range
$search = User::query()->whereLike('Name', '50%_off')->get();         // % and _ are literal

// Expressions need the *Raw methods (values still bound)
$rows = DB::table('Orders')->selectRaw('SUM(Total) AS Revenue')->whereRaw('Total > ?', [100])->get();
```

### 5. Relations

```php
$user->posts;                                   // lazy
User::with('posts.comments')->get();            // eager, one query per level
User::with(['posts' => fn($q) => $q->where('Published', '>', '2026-01-01')])->get();
$user->posts()->where('Title', 'like', 'A%')->count();
$user->posts()->create(['Title' => 'Hello']);   // sets UserId

$user->roles()->attach([1, 2]);                 // belongsToMany
$user->roles()->sync([2, 3]);
```

### 6. Soft deletes and scopes

```php
use Miko\Database\ORM\Traits\SoftDeletes;

class Post extends Model { use SoftDeletes; }   // DeletedAt column

$post->delete();          // sets DeletedAt
$post->restore();
$post->forceDelete();

Post::withTrashed()->get();
Post::onlyTrashed()->count();
```

### 7. Transactions

```php
use Miko\Database\ORM\Transaction;

Transaction::run(function () {
    $user = User::create(['Name' => 'Test']);
    Order::create(['UserId' => $user->Id]);
});   // any exception or error rolls back; nested run() calls use savepoints
```

### 8. HTTP clients

```php
use Miko\Core\Http\HttpClient;
use Miko\Core\Http\XmlClient;
use Miko\Core\Http\SoapClient;

$api = HttpClient::create('https://api.example.com', ['timeout' => 10, 'bearer_token' => $token])
    ->retry(3);                                   // connection errors, 429, 5xx; POST only when never sent

$user  = $api->get('/users/5')->throwIfFailed()->json();
$saved = $api->post('/orders', ['ProductId' => 7, 'Qty' => 2]);   // arrays are sent as JSON
if ($saved->failed()) { error_log('order failed: ' . ($saved->error ?: $saved->status())); }

// parallel: three calls of 1 s take about 1 s; failures never throw, check each response
$results = $api->pool([
    'customer' => '/customers/5',
    'orders'   => ['url' => '/orders', 'query' => ['customer' => 5]],
    'invoice'  => ['method' => 'POST', 'url' => '/invoices', 'json' => ['OrderId' => 9]],
], concurrency: 10);

$api->download('/exports/2026.csv', __DIR__ . '/2026.csv');      // streamed to disk

$rates = XmlClient::create()->get('https://www.tcmb.gov.tr/kurlar/today.xml');
$usd   = $rates->value('//Currency[@Kod="USD"]/ForexBuying');

$soap = SoapClient::create('https://service.example.com/api?wsdl', ['timeout' => 20]);
$res  = $soap->call('GetStatus', ['Id' => 5]);   // SoapResponse, never an uncaught SoapFault
```

One `HttpClient` keeps its connections alive between calls and shares the DNS / TLS session cache, so reuse the instance instead of creating a client per request. Only `http://` and `https://` URLs are accepted (also after redirects). `SoapClient` needs `extension=soap` and caches the WSDL (`cache_wsdl => WSDL_CACHE_NONE` while you develop the service). Every request method has an async twin (`getAsync()`, `postAsync()`, `downloadAsync()` ...) that returns a `Future<HttpResponse>`.

### 9. Async queries

Normal methods stay as they are; add `Async` where independent queries can run together.

```php
use Miko\Core\Async\Async;

// sent at once, waited together: about as long as the slowest one
[$users, $orderCount, $revenue, $rates] = Async::all([
    User::where('Active', 1)->with('roles')->getAsync(),
    Order::query()->countAsync(),
    Order::query()->whereYear('Date', 2026)->sumAsync('Total'),
    $http->getAsync('https://api.example.com/rates'),        // HTTP requests join the same wait
]);

$page   = Post::query()->latest()->paginateAsync(20, 1)->await();   // count and page in parallel
$future = DB::queryAsync('SELECT ...', [$id]);                       // start now ...
$rows   = $future->await();                                           // ... read later

// one failing query does not stop the others
$results = Async::allSettled(['a' => $q1->getAsync(), 'b' => $q2->countAsync()]);
// ['a' => ['status' => 'fulfilled', 'value' => [...]], 'b' => ['status' => 'rejected', 'reason' => QueryException]]
```

| Database | Async queries | Client |
| :--- | :--- | :--- |
| MySQL / MariaDB | parallel | `mysqli` when loaded, otherwise the built-in client |
| PostgreSQL | parallel | `pgsql` when loaded, otherwise the built-in client |
| SQL Server | parallel | built-in TDS client (`pdo_sqlsrv` has no async API) |
| SQLite | one by one on the main connection, same results | - (no server to talk to) |

- Each parallel query uses its own connection (`max_connections`, default 4, per database connection); they are opened on first use and reused for the request.
- Inside a transaction async queries run on the transaction's connection, so they see its uncommitted changes.
- `timeout` (seconds) cancels a query on the server (`KILL QUERY` / PostgreSQL cancel request / TDS attention) and fails it with `AsyncTimeoutException`.
- Results have the same types as the normal methods; errors are the same `QueryException` (same codes / SQLSTATE).
- If no extra connection can be opened (connection limit, unsupported login method), the queries run on the main connection and a warning goes to the connection log.
- Settings: `Config/Database.php` → `'async' => ['enabled' => true, 'max_connections' => 4, 'timeout' => 0, 'mysql_driver' => 'auto', 'pgsql_driver' => 'auto', 'sqlsrv_driver' => 'auto']`, or `Async::configure([...])`. `'extension'` / `'php'` force one client (`'sqlsrv_driver' => 'extension'` runs SQL Server queries one by one). `DB::supportsParallelQueries()` tells whether the current connection runs them in parallel.

**Built-in clients.** They talk to the server over a socket in plain PHP (only `openssl` for encrypted connections and `mbstring` for SQL Server):

- **MySQL / MariaDB** - `mysql_native_password`, `caching_sha2_password` (MySQL 8 default; RSA key exchange without SSL), `sha256_password`; SSL from the usual `PDO::MYSQL_ATTR_SSL_*` options; `unix_socket`; charsets utf8mb4 / utf8 / latin1 / ascii (others use `mysqli` or run one by one).
- **PostgreSQL** - `scram-sha-256`, `md5`, `password`; `sslmode` (`prefer` by default, like libpq; `require`, `verify-ca`, `verify-full`), `sslrootcert`, `sslcert`, `sslkey` (the same keys also go to the PDO connection); Unix sockets (`'host' => '/var/run/postgresql'`).
- **SQL Server** - SQL Server logins (not Windows authentication); `encrypt` / `trust_server_certificate` behave like the ODBC driver (without `encrypt` only the login is encrypted); `host\instance` and `host,port`; Azure redirects. `sql_variant` and CLR types (`geography`, `hierarchyid`) are read again on the main connection.
- Kerberos / GSSAPI / Windows logins are not supported by the built-in clients: such connections run the async queries one by one.

---

## Supported Databases

| Database | Driver | Default Port | Status | Async queries |
| :--- | :--- | :--- | :--- | :--- |
| **MySQL / MariaDB** | `mysql` | 3306 | supported, tested | parallel |
| **PostgreSQL** | `pgsql` | 5432 | supported, tested | parallel |
| **SQLite** | `sqlite` | - | supported, tested (test suite default) | one by one |
| **SQL Server** | `sqlsrv` | 1433 | supported, tested | parallel |

The test suite (101 tests) passes on SQLite 3.39, MariaDB 12.2, MySQL 8.0, PostgreSQL 18.6 and SQL Server 2022 (pdo_sqlsrv 5.12, ODBC Driver 17). Run it against your own server before upgrading; the database must exist and the suite drops its `miko_t_*` tables at the end:

```bash
php tests/run.php
MIKO_TEST_DRIVER=mysql MIKO_TEST_HOST=127.0.0.1 MIKO_TEST_PORT=3306 MIKO_TEST_DATABASE=miko_test \
MIKO_TEST_USERNAME=root MIKO_TEST_PASSWORD=secret php tests/run.php
MIKO_TEST_DRIVER=pgsql MIKO_TEST_HOST=127.0.0.1 MIKO_TEST_PORT=5432 MIKO_TEST_DATABASE=miko_test \
MIKO_TEST_USERNAME=postgres MIKO_TEST_PASSWORD=secret php tests/run.php
MIKO_TEST_DRIVER=sqlsrv MIKO_TEST_HOST=127.0.0.1 MIKO_TEST_PORT=1433 MIKO_TEST_DATABASE=miko_test \
MIKO_TEST_USERNAME=sa MIKO_TEST_PASSWORD=secret php tests/run.php
php -d extension=soap tests/http.php       # HTTP clients, async HTTP (starts local php -S servers)
```

XAMPP ships `pdo_pgsql` and `soap` disabled: enable them in `php.ini`, or add `-d extension=pdo_pgsql` to the command (`pgsql` and `mysqli` are optional: without them async queries use the built-in clients). SQL Server needs the Microsoft `pdo_sqlsrv` extension and an ODBC driver; ODBC Driver 18 encrypts by default, so a server with a self-signed certificate needs `'trust_server_certificate' => true` (or `'encrypt' => false`) in the connection config.

**SQL Server and unique keys:** SQL Server allows only one NULL in a UNIQUE constraint. `unique()` on a nullable column therefore creates a filtered unique index (`WHERE col IS NOT NULL`), so several rows without a value are allowed, as on the other databases.

**PostgreSQL and raw SQL:** the builders quote every name, so `PascalCase` columns work everywhere. In `selectRaw()`, `whereRaw()`, `RawQuery` and `DB::select()` the SQL is sent as written, and PostgreSQL folds unquoted names to lower case (`Views` becomes `views`). Quote mixed-case names yourself (`"Views"`) or use `$connection->getGrammar()->wrap('Views')`. `json` columns are `jsonb` on PostgreSQL, which normalises the stored text (`{"a": 1}`); read them through `$casts`.

---

## Project Structure

```
your-project/
├── Miko/                  # mikoorm library (this repo)
│   └── autoload.php
├── Log/                   # Logger files (created at runtime, web access denied)
├── App/
│   ├── Models/
│   └── DbContext.php
└── .env
```

Library layout:

```
Miko/
├── autoload.php
├── Cache/                 # ApcuCache
├── Config/                # Database.php
├── Core/                  # Config, DatabaseConfig, Http, Validation, Helpers
├── Database/              # Connection, Grammar, DB, query builders, pool, migrations
│   └── ORM/               # Model, QueryBuilder, Relations, DbContext, MikoSet
├── Library/               # Crypto, Security, TextHelper
├── Log/                   # Logger
├── Security/              # FormCrypt
└── tests/                 # php tests/run.php, php -d extension=soap tests/http.php
```

---

## Upgrading from 1.x

The most important breaking changes (full list in [CHANGELOG.md](CHANGELOG.md)):

| 1.x | 2.0 |
| :--- | :--- |
| PHP 8.0 | PHP 8.1+ |
| `$primaryKey = 'id'` | `$primaryKey = 'Id'` |
| Timestamps always on | `use HasTimestamps;` to enable |
| `create()` ignored `$fillable` | `$fillable` / `$guarded` enforced, primary key guarded |
| Relation keys guessed as `user_id` | `UserId` (`user_id` when the primary key is `id`) |
| `select('CONCAT(a,b)')`, `where('DATE(x)', ...)` | use `selectRaw()` / `whereRaw()` / `whereDate()` |
| `User::fresh()` / `User::refresh()` (migration) | `User::recreateTable()` |
| `Query::delete()` hard-deleted soft-delete models | soft deletes; `forceDelete()` removes rows |
| `whereLike()` passed `%`/`_` through | escaped; `whereLike($col, $pattern, false)` for raw patterns |
| `latest()` used `CreateDate` | `CreatedDate` (or the model's created-at column) |
| autoload registered error handlers and `../Cargo/bootstrap.php` | opt-in `MIKO_REGISTER_ERROR_HANDLERS`, no Cargo include |
| `FormCrypt` fallback key, no MAC | `ENCRYPTION_KEY` required, `v2:` payloads; `decryptLegacy()` for old data |
| `Security::getClientIp()` trusted `X-Forwarded-For` | only from `TRUSTED_PROXIES` |
| `HttpClient::get($url, $headers, $async)`, `request(..., $async, $multipart)` | `$async` removed (use `pool()`); `get($url, $headers, $query)`, `request($method, $url, $body, $headers, $options)` |
| `HttpClient::post($url)` sent `[]` | sends an empty body; `post/put/patch` default `$data = null` |
| `HttpClient::multi()` crashed (`TypeError`) | `pool()` (and `multi()`) run in parallel; each response has its own `errno` / `error` |
| `SoapClient` downloaded the WSDL on every request | `cache_wsdl` = `WSDL_CACHE_BOTH` by default |
| SQL Server returned int columns as strings | int / float columns come back as int / float (2.1, like the other drivers); bigint, decimal and money stay strings |

---

## Quick Links

| Resource | Link |
| :--- | :--- |
| **Official Website** | [mikoorm.com](https://mikoorm.com) |
| **Full Documentation** | [mikoorm.com/docs](https://mikoorm.com/docs/) |
| **Getting Started** | [Installation Guide](https://mikoorm.com/docs/?doc=getting-started) |
| **GitHub** | [mersolution/mikoorm](https://github.com/mersolution/mikoorm) |

---

*mikoorm v2.1.0 - a PHP ORM Framework by [Mersolution Technology](https://mersolution.com)*
