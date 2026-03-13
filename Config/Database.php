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
 * Database connection settings
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | Default database connection to use
    | Values: 'mysql', 'sqlite', 'sqlsrv', 'pgsql'
    |
    */
    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Different database connection settings
    |
    */
    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST'),
            'port'      => env('DB_PORT'),
            'database'  => env('DB_DATABASE'),
            'username'  => env('DB_USERNAME'),
            'password'  => env('DB_PASSWORD'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],

        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_DATABASE', __DIR__ . '/../../database.sqlite'),
            'options'  => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ],

        'sqlsrv' => [
            'driver'   => 'sqlsrv',
            'host'     => env('DB_HOST', 'localhost'),
            'port'     => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'miko'),
            'username' => env('DB_USERNAME', 'sa'),
            'password' => env('DB_PASSWORD', ''),
            'options'  => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ],

        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => env('DB_HOST', 'localhost'),
            'port'     => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'miko'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => 'utf8',
            'schema'   => 'public',
            'options'  => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Settings
    |--------------------------------------------------------------------------
    |
    | Migration table and folder settings
    |
    */
    'migrations' => [
        'table' => 'migrations',
        'path'  => __DIR__ . '/../../../Database/Migrations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Pool Settings
    |--------------------------------------------------------------------------
    |
    | Connection pool settings for performance
    |
    */
    'pool' => [
        'enabled' => env('DB_POOL_ENABLED', true),
        'min' => (int) env('DB_POOL_MIN', 2),
        'max' => (int) env('DB_POOL_MAX', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Logger Settings
    |--------------------------------------------------------------------------
    |
    | Slow query tracking settings
    |
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
    |
    | Database session and timeout settings
    |
    */
    'session' => [
        'wait_timeout' => (int) env('DB_WAIT_TIMEOUT', 28800),
        'interactive_timeout' => (int) env('DB_INTERACTIVE_TIMEOUT', 28800),
        'persistent' => env('DB_PERSISTENT', true),
    ],
];
