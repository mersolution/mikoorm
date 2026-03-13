<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Entities\Log;

/**
 * Professional Logger System
 * 
 * Provides separate logging channels for different application components:
 * - QueryBuilder: SQL query errors and performance issues
 * - Connection: Database connection and transaction errors
 * - API: API endpoint errors and validation issues
 * - Business: Business logic and application errors
 */
class Logger
{
    private static ?string $logDir = null;
    private static ?int $maxSizeMB = null;
    
    /**
     * Get max log file size in MB from env (default: 3)
     */
    private static function getMaxSizeMB(): int
    {
        if (self::$maxSizeMB === null) {
            self::$maxSizeMB = (int) ($_ENV['LOG_MAX_SIZE_MB'] ?? $_SERVER['LOG_MAX_SIZE_MB'] ?? getenv('LOG_MAX_SIZE_MB') ?: 3);
        }
        return self::$maxSizeMB;
    }

    /**
     * Get log directory (auto-detect project root)
     */
    private static function getLogDir(): string
    {
        if (self::$logDir === null) {
            // Go up 3 levels from Model/Miko/Log to project root, then into Log folder
            self::$logDir = dirname(__DIR__, 3) . '/Log';
        }
        
        return self::$logDir;
    }
    
    private const LOG_FILES = [
        'querybuilder' => 'querybuilder.log',
        'connection' => 'connection.log',
        'api' => 'api.log',
        'business' => 'business.log',
        'general' => 'application.log'
    ];

    private const LOG_LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];

    /**
     * Log QueryBuilder errors (SQL queries, bindings, etc.)
     */
    public static function queryBuilder(string $message, array $context = [], string $level = 'ERROR'): void
    {
        self::write('querybuilder', $level, $message, $context);
    }

    /**
     * Log Connection errors (PDO, transactions, etc.)
     */
    public static function connection(string $message, array $context = [], string $level = 'ERROR'): void
    {
        self::write('connection', $level, $message, $context);
    }

    /**
     * Log API errors (endpoints, validation, authentication, etc.)
     */
    public static function api(string $message, array $context = [], string $level = 'ERROR'): void
    {
        self::write('api', $level, $message, $context);
    }

    /**
     * Log Business logic errors (application logic, calculations, etc.)
     */
    public static function business(string $message, array $context = [], string $level = 'ERROR'): void
    {
        self::write('business', $level, $message, $context);
    }

    /**
     * Log general application errors
     */
    public static function general(string $message, array $context = [], string $level = 'ERROR'): void
    {
        self::write('general', $level, $message, $context);
    }

    /**
     * Write log entry to specified channel
     */
    private static function write(string $channel, string $level, string $message, array $context = []): void
    {
        if (!isset(self::LOG_FILES[$channel])) {
            $channel = 'general';
        }

        $logDir = self::getLogDir();
        $logFile = $logDir . '/' . self::LOG_FILES[$channel];

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755);
        }

        // Check file size and rotate if exceeds limit from env
        if (file_exists($logFile)) {
            $fileSizeMB = filesize($logFile) / 1024 / 1024;
            if ($fileSizeMB > self::getMaxSizeMB()) {
                // Delete old file and create new one
                unlink($logFile);
            }
        }

        $timestamp = date('Y-m-d H:i:s');
        $level = strtoupper($level);
        
        $logEntry = sprintf(
            "[%s] [%s] %s",
            $timestamp,
            $level,
            $message
        );

        if (!empty($context)) {
            $logEntry .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $logEntry .= PHP_EOL;

        error_log($logEntry, 3, $logFile);
    }

    /**
     * Log SQL query with bindings
     */
    public static function logQuery(string $sql, array $bindings = [], ?string $error = null): void
    {
        $context = [
            'sql' => $sql,
            'bindings' => $bindings
        ];

        if ($error !== null) {
            $context['error'] = $error;
            self::queryBuilder("SQL Query Failed", $context, 'ERROR');
        } else {
            self::queryBuilder("SQL Query Executed", $context, 'DEBUG');
        }
    }

    /**
     * Log API request
     */
    public static function logApiRequest(string $method, string $endpoint, array $params = [], ?string $error = null): void
    {
        $context = [
            'method' => $method,
            'endpoint' => $endpoint,
            'params' => $params,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];

        if ($error !== null) {
            $context['error'] = $error;
            self::api("API Request Failed: {$method} {$endpoint}", $context, 'ERROR');
        } else {
            self::api("API Request: {$method} {$endpoint}", $context, 'INFO');
        }
    }

    /**
     * Log database connection error
     */
    public static function logConnectionError(string $message, array $context = []): void
    {
        self::connection($message, $context, 'ERROR');
    }

    /**
     * Log business logic error
     */
    public static function logBusinessError(string $message, array $context = []): void
    {
        self::business($message, $context, 'ERROR');
    }

    /**
     * Clear old log files (older than specified days)
     */
    public static function clearOldLogs(int $days = 30): int
    {
        $cleared = 0;
        $cutoffTime = time() - ($days * 24 * 60 * 60);

        $logDir = self::getLogDir();
        
        foreach (self::LOG_FILES as $logFile) {
            $filePath = $logDir . '/' . $logFile;
            
            if (file_exists($filePath) && filemtime($filePath) < $cutoffTime) {
                if (unlink($filePath)) {
                    $cleared++;
                }
            }
        }

        return $cleared;
    }

    /**
     * Get log file size in bytes
     */
    public static function getLogSize(string $channel): int
    {
        if (!isset(self::LOG_FILES[$channel])) {
            return 0;
        }

        $logDir = self::getLogDir();
        $filePath = $logDir . '/' . self::LOG_FILES[$channel];
        
        return file_exists($filePath) ? filesize($filePath) : 0;
    }

    /**
     * Read last N lines from log file
     */
    public static function readLastLines(string $channel, int $lines = 100): array
    {
        if (!isset(self::LOG_FILES[$channel])) {
            return [];
        }

        $logDir = self::getLogDir();
        $filePath = $logDir . '/' . self::LOG_FILES[$channel];
        
        if (!file_exists($filePath)) {
            return [];
        }

        $file = new \SplFileObject($filePath, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $startLine = max(0, $lastLine - $lines);

        $result = [];
        $file->seek($startLine);
        
        while (!$file->eof()) {
            $line = $file->current();
            if (!empty(trim($line))) {
                $result[] = $line;
            }
            $file->next();
        }

        return $result;
    }
}
