<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * DbConfig - Fluent Database Configuration
 * Similar to mersolutionCore DbConfig.cs
 */

namespace Miko\Database;

use Miko\Database\Drivers\DriverFactory;
use Miko\Database\Drivers\DriverInterface;
use PDO;

/**
 * Fluent Database Configuration
 * 
 * Usage:
 *   DbConfig::mysql('localhost', 'database', 'root', 'password')->connect();
 *   DbConfig::sqlite('/path/to/database.sqlite')->connect();
 *   DbConfig::sqlServer('localhost', 'database', 'user', 'pass')->connect();
 *   DbConfig::postgreSql('localhost', 'database', 'user', 'pass')->connect();
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
    private array $options = [];

    private function __construct() {}

    /**
     * Configure MySQL connection
     */
    public static function mysql(string $host, string $database, string $username, string $password, int $port = 3306): self
    {
        $instance = new self();
        $instance->driver = 'mysql';
        $instance->host = $host;
        $instance->database = $database;
        $instance->username = $username;
        $instance->password = $password;
        $instance->port = $port;
        
        self::$instance = $instance;
        return $instance;
    }

    /**
     * Configure SQLite connection
     */
    public static function sqlite(string $databasePath): self
    {
        $instance = new self();
        $instance->driver = 'sqlite';
        $instance->database = $databasePath;
        
        self::$instance = $instance;
        return $instance;
    }

    /**
     * Configure SQL Server connection
     */
    public static function sqlServer(string $host, string $database, ?string $username = null, ?string $password = null, int $port = 1433): self
    {
        $instance = new self();
        $instance->driver = 'sqlsrv';
        $instance->host = $host;
        $instance->database = $database;
        $instance->username = $username ?? '';
        $instance->password = $password ?? '';
        $instance->port = $port;
        
        self::$instance = $instance;
        return $instance;
    }

    /**
     * Configure PostgreSQL connection
     */
    public static function postgreSql(string $host, string $database, string $username, string $password, int $port = 5432): self
    {
        $instance = new self();
        $instance->driver = 'pgsql';
        $instance->host = $host;
        $instance->database = $database;
        $instance->username = $username;
        $instance->password = $password;
        $instance->port = $port;
        
        self::$instance = $instance;
        return $instance;
    }

    /**
     * Set charset (for MySQL)
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set PDO options
     */
    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Create and return PDO connection
     */
    public function connect(): Connection
    {
        $driver = DriverFactory::create($this->driver);
        
        $config = [
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'charset' => $this->charset,
        ];

        $dsn = $driver->getDsn($config);
        $options = array_merge($driver->getOptions(), $this->options);

        $pdo = new PDO(
            $dsn,
            $this->username ?: null,
            $this->password ?: null,
            $options
        );

        self::$connection = new Connection($pdo, $config);
        return self::$connection;
    }

    /**
     * Get current connection (singleton)
     */
    public static function connection(): ?Connection
    {
        return self::$connection;
    }

    /**
     * Get current instance
     */
    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * Get driver name
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get config array
     */
    public function getConfig(): array
    {
        return [
            'driver' => $this->driver,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'charset' => $this->charset,
        ];
    }

    /**
     * Create connection from environment variables
     */
    public static function fromEnv(): self
    {
        $driver = $_ENV['DB_DRIVER'] ?? 'mysql';
        
        return match($driver) {
            'mysql' => self::mysql(
                $_ENV['DB_HOST'] ?? $_ENV['DB_HOST_LOCAL'] ?? 'localhost',
                $_ENV['DB_DATABASE'] ?? $_ENV['DB_DATABASE_LOCAL'] ?? '',
                $_ENV['DB_USERNAME'] ?? $_ENV['DB_USERNAME_LOCAL'] ?? 'root',
                $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASSWORD_LOCAL'] ?? '',
                (int)($_ENV['DB_PORT'] ?? 3306)
            ),
            'sqlite' => self::sqlite($_ENV['DB_DATABASE'] ?? ':memory:'),
            'sqlsrv' => self::sqlServer(
                $_ENV['DB_HOST'] ?? 'localhost',
                $_ENV['DB_DATABASE'] ?? '',
                $_ENV['DB_USERNAME'] ?? null,
                $_ENV['DB_PASSWORD'] ?? null,
                (int)($_ENV['DB_PORT'] ?? 1433)
            ),
            'pgsql' => self::postgreSql(
                $_ENV['DB_HOST'] ?? 'localhost',
                $_ENV['DB_DATABASE'] ?? '',
                $_ENV['DB_USERNAME'] ?? '',
                $_ENV['DB_PASSWORD'] ?? '',
                (int)($_ENV['DB_PORT'] ?? 5432)
            ),
            default => throw new \InvalidArgumentException("Unsupported driver: {$driver}")
        };
    }
}
