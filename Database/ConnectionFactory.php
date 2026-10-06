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

use Miko\Database\Drivers\DriverFactory;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\Monitor\ConnectionStats;
use PDO;
use PDOException;

/**
 * ConnectionFactory - the single place where PDO connections are created.
 *
 * Config keys: driver, host, port, database, username, password, charset,
 * collation, schema (pgsql), options (PDO options), persistent, wait_timeout,
 * interactive_timeout, time_names (MySQL lc_time_names), foreign_keys (sqlite).
 */
final class ConnectionFactory
{
    public static function make(array $config): Connection
    {
        $config['driver'] = strtolower((string) ($config['driver'] ?? 'mysql'));

        return new Connection(self::createPdo($config), $config);
    }

    public static function createPdo(array $config): PDO
    {
        $driverName = strtolower((string) ($config['driver'] ?? 'mysql'));
        $driver = DriverFactory::create($driverName);

        $options = $driver->getOptions();
        if (!empty($config['options']) && is_array($config['options'])) {
            $options = $config['options'] + $options;
        }
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options += [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

        if (self::isTruthy($config['persistent'] ?? false) && $driverName !== 'sqlite') {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        if ($driverName === 'mysql') {
            $init = self::mysqlInitCommand($config);
            $attr = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
                ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
                : (defined('PDO::MYSQL_ATTR_INIT_COMMAND') ? constant('PDO::MYSQL_ATTR_INIT_COMMAND') : null);
            if ($attr !== null && !isset(($config['options'] ?? [])[$attr])) {
                $options[$attr] = $init;
            }
        }

        try {
            $pdo = new PDO(
                $driver->getDsn($config),
                isset($config['username']) && $config['username'] !== '' ? (string) $config['username'] : null,
                isset($config['password']) && $config['password'] !== '' ? (string) $config['password'] : null,
                $options
            );
        } catch (PDOException $e) {
            ConnectionStats::recordFailedConnection();
            throw new DatabaseException('Database connection failed: ' . $e->getMessage(), (int) ($e->errorInfo[1] ?? 0), $e);
        }

        ConnectionStats::recordConnection();

        if ($driverName === 'pgsql') {
            $schema = $config['schema'] ?? null;
            if (is_string($schema) && $schema !== '') {
                $schemas = array_map('trim', explode(',', $schema));
                foreach ($schemas as $s) {
                    self::safeIdent($s);
                }
                $pdo->exec('SET search_path TO ' . implode(', ', $schemas));
            }
            if (!empty($config['charset'])) {
                $pdo->exec("SET client_encoding TO '" . self::safeIdent((string) $config['charset']) . "'");
            }
        } elseif ($driverName === 'sqlite') {
            if (self::isTruthy($config['foreign_keys'] ?? true)) {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }

        return $pdo;
    }

    /**
     * One INIT_COMMAND for the whole MySQL session setup (one round trip); also used by the async mysqli links
     *
     * @internal
     */
    public static function mysqlInitCommand(array $config): string
    {
        $charset = self::safeIdent((string) ($config['charset'] ?? 'utf8mb4'));
        $parts = ["NAMES '{$charset}'" . (!empty($config['collation'])
            ? " COLLATE '" . self::safeIdent((string) $config['collation']) . "'"
            : '')];

        foreach (['wait_timeout', 'interactive_timeout'] as $var) {
            if (isset($config[$var]) && $config[$var] !== '' && $config[$var] !== null) {
                $parts[] = "SESSION {$var} = " . max(1, (int) $config[$var]);
            }
        }

        if (!empty($config['time_names'])) {
            $parts[] = "lc_time_names = '" . self::safeIdent((string) $config['time_names']) . "'";
        }

        return 'SET ' . implode(', ', $parts);
    }

    private static function safeIdent(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new DatabaseException('Invalid identifier in database config.');
        }
        return $value;
    }

    public static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off', 'null'], true);
        }
        return (bool) $value;
    }
}
