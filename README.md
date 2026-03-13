# Getting Started

Miko ORM is a lightweight and fast database framework for PHP with fluent query syntax.

https://mikoorm.com <br>
https://mikoorm.com/document/index.html

---

## Installation

Include Miko ORM in your project by requiring the `autoload.php` file:

```php
require_once 'Model/Miko/autoload.php';
```

---

## Quick Start

### 1. Define Your Model

```php
use Miko\Database\ORM\Model;
use Miko\Database\ORM\Traits\HasTimestamps;

class User extends Model
{
    use HasTimestamps;
    
    protected static string $table = 'users';
    protected static string $primaryKey = 'Id';
    
    protected array $fillable = ['Name', 'Email', 'Password', 'Role'];
    protected array $hidden = ['Password'];
}
```

### 2. Configure Database Connection

```php
use Miko\Database\ORM\DbContext;

class AppDbContext extends DbContext
{
    protected function configure(): void
    {
        $this->setConnection('mysql', [
            'host' => 'localhost',
            'database' => 'your_database',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4'
        ]);
    }
}

// Initialize
$db = new AppDbContext();
```

### 3. Start Using

```php
// Create
$user = User::create([
    'Name' => 'John Doe',
    'Email' => 'john@example.com'
]);

// Read
$user = User::find(1);
$users = User::where('IsActive', true)->get();

// Update
$user->Name = 'John Updated';
$user->save();

// Delete
$user->delete();
```

---

## Supported Databases

<table style="width:100%; border-collapse: collapse;">
<thead>
<tr style="background-color: #1e3a5f; color: white;">
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Database</th>
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Driver</th>
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Default Port</th>
</tr>
</thead>
<tbody>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>MySQL / MariaDB</strong></td><td style="border: 1px solid #ddd; padding: 8px;"><code>mysql</code></td><td style="border: 1px solid #ddd; padding: 8px;">3306</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>PostgreSQL</strong></td><td style="border: 1px solid #ddd; padding: 8px;"><code>pgsql</code></td><td style="border: 1px solid #ddd; padding: 8px;">5432</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>SQLite</strong></td><td style="border: 1px solid #ddd; padding: 8px;"><code>sqlite</code></td><td style="border: 1px solid #ddd; padding: 8px;">-</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>SQL Server</strong></td><td style="border: 1px solid #ddd; padding: 8px;"><code>sqlsrv</code></td><td style="border: 1px solid #ddd; padding: 8px;">1433</td></tr>
</tbody>
</table>

---

## Project Structure

```
your-project/
├── Model/
│   └── Miko/              # Miko ORM Framework
│       ├── autoload.php   # Include this file
│       ├── Cache/
│       ├── Core/
│       ├── Database/
│       ├── Library/
│       └── Log/
├── App/
│   ├── Models/            # Your models
│   │   ├── User.php
│   │   └── Product.php
│   └── DbContext.php      # Your database context
└── .env                   # Environment configuration
```

---

## Key Features

<table style="width:100%; border-collapse: collapse;">
<thead>
<tr style="background-color: #1e3a5f; color: white;">
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Feature</th>
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Description</th>
</tr>
</thead>
<tbody>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Model-First Migration</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Auto-create tables from model definitions</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>Fluent Query Builder</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Clean and intuitive query API</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Relations</strong></td><td style="border: 1px solid #ddd; padding: 8px;">HasOne, HasMany, BelongsTo, BelongsToMany</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>Observers</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Model lifecycle events (creating, created, updating, etc.)</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Bulk Operations</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Efficient bulk insert, update, upsert, delete</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>JSON Columns</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Native JSON support with dot notation access</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Validation Attributes</strong></td><td style="border: 1px solid #ddd; padding: 8px;">PHP 8 attributes for model validation</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>Connection Pool</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Efficient database connection management</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Query Cache</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Cache query results with tags</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>Soft Deletes</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Soft delete support with restore capability</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Transactions</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Transaction support with savepoints</td></tr>
</tbody>
</table>

---

## Next Steps

<table style="width:100%; border-collapse: collapse;">
<thead>
<tr style="background-color: #1e3a5f; color: white;">
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Documentation</th>
<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Description</th>
</tr>
</thead>
<tbody>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Database Config</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Configure your database connection</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>ORM Model</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Learn about model definitions</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>Query Builder</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Build complex queries</td></tr>
<tr style="background-color: #f9f9f9;"><td style="border: 1px solid #ddd; padding: 8px;"><strong>Relations</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Define model relationships</td></tr>
<tr><td style="border: 1px solid #ddd; padding: 8px;"><strong>CRUD Examples</strong></td><td style="border: 1px solid #ddd; padding: 8px;">Complete CRUD examples</td></tr>
</tbody>
</table>

