<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 *
 * HttpException - thrown by HttpResponse::throwIfFailed() and by HttpClient for transport errors
 */

namespace Miko\Core\Http;

class HttpException extends \RuntimeException
{
    private ?HttpResponse $response;

    public function __construct(string $message, int $code = 0, ?HttpResponse $response = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    /**
     * Build the exception for a failed response.
     * Code = HTTP status, or the cURL error number when the request never got a response.
     */
    public static function fromResponse(HttpResponse $response): self
    {
        if ($response->errno !== 0) {
            return new self("Connection error ({$response->errno}): {$response->error}", $response->errno, $response);
        }

        $body = trim($response->body);
        if (strlen($body) > 300) {
            $body = substr($body, 0, 300) . '...';
        }

        return new self(trim("HTTP {$response->statusCode}: {$body}"), $response->statusCode, $response);
    }

    public function getResponse(): ?HttpResponse
    {
        return $this->response;
    }
}
