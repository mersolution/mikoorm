<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

use function Miko\Core\env;

/**
 * Database Configuration
 *
 * Every key reads DB_* first and falls back to the older DB_*_LOCAL names.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    | Values: 'mysql', 'sqlite', 'sqlsrv', 'pgsql'
    */
    'default' => env('DB_CONNECTION', env('DB_DRIVER', 'mysql')),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [
        'mysql' => [
            'driver'     => 'mysql',
            'host'       => env('DB_HOST', env('DB_HOST_LOCAL', '127.0.0.1')),
            'port'       => (int) env('DB_PORT', 3306),
            'database'   => env('DB_DATABASE', env('DB_DATABASE_LOCAL', '')),
            'username'   => env('DB_USERNAME', env('DB_USERNAME_LOCAL', 'root')),
            'password'   => env('DB_PASSWORD', env('DB_PASSWORD_LOCAL', '')),
            'charset'    => env('DB_CHARSET', 'utf8mb4'),
            'collation'  => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            // MySQL lc_time_names, e.g. 'tr_TR' for Turkish month/day names in DATE_FORMAT
            'time_names' => env('DB_LC_TIME_NAMES'),
        ],

        'sqlite' => [
            'driver'       => 'sqlite',
            'database'     => env('DB_DATABASE', __DIR__ . '/../../database.sqlite'),
            'foreign_keys' => true,
        ],

        'sqlsrv' => [
            'driver'   => 'sqlsrv',
            'host'     => env('DB_HOST', 'localhost'),
            'port'     => (int) env('DB_PORT', 1433),
            'database' => env('DB_DATABASE', 'miko'),
            'username' => env('DB_USERNAME', 'sa'),
            'password' => env('DB_PASSWORD', ''),
        ],

        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => env('DB_HOST', 'localhost'),
            'port'     => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'miko'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => 'utf8',
            'schema'   => 'public',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Settings
    |--------------------------------------------------------------------------
    */
    'migrations' => [
        'table' => '__migrations',
        'path'  => __DIR__ . '/../../Database/Migrations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Pool Settings
    |--------------------------------------------------------------------------
    | Within one request the pool hands out one pinned connection per name.
    */
    'pool' => [
        'enabled' => env('DB_POOL_ENABLED', true),
        'min' => (int) env('DB_POOL_MIN', 2),
        'max' => (int) env('DB_POOL_MAX', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Async Queries (getAsync(), countAsync(), DB::queryAsync() ...)
    |--------------------------------------------------------------------------
    | MySQL / MariaDB, PostgreSQL and SQL Server run them in parallel on extra
    | connections (no extra PHP extension needed); SQLite runs them one by one.
    | max_connections: extra connections per database connection
    | timeout: seconds before a query is cancelled on the server (0 = none)
    | mysql_driver / pgsql_driver: auto = mysqli / pgsql extension if loaded,
    |               else the built-in PHP client; 'extension' / 'php' force one
    | sqlsrv_driver: auto / php = built-in TDS client, 'extension' = one by one
    */
    'async' => [
        'enabled' => env('DB_ASYNC_ENABLED', true),
        'max_connections' => (int) env('DB_ASYNC_MAX_CONNECTIONS', 4),
        'timeout' => (float) env('DB_ASYNC_TIMEOUT', 0),
        'mysql_driver' => env('DB_ASYNC_MYSQL_DRIVER', 'auto'),
        'pgsql_driver' => env('DB_ASYNC_PGSQL_DRIVER', 'auto'),
        'sqlsrv_driver' => env('DB_ASYNC_SQLSRV_DRIVER', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Logger Settings
    |--------------------------------------------------------------------------
    | Slow queries (>= slow_threshold ms) are written to Log/<log_file>.
    */
    'query_log' => [
        'enabled' => env('QUERY_LOG_ENABLED', true),
        'slow_threshold' => (float) env('QUERY_SLOW_THRESHOLD', 500), // ms
        'log_file' => 'slow_queries.log',
    ],

    /*
    |--------------------------------------------------------------------------
    | Session & Timeout Settings
    |--------------------------------------------------------------------------
    | persistent: reuse PDO handles across PHP-FPM requests (off by default).
    */
    'session' => [
        'wait_timeout' => (int) env('DB_WAIT_TIMEOUT', 28800),
        'interactive_timeout' => (int) env('DB_INTERACTIVE_TIMEOUT', 28800),
        'persistent' => env('DB_PERSISTENT', false),
    ],
];
