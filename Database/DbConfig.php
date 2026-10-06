<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 *
 * DbConfig - Fluent Database Configuration
 * Similar to mersolutionCore DbConfig.cs
 */

namespace Miko\Database;

use Miko\Core\Config;

/**
 * Fluent Database Configuration
 *
 * Usage:
 *   DbConfig::mysql('localhost', 'database', 'root', 'password')->connect();
 *   DbConfig::sqlite('/path/to/database.sqlite')->connect();
 *   DbConfig::sqlServer('localhost', 'database', 'user', 'pass')->connect();
 *   DbConfig::postgreSql('localhost', 'database', 'user', 'pass')->connect();
 *
 * The last connect() becomes the default connection for models (unless
 * ConnectionResolver::setDefault() was called).
 */
class DbConfig
{
    private static ?self $instance = null;
    private static ?Connection $connection = null;

    private string $driver = 'mysql';
    private string $host = 'localhost';
    private int $port = 3306;
    private string $database = '';
    private string $username = '';
    private string $password = '';
    private string $charset = 'utf8mb4';
    private ?string $collation = null;
    private array $options = [];

    private function __construct() {}

    public static function mysql(string $host, string $database, string $username, string $password, int $port = 3306): self
    {
        $instance = new self();
        $instance->driver = 'mysql';
        $instance->host = $host;
        $instance->database = $database;
        $instance->username = $username;
        $instance->password = $password;
        $instance->port = $port;
        $instance->collation = 'utf8mb4_unicode_ci';

        return self::$instance = $instance;
    }

    public static function sqlite(string $databasePath): self
    {
        $instance = new self();
        $instance->driver = 'sqlite';
        $instance->database = $databasePath;

        return self::$instance = $instance;
    }

    public static function sqlServer(string $host, string $database, ?string $username = null, ?string $password = null, int $port = 1433): self
    {
        $instance = new self();
        $instance->driver = 'sqlsrv';
        $instance->host = $host;
        $instance->database = $database;
        $instance->username = $username ?? '';
        $instance->password = $password ?? '';
        $instance->port = $port;

        return self::$instance = $instance;
    }

    public static function postgreSql(string $host, string $database, string $username, string $password, int $port = 5432): self
    {
        $instance = new self();
        $instance->driver = 'pgsql';
        $instance->host = $host;
        $instance->database = $database;
        $instance->username = $username;
        $instance->password = $password;
        $instance->port = $port;
        $instance->charset = 'utf8';

        return self::$instance = $instance;
    }

    /**
     * Set charset (MySQL / PostgreSQL)
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set collation (MySQL)
     */
    public function collation(?string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * Set PDO options
     */
    public function options(array $options): self
    {
        $this->options = $options + $this->options;
        return $this;
    }

    /**
     * Create the connection
     */
    public function connect(): Connection
    {
        return self::$connection = ConnectionFactory::make($this->toArray(true));
    }

    /**
     * Last connection created by connect()
     */
    public static function connection(): ?Connection
    {
        return self::$connection;
    }

    /**
     * Forget the last connection
     */
    public static function reset(): void
    {
        self::$connection = null;
        self::$instance = null;
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Config array (without password)
     */
    public function getConfig(): array
    {
        return $this->toArray(false);
    }

    /**
     * Config array for ConnectionFactory
     */
    public function toArray(bool $withPassword = false): array
    {
        $config = [
            'driver' => $this->driver,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'options' => $this->options,
        ];

        if ($withPassword) {
            $config['password'] = $this->password;
        }

        return $config;
    }

    /**
     * Create configuration from environment (.env or real environment variables)
     */
    public static function fromEnv(): self
    {
        $driver = (string) Config::env('DB_DRIVER', Config::env('DB_CONNECTION', 'mysql'));
        $host = (string) Config::env('DB_HOST', Config::env('DB_HOST_LOCAL', 'localhost'));
        $database = (string) Config::env('DB_DATABASE', Config::env('DB_DATABASE_LOCAL', ''));
        $username = Config::env('DB_USERNAME', Config::env('DB_USERNAME_LOCAL'));
        $password = Config::env('DB_PASSWORD', Config::env('DB_PASSWORD_LOCAL'));
        $port = Config::env('DB_PORT');

        return match ($driver) {
            'mysql' => self::mysql($host, $database, (string) ($username ?? 'root'), (string) ($password ?? ''), (int) ($port ?? 3306)),
            'sqlite' => self::sqlite($database !== '' ? $database : ':memory:'),
            'sqlsrv' => self::sqlServer($host, $database, $username !== null ? (string) $username : null, $password !== null ? (string) $password : null, (int) ($port ?? 1433)),
            'pgsql' => self::postgreSql($host, $database, (string) ($username ?? ''), (string) ($password ?? ''), (int) ($port ?? 5432)),
            default => throw new \InvalidArgumentException("Unsupported driver: {$driver}"),
        };
    }
}
