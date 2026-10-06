# Changelog

## 2.1.0 - 2026-10-06

Async queries and live-tested SQL Server. Normal (sync) methods are unchanged.

### Added

- **Async queries.** Every read has an `...Async()` version that returns a `Miko\Core\Async\Future`:
  - ORM builder: `getAsync()`, `firstAsync()`, `findAsync()`, `findManyAsync()`, `countAsync()`, `existsAsync()`, `sumAsync()`, `avgAsync()`, `minAsync()`, `maxAsync()`, `valueAsync()`, `pluckAsync()`, `paginateAsync()` (count and page in parallel); `Model::allAsync()`, and `User::countAsync()` style static calls.
  - Relations: `$user->posts()->getAsync()` / `countAsync()` ..., `belongsToMany` with pivot data; eager loading inside `getAsync()` loads the relations of one level in parallel.
  - Table builder (`DB::table()`, `FluentQueryBuilder` with unions): `getAsync()`, `firstAsync()`, `findAsync()`, `countAsync()`, `existsAsync()`, aggregates, `valueAsync()`, `pluckAsync()`, `paginateAsync()`.
  - `DB::queryAsync()`, `DB::firstAsync()`, `DB::scalarAsync()`, `RawQuery::getAsync()` / `firstAsync()` / `valueAsync()`, `DB::supportsParallelQueries()`.
- **Parallel execution** on MySQL / MariaDB, PostgreSQL and SQL Server on extra connections: at most `max_connections` (default 4) per connection, opened on demand and reused; values are escaped with the connection charset (MySQL) or sent as real parameters (PostgreSQL, SQL Server `sp_executesql`); result types and `QueryException` codes / SQLSTATE are the same as the sync methods. `timeout` cancels a running query on the server (`KILL QUERY` / PostgreSQL cancel request / TDS attention) with `AsyncTimeoutException`.
- **Built-in clients, no PHP extension needed** - protocol implementations in PHP, used when `mysqli` / `pgsql` are not loaded and always for SQL Server (`pdo_sqlsrv` has no async API):
  - `MyWireLink` / `MyWireDriver` (MySQL / MariaDB): `mysql_native_password`, `caching_sha2_password` (fast and full authentication, RSA without SSL), `sha256_password`, auth switch; SSL from the `PDO::MYSQL_ATTR_SSL_*` options; result types like mysqlnd (ZEROFILL, BIT, unsigned BIGINT); `NO_BACKSLASH_ESCAPES` honoured; extra result sets of `CALL` are read and dropped.
  - `PgWireLink` / `PgWireDriver` (PostgreSQL, protocol 3.0): `scram-sha-256` (server signature checked) / `md5` / `password`, `sslmode` (`prefer` default, `require`, `verify-ca`, `verify-full`) with `sslrootcert` / `sslcert` / `sslkey`, Unix sockets, cancel requests.
  - `TdsLink` / `TdsDriver` (SQL Server, TDS 7.4): SQL Server login, login-only or full encryption like the ODBC driver (`encrypt`, `trust_server_certificate`), named instances (SQL Browser), Azure routing, attention; values formatted like `pdo_sqlsrv` (bigint and decimal / money as strings, ODBC date and time text, code page conversion of `varchar`); `sql_variant` and CLR types are read again on the main connection.
  - Settings `mysql_driver` / `pgsql_driver` / `sqlsrv_driver` (`auto` / `extension` / `php`).
- PostgreSQL `sslmode`, `sslrootcert`, `sslcert`, `sslkey` connection keys (PDO DSN and async connections).
- SQLite, configs no client supports (Windows / Kerberos logins), an open transaction (so uncommitted rows are visible) or `enabled => false` run the same methods on the main connection; so do queries whose extra connection cannot be opened (logged as a warning).
- `Async::all()` (keys kept, throws the first error after all finished), `Async::allSettled()`, `Future::then()` / `catch()` / `finally()`, `Future::all()`, `Deferred`; a small event loop drives database and HTTP work together.
- `HttpClient::getAsync()`, `postAsync()`, `putAsync()`, `patchAsync()`, `deleteAsync()`, `downloadAsync()`, `requestAsync()` (concurrency limit and retries apply); they can be awaited together with queries.
- `Config/Database.php` `async` section (`enabled`, `max_connections`, `timeout`, `mysql_driver`, `pgsql_driver`, `sqlsrv_driver`; `DB_ASYNC_*` env keys) and `Async::configure()`.
- `tests/run.php` prints the connection log after a failed run.
- Query log entries have an `async` flag; slow async queries are marked `[async]` in the log file.
- SQL Server: `upsert()` with `MERGE ... WITH (HOLDLOCK)`; `encrypt` and `trust_server_certificate` connection options (ODBC Driver 18).
- `QueryException::fromDriver()`, `Grammar::hasOrderBy()`, `Grammar::derivedTable()`, `ColumnBuilder::isNullable()`.

### Fixed

- Repeated named parameters (`:n ... :n`) were also replaced inside string literals (`':n'`), which broke the statement ("column index out of range").
- SQL Server: `unique()` on a nullable column allowed only one NULL row; it is now a filtered unique index (`WHERE col IS NOT NULL`), the same behaviour as MySQL, PostgreSQL and SQLite.
- SQL Server: `Connection::paginate()` / `Paginator::rawPaginate()` failed for SQL with ORDER BY (not allowed in a derived table); an ORDER BY inside `OVER (...)` was taken for the statement's ORDER BY.

### Changed

- SQL Server returns int / float columns as PHP int / float (`PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE`), like the other drivers; bigint, decimal and money stay strings (pdo_sqlsrv).
- Test suite: 101 tests, passing on SQLite 3.39, MariaDB 12.2 and MySQL 8.0 (with and without `mysqli`), PostgreSQL 18.6 (with and without `pgsql`) and SQL Server 2022; `tests/http.php` 35 tests.

## 2.0.0 - 2026-10-06

Correctness and security release. Requires **PHP 8.1+**.

### Fixed

- **Relations returned the wrong rows.** Every relation now owns one query: `hasMany` no longer returns the whole related table, `belongsTo` / `hasOne` no longer return the first row of the table, eager loading maps every parent.
- **`belongsToMany` crashed** (`join()` argument count) - rewritten with pivot data (`$model->pivot`, `withPivot()`), `attach()`, `detach()`, `sync()`, `updateExistingPivot()`.
- **`$model->update([...])` updated the whole table** (the call fell through to the query builder). It now fills and saves that model only; mass writes (`update`, `delete`, `restore`, `forceDelete`, `increment`) are only possible on an explicit query.
- **Reading a property could run any method** (`$model->delete` deleted the row). Property access only loads relation methods declared in your model; library methods never run.
- **Mass assignment protection was ignored** by `new Model($data)` / `create()`. `$fillable` / `$guarded` are enforced and the primary key is never mass assignable.
- **Global scopes could be bypassed with `orWhere()`** - scopes are applied as separate parenthesised groups when the query runs.
- **`withoutGlobalScope()` did nothing**, so `withTrashed()` and `onlyTrashed()` were wrong. Fixed.
- **`find()` / `all()` returned soft-deleted rows**; query `delete()` hard-deleted soft-delete models. Both respect soft deletes now (`forceDelete()` removes rows).
- **`Transaction::run()`** only caught `Exception` (an `Error` left the transaction open and every later write in the request was lost) and used a different connection than the models. It catches `Throwable`, shares the model connection and uses savepoints for nested calls.
- `Model::exists()`, `single()`, `any()`, `doesntExist()`, `increment()`, `ModelValidator::validate()` crashed - fixed.
- `json_encode($model)` returned `{}` - `Model` implements `JsonSerializable`.
- String primary keys were overwritten by `lastInsertId()` after insert.
- `$casts` were ignored on read and write (`json` arrays were stored as `Array`).
- `ModelMetadata` treated internal model properties (`attributes`, `original`...) as columns.
- `count()` with `groupBy()` / `having()` returned the wrong number; `skip()` without `take()` was invalid SQL; `latest()` defaulted to a non-existent column.
- `Query\QueryBuilder`: bindings depended on call order (`having()` before `where()` swapped values), `havingRaw()` / `selectRaw()` bindings were broken, `union()` duplicated bindings on every `toSql()`.
- `BulkOperations::insert()` dropped `$hidden` columns of models; `BulkInsert` put values in the wrong columns when row keys were in another order; bulk inserts failed above the driver parameter limit.
- `HealthCheck` always reported a failed connection.
- `Connection::disconnect()` left an unusable object; `reconnect()` always built a MySQL DSN.
- `DbContext::ensureCreated()` failed for models with `defineSchema()` and echoed into HTTP responses; models did not use the context connection.
- `HasMigration::fresh()` / `refresh()` were unreachable (shadowed by `Model::fresh()`).
- `Security::isValidTcNo()` rejected some valid numbers (negative modulo).
- `Config::env()` read `.env` files from the parent folder of the project.
- `RawQuery::value()` / `XmlClient::value()` implicit nullable parameters (PHP 8.4 deprecation).
- **`HttpClient::multi()` crashed** (`TypeError`: the cURL handle was cast to `int`), and every `$async = true` call returned a useless number.
- HttpClient: response headers of redirects / `100 Continue` were mixed into the final headers and header names were case-sensitive; `object()` threw a `TypeError` for scalar JSON; `post()` overwrote a `Content-Type` given by the caller.
- HttpClient accepted any URL scheme (`file://` read local files, also through a redirect) and header values with line breaks (header injection).
- `XmlClient::getWithCurl()` treated HTTP 4xx/5xx as success when the body was XML; `get()` put the URL (with tokens in the query string) into the error message; neither checked the URL scheme.
- `SoapClient`: an unreachable WSDL threw an uncaught `SoapFault` from the error handler of `call()`; the response timeout was PHP's `default_socket_timeout` (60 s), `connection_timeout` only covered connecting.

### Changed (breaking)

- PHP 8.1 or newer is required.
- `Model::$primaryKey` defaults to `Id` (was `id`).
- Timestamps are opt-in: `use HasTimestamps;` (CreatedDate / UpdatedDate, override with `const CREATED_AT` / `UPDATED_AT`).
- Default relation keys are `{Model}{PrimaryKey}` (e.g. `UserId`), or `user_id` when the primary key is `id`.
- Column, table and operator names are validated and quoted for the driver. Expressions in `select()` / `where()` / `orderBy()` must use `selectRaw()` / `whereRaw()` / `orderByRaw()`; `COUNT/SUM/AVG/MIN/MAX(col)` and `col AS alias` are still accepted.
- `Query\QueryBuilder` binds named parameters (`getBindings()` returns `[':q1_0' => value]`).
- `whereLike()` escapes `%` and `_` in the value; pass `false` as third argument for a raw pattern. New `whereStartsWith()`.
- `latest()` / `oldest()` default to `CreatedDate` (or the model's created-at column).
- `HasMigration::fresh()` / `refresh()` renamed to `recreateTable()`.
- Models, `Transaction`, `DB` and builders share one default connection (`ConnectionResolver`): explicit default, the last `DbConfig::...()->connect()`, otherwise `Config/Database.php`.
- `autoload.php` no longer registers global error handlers (define `MIKO_REGISTER_ERROR_HANDLERS` to keep them) and no longer includes `../Cargo/bootstrap.php`.
- `Config::env()`: real environment variables win over `.env`; lookup order `MIKO_ENV_FILE`, `<project>/.env`, `<project>/Env/Config.env`, `<library>/.env`.
- `Config/Database.php`: `persistent` defaults to `false`; MySQL `lc_time_names` is opt-in (`DB_LC_TIME_NAMES`); migrations table `__migrations`.
- `FormCrypt` / `Crypto`: authenticated `v2:` payloads (AES-256-CBC + HMAC-SHA256, HKDF keys). `ENCRYPTION_KEY` is required - there is no fallback key. Use `decryptLegacy()` to read 1.x data and re-encrypt it.
- `Security::getClientIp()` uses forwarding headers only from `TRUSTED_PROXIES` / `setTrustedProxies()`.
- `Observer` base methods have no return types (observers may declare `: void`).
- `DbContext::ensureCreated()` / `ensureDeleted()` return the table names instead of printing; `Migrator::ensureDatabaseExists()` is silent. Seeders print on the CLI only; `SeederRunner::runAll()` returns the count.
- `BulkOperations::update()` / `delete()` default to the model primary key; `delete()` soft-deletes soft-delete models and returns affected rows.
- `ORM\ConnectionPool::acquire()` throws immediately when the pool is full (it used to wait 30 seconds).
- `Connection` throws `QueryException` with the driver error code (`isDuplicateEntry()`, `isForeignKeyError()`, `isDeadlock()` work on every driver).
- `Logger`: SQL binding values are masked in log files (`LOG_SQL_BINDINGS=true` to log them), the log folder gets a deny-all `.htaccess`, rotation keeps one `.1` file.
- `HttpClient`: the `$async` parameters are removed (use `pool()`); `get($url, $headers, $query)`; `request($method, $url, $body, $headers, $options)` takes an options array (`query`, `form`, `multipart`, `sink`, `timeout`, `retries`...); `post()` / `put()` / `patch()` default to no body (was `[]`); only `http://` / `https://` URLs; a relative URL without `base_url` throws. `HttpResponse` / `HttpException` live in their own files.
- `HttpResponse::isSuccess` is false for transport errors (`errno` > 0, `statusCode` 0); `HttpResponse::json()` takes an optional dot path.
- `XmlClient` runs on `HttpClient` (`get($url, $query, $headers)`); `getWithCurl()` is an alias of `get()`.
- `SoapClient`: WSDL cached (`WSDL_CACHE_BOTH`), `connection_timeout` 10 s, new `timeout` option (30 s response timeout), keep-alive and gzip responses on; `$wsdl` may be `null` (non-WSDL mode with `location` + `uri`); needs `extension=soap` (clear error otherwise).

### Added

- `Grammar` (identifier validation/quoting, LIMIT/OFFSET, date parts, savepoints per driver), `ConnectionFactory`, `ConnectionResolver`.
- Driver specific DDL in `TableBuilder` / `Schema` for MySQL, PostgreSQL, SQLite and SQL Server; `Schema::table()` to alter tables; `ColumnBuilder::useCurrent()`, `primary()`; `TableBuilder::char()`, `primary()`.
- Nested and constrained eager loading: `with('posts.comments')`, `with(['posts' => fn($q) => ...])`, `without()`, `$model->load()`, `loadMissing()`, default `$with`.
- Query builder: `whereNot()`, `whereKey()`, `whereStartsWith()`, `whereTime()`, `orWhereDate()`, `unless()`, `addSelect()`, `selectRaw()`, `orHaving()`, `reorder()`, `forPage()`, `cursor()`, `each()`, `findMany()`, `single()`, `withoutGlobalScopes()`, local scopes on the builder (`User::where(...)->active()`).
- Model: `forceCreate()`, `touch()`, `isClean()`, `getAttributes()`, `observe()`, `retrieved` / `forceDeleting` / `forceDeleted` events, `$incrementing`, `$keyType`.
- `BulkOperations::upsert()` for PostgreSQL and SQLite (`ON CONFLICT`); `DB::table()`, `DB::transaction()`.
- `Security::rateLimitByIp()` (APCu), `Security::setTrustedProxies()`.
- `QueryLogger` is fed by `Connection` (all queries, ORM included) and configured from `Config/Database.php`.
- Test suite: `php tests/run.php` (83 tests, passes on SQLite 3.39, MariaDB 12.2, MySQL 8.0 and PostgreSQL 18.6).
- `HttpClient::pool()`: parallel requests with a concurrency limit, per-request retries and downloads, keys and order kept; `retry()` (exponential backoff with jitter, `Retry-After`, non-idempotent methods only retried when never sent); `download()` (streamed to `<file>.part`, renamed on success); `head()`, `setTimeout()`, `setUserAgent()`, `close()`; options `ca_bundle`, `proxy`, `http_version`, `user_agent`; headers as `['Name' => 'value']` or `['Name: value']`.
- `HttpResponse`: `status()`, `body()`, `successful()`, `connectionFailed()`, `clientError()`, `serverError()`, `headerValues()`, `hasHeader()`, `contentType()`, `duration()`, `effectiveUrl()`; `HttpException::getResponse()`.
- `XmlClient`: `post()` (string / SimpleXMLElement / DOMDocument), `pool()`, `parse()`; `XmlResponse`: `status()`, `body()`, `registerNamespace()` (document prefixes are registered automatically).
- `SoapClient`: per call SOAP headers, `setTimeout()`; `SoapResponse`: `faultCode()`, `outputHeaders()`, `duration()`, `throwIfFailed()`.
- HTTP client tests: `php -d extension=soap tests/http.php` (30 tests against local `php -S` servers).

### Performance

- `exists()` runs `SELECT 1 ... LIMIT 1` instead of `COUNT(*)`; `count()` / aggregates drop ORDER BY / LIMIT.
- `whereDate()` / `whereYear()` with plain values compile to ranges that can use an index.
- One connection per request instead of one per model class; MySQL session setup is one `INIT_COMMAND`.
- Bulk inserts are chunked to the driver parameter limit; bulk updates reuse prepared statements inside one transaction.
- `save()` skips the UPDATE when nothing changed (numeric strings compare equal).
- `ensureCreated()` no longer opens an extra connection when the context is already connected.
- `HttpClient` reuses one cURL handle (keep-alive connections) and shares the DNS / TLS session cache; locally 5 sequential calls took 15 ms instead of 40 ms (HTTP) and 20 ms instead of 37 ms (HTTPS). Response headers are collected while they arrive (no copy of the body), no `Expect: 100-continue` round trip, gzip/deflate accepted.
- `SoapClient` no longer downloads and parses the WSDL on every request.

## 1.1.0

- Grouped `where(function ($q) { ... })` / `orWhere(function)` on Model, MikoSet, ORM query builder and database / fluent query builders
- Named connection pool: `Miko\Database\ConnectionPool\ConnectionPool` + `DatabaseManager` (`pool.enabled`, `session.persistent`)
