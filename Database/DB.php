<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database;

use Miko\Database\ConnectionPool\ConnectionPool;

/**
 * DB Facade - Static database access
 */
class DB
{
    private static ?ConnectionPool $pool = null;

    private function __construct() {}

    /**
     * Get Connection instance
     * 
     * @return Connection
     */
    public static function connection(): Connection
    {
        if (self::$pool === null) 
        {
            $config = require __DIR__ . '/../Config/Database.php';
            $default = $config['default'] ?? 'mysql';
            $connConfig = $config['connections'][$default];
            $poolConfig = $config['pool'] ?? [];
            $sessionConfig = $config['session'] ?? [];
            
            self::$pool = new ConnectionPool([
                'connections' => [
                    'default' => array_merge($connConfig, [
                        'persistent' => $sessionConfig['persistent'] ?? true,
                        'pool' => [
                            'min' => $poolConfig['min'] ?? 2,
                            'max' => $poolConfig['max'] ?? 10
                        ]
                    ])
                ]
            ]);
        }

        return self::$pool->getConnection('default');
    }

    private function __clone() {}
    public function __wakeup() {}
}
