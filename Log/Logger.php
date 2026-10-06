<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Log;

/**
 * Channel based file logger.
 *
 * Log folder: LOG_DIR env, Logger::setLogDir() or <project>/Log (project =
 * folder that contains the library). The folder gets a deny-all .htaccess.
 * SQL binding values are masked unless LOG_SQL_BINDINGS=true.
 */
class Logger
{
    private static ?string $logDir = null;
    private static ?int $maxSizeMB = null;
    private static bool $dirPrepared = false;

    private const LOG_FILES = [
        'querybuilder' => 'querybuilder.log',
        'connection' => 'connection.log',
        'api' => 'api.log',
        'business' => 'business.log',
        'general' => 'application.log',
    ];

    public static function setLogDir(string $path): void
    {
        self::$logDir = rtrim($path, '/\\');
        self::$dirPrepared = false;
    }

    /**
     * Log directory (created on first write)
     */
    public static function getLogDir(): string
    {
        if (self::$logDir === null) {
            $env = $_ENV['LOG_DIR'] ?? getenv('LOG_DIR');
            self::$logDir = is_string($env) && $env !== ''
                ? rtrim($env, '/\\')
                : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Log';
        }

        return self::$logDir;
    }

    private static function getMaxSizeMB(): int
    {
        if (self::$maxSizeMB === null) {
            $value = $_ENV['LOG_MAX_SIZE_MB'] ?? $_SERVER['LOG_MAX_SIZE_MB'] ?? getenv('LOG_MAX_SIZE_MB');
            self::$maxSizeMB = max(1, (int) ($value ?: 3));
        }
        return self::$maxSizeMB;
    }

    public static function queryBuilder(string $message, array $context = [], string $level = 'ERROR'): void
    {
        self::write('querybuilder', $level, $message, $context);
    }

    public static function connection(string $message, array $context = [], string $level = 'ERROR'): void
    {
        self::write('connection', $level, $message, $context);
    }

    public static function api(string $message, array $context = [], string $level = 'ERROR'): void
    {
        self::write('api', $level, $message, $context);
    }

    public static function business(string $message, array $context = [], string $level = 'ERROR'): void
    {
        self::write('business', $level, $message, $context);
    }

    public static function general(string $message, array $context = [], string $level = 'ERROR'): void
    {
        self::write('general', $level, $message, $context);
    }

    /**
     * Opt-in: route PHP errors, uncaught exceptions and fatals to Log/application.log.
     * Call it from your bootstrap, or define('MIKO_REGISTER_ERROR_HANDLERS', true)
     * before requiring autoload.php. Uncaught exceptions answer with a JSON 500.
     */
    public static function registerPhpErrorHandlers(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        if (self::prepareDir()) {
            ini_set('log_errors', '1');
            ini_set('error_log', self::getLogDir() . DIRECTORY_SEPARATOR . 'php_error.log');
        }

        set_error_handler(static function (int $errno, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $errno)) {
                return false;
            }

            $level = match (true) {
                in_array($errno, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true) => 'ERROR',
                in_array($errno, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING], true) => 'WARNING',
                default => 'DEBUG',
            };

            self::general("PHP error {$errno}: {$message}", ['file' => $file, 'line' => $line], $level);

            return true;
        });

        set_exception_handler(static function (\Throwable $e): void {
            self::general('Uncaught ' . $e::class . ': ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ], 'CRITICAL');

            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode(['status' => 'ERROR', 'message' => 'INTERNAL_ERROR'], JSON_UNESCAPED_UNICODE);
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err === null || !in_array((int) ($err['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            self::general('PHP Fatal: ' . ($err['message'] ?? ''), [
                'file' => $err['file'] ?? '',
                'line' => $err['line'] ?? 0,
            ], 'CRITICAL');
        });
    }

    /**
     * Write a log entry
     */
    private static function write(string $channel, string $level, string $message, array $context = []): void
    {
        if (!isset(self::LOG_FILES[$channel])) {
            $channel = 'general';
        }

        if (!self::prepareDir()) {
            return;
        }

        $logFile = self::getLogDir() . DIRECTORY_SEPARATOR . self::LOG_FILES[$channel];
        self::rotateIfLarge($logFile);

        $entry = sprintf('[%s] [%s] %s', date('Y-m-d H:i:s'), strtoupper($level), $message);
        if ($context !== []) {
            $entry .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        @file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Create the log folder with a deny-all .htaccess (once per process)
     */
    private static function prepareDir(): bool
    {
        if (self::$dirPrepared) {
            return true;
        }

        $dir = self::getLogDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }

        $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }

        return self::$dirPrepared = is_writable($dir);
    }

    /**
     * Keep one previous file (name.log.1) when the log grows over LOG_MAX_SIZE_MB
     */
    public static function rotateIfLarge(?string $file): void
    {
        if ($file === null || !is_file($file)) {
            return;
        }

        clearstatcache(true, $file);
        if (filesize($file) > self::getMaxSizeMB() * 1024 * 1024) {
            @rename($file, $file . '.1');
        }
    }

    /**
     * Binding values for logs: masked as type(length) unless LOG_SQL_BINDINGS=true
     */
    public static function maskBindings(array $bindings): array
    {
        $flag = $_ENV['LOG_SQL_BINDINGS'] ?? getenv('LOG_SQL_BINDINGS');
        if (is_string($flag) && in_array(strtolower($flag), ['1', 'true', 'yes', 'on'], true)) {
            return $bindings;
        }

        return array_map(static fn($value) => match (true) {
            $value === null => null,
            is_bool($value) => 'bool',
            is_int($value), is_float($value) => get_debug_type($value),
            is_string($value) => 'string(' . strlen($value) . ')',
            default => get_debug_type($value),
        }, $bindings);
    }

    /**
     * Log SQL query (binding values masked)
     */
    public static function logQuery(string $sql, array $bindings = [], ?string $error = null): void
    {
        $context = ['sql' => $sql, 'bindings' => self::maskBindings($bindings)];

        if ($error !== null) {
            $context['error'] = $error;
            self::queryBuilder('SQL Query Failed', $context, 'ERROR');
        } else {
            self::queryBuilder('SQL Query Executed', $context, 'DEBUG');
        }
    }

    public static function logApiRequest(string $method, string $endpoint, array $params = [], ?string $error = null): void
    {
        $context = [
            'method' => $method,
            'endpoint' => $endpoint,
            'params' => $params,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ];

        if ($error !== null) {
            $context['error'] = $error;
            self::api("API Request Failed: {$method} {$endpoint}", $context, 'ERROR');
        } else {
            self::api("API Request: {$method} {$endpoint}", $context, 'INFO');
        }
    }

    public static function logConnectionError(string $message, array $context = []): void
    {
        self::connection($message, $context, 'ERROR');
    }

    public static function logBusinessError(string $message, array $context = []): void
    {
        self::business($message, $context, 'ERROR');
    }

    /**
     * Delete log files older than $days
     */
    public static function clearOldLogs(int $days = 30): int
    {
        $cleared = 0;
        $cutoff = time() - ($days * 86400);

        foreach (self::LOG_FILES as $logFile) {
            foreach ([$logFile, $logFile . '.1'] as $name) {
                $path = self::getLogDir() . DIRECTORY_SEPARATOR . $name;
                if (is_file($path) && filemtime($path) < $cutoff && @unlink($path)) {
                    $cleared++;
                }
            }
        }

        return $cleared;
    }

    public static function getLogSize(string $channel): int
    {
        if (!isset(self::LOG_FILES[$channel])) {
            return 0;
        }

        $path = self::getLogDir() . DIRECTORY_SEPARATOR . self::LOG_FILES[$channel];
        return is_file($path) ? (int) filesize($path) : 0;
    }

    public static function readLastLines(string $channel, int $lines = 100): array
    {
        if (!isset(self::LOG_FILES[$channel])) {
            return [];
        }

        $path = self::getLogDir() . DIRECTORY_SEPARATOR . self::LOG_FILES[$channel];
        if (!is_file($path)) {
            return [];
        }

        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $file->seek(max(0, $file->key() - $lines));

        $result = [];
        while (!$file->eof()) {
            $line = (string) $file->current();
            if (trim($line) !== '') {
                $result[] = $line;
            }
            $file->next();
        }

        return $result;
    }
}
