<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 *
 * SoapClient - SOAP Web Service client wrapper (requires extension=soap)
 *
 * - WSDL is cached in memory and on disk (WSDL_CACHE_BOTH) instead of being downloaded on every request
 * - real response timeout ('timeout'), not only the connect timeout
 * - keep-alive, gzip responses, TLS verification switch, per call SOAP headers
 * - every failure (also an unreachable WSDL) comes back as a SoapResponse, never as an uncaught SoapFault
 */

namespace Miko\Core\Http;

use SoapClient as PhpSoapClient;
use SoapFault;

class SoapClient
{
    /** Options handled by this wrapper, never passed to \SoapClient */
    private const OWN_OPTIONS = ['timeout', 'verify_ssl', 'ca_bundle'];

    private ?string $wsdl;
    private array $options;
    private ?PhpSoapClient $client = null;
    private ?string $lastRequest = null;
    private ?string $lastResponse = null;
    private ?SoapFault $lastError = null;

    /**
     * @param string|null $wsdl    WSDL URL / file, or null for non-WSDL mode (then 'location' and 'uri' are required)
     * @param array       $options \SoapClient options plus: timeout (s, response timeout), verify_ssl, ca_bundle
     */
    public function __construct(?string $wsdl, array $options = [])
    {
        if (!extension_loaded('soap')) {
            throw new \RuntimeException('SoapClient needs the PHP soap extension (extension=soap in php.ini).');
        }

        if ($wsdl === null && (empty($options['location']) || empty($options['uri']))) {
            throw new \InvalidArgumentException('Non-WSDL mode needs the "location" and "uri" options.');
        }

        $this->wsdl = $wsdl;
        $this->options = array_merge([
            'trace' => true,
            'exceptions' => true,
            'connection_timeout' => 10,
            'timeout' => 30,
            'cache_wsdl' => WSDL_CACHE_BOTH,
            'soap_version' => SOAP_1_1,
            'encoding' => 'UTF-8',
            'keep_alive' => true,
            'compression' => SOAP_COMPRESSION_ACCEPT,
            'user_agent' => 'MikoORM-SoapClient/' . (class_exists(\Miko\Core\Version::class) ? \Miko\Core\Version::VERSION : '2'),
            'verify_ssl' => true,
            'ca_bundle' => null,
        ], $options);
    }

    public static function create(?string $wsdl, array $options = []): self
    {
        return new self($wsdl, $options);
    }

    /**
     * Call an operation. $params is sent as the single (document/literal wrapped) argument.
     *
     * @param array $headers \SoapHeader list for this call only
     */
    public function call(string $method, array $params = [], array $headers = []): SoapResponse
    {
        $this->lastError = null;
        $this->lastRequest = null;
        $this->lastResponse = null;

        $started = microtime(true);
        $outputHeaders = [];

        try {
            $result = $this->withTimeout(function () use ($method, $params, $headers, &$outputHeaders) {
                return $this->getClient()->__soapCall($method, [$params], null, $headers ?: null, $outputHeaders);
            });

            $this->captureTrace();
            return new SoapResponse(true, $result, null, null, (array) $outputHeaders, microtime(true) - $started);
        } catch (SoapFault $e) {
            $this->lastError = $e;
            $this->captureTrace();
            return new SoapResponse(false, null, $e->getMessage(), $e->faultcode ?? null, (array) $outputHeaders, microtime(true) - $started);
        }
    }

    public function __call(string $method, array $arguments): SoapResponse
    {
        $params = $arguments[0] ?? [];
        return $this->call($method, is_array($params) ? $params : [$params], $arguments[1] ?? []);
    }

    public function getFunctions(): array
    {
        return $this->withTimeout(fn() => $this->getClient()->__getFunctions()) ?? [];
    }

    public function getTypes(): array
    {
        return $this->withTimeout(fn() => $this->getClient()->__getTypes()) ?? [];
    }

    public function getLastRequest(): ?string
    {
        return $this->lastRequest;
    }

    public function getLastResponse(): ?string
    {
        return $this->lastResponse;
    }

    public function getLastError(): ?SoapFault
    {
        return $this->lastError;
    }

    public function setLocation(string $location): self
    {
        $this->options['location'] = $location;
        $this->client?->__setLocation($location);
        return $this;
    }

    /**
     * SOAP headers sent with every following call
     */
    public function setSoapHeaders(array $headers): self
    {
        $this->options['_headers'] = $headers;
        $this->client?->__setSoapHeaders($headers ?: null);
        return $this;
    }

    /**
     * Change the response timeout (seconds)
     */
    public function setTimeout(float $seconds): self
    {
        $this->options['timeout'] = $seconds;
        return $this;
    }

    private function getClient(): PhpSoapClient
    {
        if ($this->client === null) {
            // an unreachable / invalid WSDL becomes a SoapFault without libxml warnings on the page
            $previous = libxml_use_internal_errors(true);
            try {
                $this->client = new PhpSoapClient($this->wsdl, $this->nativeOptions());
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }

            if (!empty($this->options['_headers'])) {
                $this->client->__setSoapHeaders($this->options['_headers']);
            }
        }
        return $this->client;
    }

    private function nativeOptions(): array
    {
        $options = $this->options;
        foreach (self::OWN_OPTIONS as $name) {
            unset($options[$name]);
        }
        unset($options['_headers']);

        if (!isset($options['stream_context'])) {
            $ssl = [
                'verify_peer' => (bool) $this->options['verify_ssl'],
                'verify_peer_name' => (bool) $this->options['verify_ssl'],
            ];
            if (!empty($this->options['ca_bundle'])) {
                $ssl['cafile'] = (string) $this->options['ca_bundle'];
            }
            $options['stream_context'] = stream_context_create(['ssl' => $ssl]);
        }

        return $options;
    }

    /**
     * \SoapClient reads responses with default_socket_timeout; set it for this call only
     */
    private function withTimeout(callable $callback): mixed
    {
        // default_socket_timeout is whole seconds
        $timeout = (float) $this->options['timeout'];
        $previous = $timeout > 0 ? ini_set('default_socket_timeout', (string) max(1, (int) ceil($timeout))) : false;

        try {
            return $callback();
        } finally {
            if ($previous !== false) {
                ini_set('default_socket_timeout', $previous);
            }
        }
    }

    private function captureTrace(): void
    {
        if ($this->client !== null && $this->options['trace']) {
            $this->lastRequest = $this->client->__getLastRequest();
            $this->lastResponse = $this->client->__getLastResponse();
        }
    }
}

class SoapResponse
{
    private bool $success;
    private mixed $data;
    private ?string $error;
    private ?string $faultCode;
    private array $outputHeaders;
    private float $duration;

    public function __construct(bool $success, mixed $data, ?string $error, ?string $faultCode = null, array $outputHeaders = [], float $duration = 0.0)
    {
        $this->success = $success;
        $this->data = $data;
        $this->error = $error;
        $this->faultCode = $faultCode;
        $this->outputHeaders = $outputHeaders;
        $this->duration = $duration;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /** SOAP fault code (e.g. "SOAP-ENV:Server", "HTTP", "WSDL") */
    public function faultCode(): ?string
    {
        return $this->faultCode;
    }

    /** SOAP headers returned by the server */
    public function outputHeaders(): array
    {
        return $this->outputHeaders;
    }

    /** Call duration in seconds */
    public function duration(): float
    {
        return $this->duration;
    }

    /**
     * Throw a \RuntimeException when the call failed
     */
    public function throwIfFailed(): self
    {
        if (!$this->success) {
            throw new \RuntimeException('SOAP ' . ($this->faultCode ?? 'error') . ': ' . $this->error);
        }
        return $this;
    }

    public function toArray(): array
    {
        if (is_object($this->data)) {
            $json = json_encode($this->data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $decoded = $json === false ? null : json_decode($json, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($this->data)) {
            return $this->data;
        }

        return ['raw' => $this->data];
    }

    public function json(): ?array
    {
        return $this->toArray();
    }
}
