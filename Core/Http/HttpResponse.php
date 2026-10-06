<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 *
 * HttpResponse - result of an HttpClient request
 */

namespace Miko\Core\Http;

require_once __DIR__ . '/HttpException.php';

class HttpResponse
{
    /** HTTP status code, 0 when no response arrived (see $errno) */
    public int $statusCode = 0;

    /** true for a 2xx response without a transport error */
    public bool $isSuccess = false;

    public string $body = '';

    /** @var array<string, string> response headers of the final response (name as sent => value, repeated headers joined with ", ") */
    public array $headers = [];

    /** cURL error text, '' when the transfer worked */
    public string $error = '';

    /** cURL error number (CURLE_*), 0 when the transfer worked */
    public int $errno = 0;

    /** @var array<string, mixed> curl_getinfo() of the transfer */
    public array $info = [];

    /** @var array<string, list<string>> lower-case header name => values */
    private array $headerValues = [];

    private mixed $decoded = null;
    private bool $isDecoded = false;

    /**
     * @param list<array{0: string, 1: string}> $headerPairs [name, value] in the order received
     */
    public static function make(int $status, string $body, array $headerPairs = [], int $errno = 0, string $error = '', array $info = []): self
    {
        $response = new self();
        $response->statusCode = $status;
        $response->body = $body;
        $response->errno = $errno;
        $response->error = $error;
        $response->info = $info;
        $response->isSuccess = $errno === 0 && $status >= 200 && $status < 300;

        foreach ($headerPairs as [$name, $value]) {
            $lower = strtolower($name);
            $response->headerValues[$lower][] = $value;
            $response->headers[$name] = isset($response->headers[$name]) ? $response->headers[$name] . ', ' . $value : $value;
        }

        return $response;
    }

    // ========================================
    // Status
    // ========================================

    public function status(): int
    {
        return $this->statusCode;
    }

    /** 2xx and no transport error */
    public function ok(): bool
    {
        return $this->isSuccess;
    }

    public function successful(): bool
    {
        return $this->isSuccess;
    }

    public function failed(): bool
    {
        return !$this->isSuccess;
    }

    /** No HTTP response at all (DNS, connect, TLS or timeout error) */
    public function connectionFailed(): bool
    {
        return $this->errno !== 0;
    }

    public function clientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function serverError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * Throw HttpException when the request failed (transport error or non-2xx status)
     */
    public function throwIfFailed(): self
    {
        if (!$this->isSuccess) {
            throw HttpException::fromResponse($this);
        }
        return $this;
    }

    // ========================================
    // Body
    // ========================================

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Decoded JSON body. Without $key: the decoded array, or null when the body is not a JSON object/array.
     * With $key: the value at a dot path ("data.items.0.id"), or $default.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (!$this->isDecoded) {
            $this->decoded = $this->body === '' ? null : json_decode($this->body, true, 512, JSON_BIGINT_AS_STRING);
            $this->isDecoded = true;
        }

        if ($key === null) {
            return is_array($this->decoded) ? $this->decoded : null;
        }

        $value = $this->decoded;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /**
     * Body decoded as stdClass, or null when the body is not a JSON object
     */
    public function object(): ?object
    {
        $data = json_decode($this->body, false, 512, JSON_BIGINT_AS_STRING);
        return is_object($data) ? $data : null;
    }

    // ========================================
    // Headers
    // ========================================

    /**
     * Header value (case-insensitive); repeated headers are joined with ", "
     */
    public function header(string $name): ?string
    {
        $values = $this->headerValues[strtolower($name)] ?? null;
        return $values === null ? null : implode(', ', $values);
    }

    /**
     * Every value of a header (e.g. Set-Cookie)
     *
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        return $this->headerValues[strtolower($name)] ?? [];
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerValues[strtolower($name)]);
    }

    public function contentType(): ?string
    {
        return $this->header('Content-Type');
    }

    // ========================================
    // Transfer info
    // ========================================

    /** Total transfer time in seconds */
    public function duration(): float
    {
        return (float) ($this->info['total_time'] ?? 0);
    }

    /** Final URL after redirects */
    public function effectiveUrl(): string
    {
        return (string) ($this->info['url'] ?? '');
    }
}
