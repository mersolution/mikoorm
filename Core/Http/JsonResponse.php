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

use JetBrains\PhpStorm\NoReturn;

/**
 * JsonResponse - Standardized JSON API responses
 * Compatible with existing frontend format
 */
class JsonResponse
{
    /**
     * @param mixed|null $data
     * @param string|null $message
     * @param int $code
     * @return void
     */
    #[NoReturn]
    public static function success(mixed $data = null, ?string $message = null, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        $response = ['status' => 'SUCCESS'];

        if ($data !== null) {
            if (is_array($data) && !self::isAssoc($data)) {
                $response['data'] = $data;
            } else {
                $response = array_merge($response, is_array($data) ? $data : ['data' => $data]);
            }
        }

        if ($message !== null) {
            $response['message'] = $message;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * @param string $status
     * @param int $code
     * @return void
     */
    #[NoReturn]
    public static function status(string $status, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(['status' => $status], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * @param string $status
     * @param int $code
     * @return void
     */
    #[NoReturn]
    public static function error(string $status, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(['status' => $status], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * @param array $arr
     * @return bool
     */
    private static function isAssoc(array $arr): bool
    {
        if (empty($arr)) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * @param array $errors
     * @param string $status
     * @return void
     */
    #[NoReturn]
    public static function validationError(array $errors, string $status = 'VALIDATION_ERROR'): void
    {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'status' => $status,
            'errors' => $errors
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * @param string $status
     * @return void
     */
    #[NoReturn]
    public static function notFound(string $status = 'NOT_FOUND'): void
    {
        self::error($status, 404);
    }

    /**
     * @param string $status
     * @return void
     */
    #[NoReturn]
    public static function unauthorized(string $status = 'UNAUTHORIZED'): void
    {
        self::error($status, 401);
    }

    /**
     * @param string $status
     * @return void
     */
    #[NoReturn]
    public static function forbidden(string $status = 'FORBIDDEN'): void
    {
        self::error($status, 403);
    }

    /**
     * @param string $status
     * @return void
     */
    #[NoReturn]
    public static function serverError(string $status = 'SERVER_ERROR'): void
    {
        self::error($status, 500);
    }

    /**
     * @param mixed|null $data
     * @param string $message
     * @return void
     */
    #[NoReturn]
    public static function created(mixed $data = null, string $message = 'Created successfully'): void
    {
        self::success($data, $message, 201);
    }

    /**
     * @return void
     */
    #[NoReturn]
    public static function noContent(): void
    {
        http_response_code(204);
        exit;
    }


    /**
     * @param array $data
     * @param array $meta
     * @return void
     */
    #[NoReturn]
    public static function paginated(array $data, array $meta): void
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'status' => 'SUCCESS',
            'data' => $data,
            'meta' => $meta
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }


    /**
     * @param mixed $data
     * @param int $code
     * @return string
     */
    public static function json(mixed $data, int $code = 200): string
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
