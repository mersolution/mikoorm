<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Core\Http;

/**
 * CORS (Cross-Origin Resource Sharing) Handler
 * 
 * Usage:
 * Cors::handle();                                        // With default settings
 * Cors::handle(['origins' => ['https://example.com']]);  // With custom settings
 * Cors::allowAll();                                      // Allow all origins (development)
 */
class Cors
{
    /**
     * Default CORS settings
     */
    private static array $defaults = [
        'origins' => ['*'],
        'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        'headers' => [
            'Content-Type',
            'Authorization',
            'X-Requested-With',
            'Accept',
            'Origin',
            'X-CSRF-Token'
        ],
        'expose_headers' => [],
        'max_age' => 86400,           // 24 hours
        'credentials' => false,
        'content_type' => 'application/json'
    ];

    /**
     * Set CORS headers
     * 
     * @param array $options Custom settings
     * @return void
     */
    public static function handle(array $options = []): void
    {
        $config = array_merge(self::$defaults, $options);
        
        // Origin check
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        
        if (in_array('*', $config['origins'])) {
            header('Access-Control-Allow-Origin: *');
        } elseif (in_array($origin, $config['origins'])) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
        }
        
        // Methods
        $methods = implode(', ', $config['methods']);
        header("Access-Control-Allow-Methods: {$methods}");
        
        // Headers
        $headers = implode(', ', $config['headers']);
        header("Access-Control-Allow-Headers: {$headers}");
        
        // Expose Headers
        if (!empty($config['expose_headers'])) {
            $exposeHeaders = implode(', ', $config['expose_headers']);
            header("Access-Control-Expose-Headers: {$exposeHeaders}");
        }
        
        // Max Age (preflight cache)
        header("Access-Control-Max-Age: {$config['max_age']}");
        
        // Credentials
        if ($config['credentials']) {
            header('Access-Control-Allow-Credentials: true');
        }
        
        // Content Type
        if ($config['content_type']) {
            header("Content-Type: {$config['content_type']}; charset=utf-8");
        }
        
        // Preflight request (OPTIONS) - respond immediately
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Allow all origins (for development)
     * 
     * @return void
     */
    public static function allowAll(): void
    {
        self::handle([
            'origins' => ['*'],
            'credentials' => false
        ]);
    }

    /**
     * Allow specific origins (for production)
     * 
     * @param array $origins Allowed origins
     * @param bool $credentials Allow cookies/auth headers
     * @return void
     */
    public static function allowOrigins(array $origins, bool $credentials = false): void
    {
        self::handle([
            'origins' => $origins,
            'credentials' => $credentials
        ]);
    }

    /**
     * Standard CORS settings for API
     * 
     * @return void
     */
    public static function api(): void
    {
        self::handle([
            'origins' => ['*'],
            'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'content_type' => 'application/json'
        ]);
    }

    /**
     * Set default settings
     * 
     * @param array $defaults New default settings
     * @return void
     */
    public static function setDefaults(array $defaults): void
    {
        self::$defaults = array_merge(self::$defaults, $defaults);
    }
}
