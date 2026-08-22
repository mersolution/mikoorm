# MikoORM

[![Version](https://img.shields.io/badge/version-1.1.0-0ea5e9?style=for-the-badge)](https://mikoorm.com)
[![Docs](https://img.shields.io/badge/docs-mikoorm.com-0ea5e9?style=for-the-badge&logo=gitbook&logoColor=white)](https://mikoorm.com/docs/)
[![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://mikoorm.com)
[![License](https://img.shields.io/badge/license-MIT-84cc16?style=for-the-badge&logo=opensourceinitiative&logoColor=white)](https://github.com/mersolution/mikoorm)

> **Miko ORM** (`mikoorm`) **v1.1.0** is a **PHP ORM Framework** with a fluent query builder for modern PHP applications.

Explore the full documentation at [mikoorm.com](https://mikoorm.com).

---

## v1.1.0

- Grouped `where(function ($q) { ... })` / `orWhere(function)` on Model, MikoSet, ORM query builder and database / fluent query builders
- Named connection pool: `Miko\Database\ConnectionPool\ConnectionPool` + `DatabaseManager` (`pool.enabled`, `session.persistent`)

---

## Why mikoorm?

Modern PHP applications need a reliable, fast, and easy-to-use database layer. mikoorm gives you the balance between productivity and control:

* **PHP ORM Framework:** Models, `DbContext`, `MikoSet`, relations, observers, and code-first tables.
* **Fluent Query Builder:** Chainable methods for readable queries — including grouped `where(function ($q) { ... })` / `orWhere(function)`.
* **Multi-Database:** MySQL, MariaDB, PostgreSQL, SQLite and SQL Server through PDO drivers.
* **Minimal Setup:** Require `autoload.php`, override `getConfig()`, call `ensureCreated()`.
* **Built-in Pool & Cache:** Named connection pool via `DatabaseManager`, plus query cache and APCu.

---

## Core Features

* **ORM** — `Model` with CRUD, relations, soft deletes, timestamps and lifecycle events
* **Fluent Query Builder** — WHERE (including grouped closures), JOIN, ORDER, GROUP, LIMIT, aggregates and pagination
* **Multi-Database** — MySQL, MariaDB, PostgreSQL, SQLite, SQL Server
* **DbContext & MikoSet** — typed entity sets, `ensureCreated()`
* **Migrations** — code-first schema with `Schema`, `Migrator`, `TableBuilder`
* **Caching** — `QueryCache` and `ApcuCache`
* **Validation** — `Validator` and PHP 8 attributes
* **Transactions** — `Transaction` with savepoints
* **Bulk Operations** — bulk insert, update, upsert, delete
* **Raw Queries** — `DB::query()`, `execute()`, `scalar()`, `first()`
* **JSON Columns** — `JsonColumn` with dot-notation access
* **Observers & Events** — `Observer` and model lifecycle hooks
* **Global Scopes** — `addGlobalScope()` / `withoutGlobalScope()`
* **Connection Pool** — `Miko\Database\ConnectionPool\ConnectionPool` + `DatabaseManager`; optional `Miko\Database\ORM\ConnectionPool`
* **Cryptography** — `Crypto`, `Security`
* **Utilities** — `TextHelper`, `HttpClient`, `SoapClient`, `XmlClient`, `JwtHelper`, `JsonResponse`, `Cors`

---

## Quick Links

| Resource | Link |
| :--- | :--- |
| **Official Website** | [mikoorm.com](https://mikoorm.com) |
| **Full Documentation** | [mikoorm.com/docs](https://mikoorm.com/docs/) |
| **Getting Started** | [Installation Guide](https://mikoorm.com/docs/?doc=getting-started) |
| **GitHub** | [mersolution/mikoorm](https://github.com/mersolution/mikoorm) |

---

## Getting Started

### Installation

Include mikoorm by requiring `autoload.php`:

```php
require_once 'Miko/autoload.php';
```

`autoload.php` loads Core, DB, QueryBuilder, Logger, cache, library, drivers, migrations, ORM, seeders, factories, monitors, `Database/ConnectionPool` and `DatabaseManager`.

---

## Quick Start

### 1. Define a Model

```php
use Miko\Database\ORM\Model;
use Miko\Database\ORM\Traits\HasTimestamps;

class User extends Model
{
    use HasTimestamps;

    protected static string $table = 'users';
    protected string $primaryKey = 'Id';

    protected array $fillable = ['Name', 'Email', 'Password', 'Role'];
    protected array $hidden = ['Password'];
}
```

### 2. Create a DbContext

`configure()` / `setConnection()` do not exist on `DbContext`. Override `getConfig()`. Public `MikoSet` properties are discovered automatically (`Users` → `App\Models\User` or `User`).

```php
use Miko\Database\ORM\DbContext;
use Miko\Database\ORM\MikoSet;
use Miko\Core\Config;

class AppDbContext extends DbContext
{
    public MikoSet $Users;

    protected function getConfig(): array
    {
        return [
            'host' => Config::env('DB_HOST_LOCAL', 'localhost'),
            'port' => (int) Config::env('DB_PORT', 3306),
            'database' => Config::env('DB_DATABASE_LOCAL', 'your_database'),
            'username' => Config::env('DB_USERNAME_LOCAL', 'root'),
            'password' => Config::env('DB_PASSWORD_LOCAL', ''),
            'charset' => 'utf8mb4',
        ];
    }
}

$db = new AppDbContext();
$db->ensureCreated();
```

### 3. CRUD

```php
$user = User::create([
    'Name' => 'John Doe',
    'Email' => 'john@example.com',
]);

$user = User::find(1);
$users = User::where('IsActive', true)->get();

$user->Name = 'John Updated';
$user->save();

$user->delete();
```

### 4. Fluent Query Builder

```php
$users = User::where('IsActive', true)
    ->orderBy('Name')
    ->take(10)
    ->get();

$staff = User::where('IsActive', true)
    ->where(function ($q) {
        $q->where('Role', 'admin')
          ->orWhere('Role', 'moderator');
    })
    ->get();
// SQL: WHERE IsActive = ? AND (Role = ? OR Role = ?)

$page = User::where('IsActive', true)->paginate(20, 1);
echo $page['current_page'];
echo $page['last_page'];
echo $page['total'];

$count = User::count();
$exists = User::where('Email', 'john@example.com')->exists();
$names = User::query()->pluck('Name');
$sql = User::where('IsActive', true)->take(5)->toSql();
```

---

## Supported Databases

<table style="width:100%; border-collapse: collapse;">
<thead>
<tr style="background-color: #0d1b2a; color: white;">
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Database</th>
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Driver</th>
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Default Port</th>
</tr>
</thead>
<tbody>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>MySQL / MariaDB</strong></td><td style="border: 1px solid #ddd; padding: 8px;"><code>mysql</code></td><td style="border: 1px solid #ddd; padding: 8px;">3306</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>PostgreSQL</strong></td><td style="border: 1px solid #ddd; padding: 8px;"><code>pgsql</code></td><td style="border: 1px solid #ddd; padding: 8px;">5432</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>SQLite</strong></td><td style="border: 1px solid #ddd; padding: 8px;"><code>sqlite</code></td><td style="border: 1px solid #ddd; padding: 8px;">—</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>SQL Server</strong></td><td style="border: 1px solid #ddd; padding: 8px;"><code>sqlsrv</code></td><td style="border: 1px solid #ddd; padding: 8px;">1433</td></tr>
</tbody>
</table>

---

## Project Structure

```
your-project/
├── Miko/                  # mikoorm library (this repo)
│   └── autoload.php
├── Log/                   # Logger channel files (created at runtime)
├── App/
│   ├── Models/
│   │   ├── User.php
│   │   └── Product.php
│   └── DbContext.php
└── .env
```

Library layout:

```
Miko/
├── autoload.php
├── Cache/                 # ApcuCache
├── Core/                  # Config, DatabaseConfig, Http, Validation, Helpers
├── Database/              # DB, drivers, QueryBuilder, ConnectionPool, DatabaseManager
│   └── ORM/               # Model, QueryBuilder, DbContext, MikoSet
├── Library/               # Crypto, Security, TextHelper
├── Log/                   # Logger
└── Security/              # FormCrypt
```

---

## Key Features

<table style="width:100%; border-collapse: collapse;">
<thead>
<tr style="background-color: #0d1b2a; color: white;">
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Feature</th>
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Description</th>
</tr>
</thead>
<tbody>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Model-First Migration</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Auto-create tables from model definitions</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>Fluent Query Builder</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Chainable queries, including grouped <code>where(function ($q) { ... })</code> / <code>orWhere(function)</code></td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Relations</strong></td><td style="border: 1px solid #ddd; padding: 8px;">HasOne, HasMany, BelongsTo, BelongsToMany</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>Observers</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Model lifecycle events (creating, created, updating, …)</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Bulk Operations</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Bulk insert, update, upsert, delete</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>JSON Columns</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Native JSON support with dot-notation access</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Validation Attributes</strong></td><td style="border: 1px solid #ddd; padding: 8px;">PHP 8 attributes for model validation</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>Connection Pool</strong></td><td style="border: 1px solid #ddd; padding: 8px;"><code>ConnectionPool</code> + <code>DatabaseManager</code> for named connections. Set <code>pool.enabled</code>. Use <code>session.persistent</code> so PHP-FPM workers reuse PDO handles</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Query Cache</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Cache query results with tags</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>Soft Deletes</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Soft delete with restore / force delete</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Transactions</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Transactions with savepoints</td></tr>
</tbody>
</table>

---

## Compatibility

<table style="width:100%; border-collapse: collapse;">
<thead>
<tr style="background-color: #0d1b2a; color: white;">
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Runtime</th>
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Minimum</th>
</tr>
</thead>
<tbody>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>PHP</strong></td><td style="border: 1px solid #ddd; padding: 8px;">8.0+</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>PDO</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Required (mysql, pgsql, sqlite, sqlsrv as needed)</td></tr>
</tbody>
</table>

---

## Next Steps

<table style="width:100%; border-collapse: collapse;">
<thead>
<tr style="background-color: #0d1b2a; color: white;">
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Documentation</th>
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Description</th>
</tr>
</thead>
<tbody>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><a href="https://mikoorm.com/docs/?doc=database-config"><strong>Database Config</strong></a></td><td style="border: 1px solid #ddd; padding: 8px;">Configure your database connection</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><a href="https://mikoorm.com/docs/?doc=orm-model"><strong>ORM Model</strong></a></td><td style="border: 1px solid #ddd; padding: 8px;">Model definitions</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><a href="https://mikoorm.com/docs/?doc=query-builder"><strong>Query Builder</strong></a></td><td style="border: 1px solid #ddd; padding: 8px;">Chainable queries and grouped where</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><a href="https://mikoorm.com/docs/?doc=relations"><strong>Relations</strong></a></td><td style="border: 1px solid #ddd; padding: 8px;">HasOne, HasMany, BelongsTo, BelongsToMany</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><a href="https://mikoorm.com/docs/?doc=dbcontext"><strong>DbContext</strong></a></td><td style="border: 1px solid #ddd; padding: 8px;">DbContext and MikoSet</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><a href="https://mikoorm.com/docs/?doc=connections"><strong>Connection &amp; Pool</strong></a></td><td style="border: 1px solid #ddd; padding: 8px;">Named connections and pooling</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><a href="https://mikoorm.com/docs/?doc=example-crud"><strong>CRUD Examples</strong></a></td><td style="border: 1px solid #ddd; padding: 8px;">End-to-end CRUD</td></tr>
</tbody>
</table>

---

*mikoorm v1.1.0 — a PHP ORM Framework by [Mersolution Technology](https://mersolution.com)*
