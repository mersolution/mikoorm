<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 *
 * XmlClient - XML web service client on HttpClient (keep-alive, timeouts, retries, parallel fetch)
 * Parsing is safe by default: no network access while parsing, libxml errors are collected, not printed.
 */

namespace Miko\Core\Http;

require_once __DIR__ . '/HttpClient.php';

class XmlClient
{
    private array $options;
    private HttpClient $http;

    /**
     * Options: timeout, connect_timeout, user_agent, verify_ssl, ca_bundle, proxy, retries, retry_delay,
     *          headers, base_url, concurrency
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify_ssl' => true,
            'retries' => 0,
            'headers' => [],
        ], $options);

        $config = $this->options;
        $config['headers'] = array_merge(
            ['Accept' => 'application/xml, text/xml;q=0.9, */*;q=0.1'],
            (array) $this->options['headers']
        );
        $this->http = new HttpClient($config);
    }

    public static function create(array $options = []): self
    {
        return new self($options);
    }

    /**
     * GET an XML document
     */
    public function get(string $url, array $query = [], array $headers = []): XmlResponse
    {
        return XmlResponse::fromHttp($this->http->get($url, $headers, $query));
    }

    /**
     * 1.x name of get()
     */
    public function getWithCurl(string $url): XmlResponse
    {
        return $this->get($url);
    }

    /**
     * POST an XML document (string, SimpleXMLElement or DOMDocument)
     */
    public function post(string $url, string|\SimpleXMLElement|\DOMDocument $xml, array $headers = []): XmlResponse
    {
        if ($xml instanceof \SimpleXMLElement) {
            $xml = (string) $xml->asXML();
        } elseif ($xml instanceof \DOMDocument) {
            $xml = (string) $xml->saveXML();
        }

        $headers = array_merge(['Content-Type' => 'application/xml; charset=utf-8'], $headers);
        return XmlResponse::fromHttp($this->http->post($url, $xml, $headers));
    }

    /**
     * Fetch several XML documents in parallel; keys are kept
     *
     * @param array<array-key, string|array> $requests URL strings or HttpClient::pool() request arrays
     * @return array<array-key, XmlResponse>
     */
    public function pool(array $requests, ?int $concurrency = null): array
    {
        return array_map([XmlResponse::class, 'fromHttp'], $this->http->pool($requests, $concurrency));
    }

    /**
     * Parse an XML string
     */
    public static function parse(string $xml): XmlResponse
    {
        [$document, $error] = XmlResponse::load($xml);
        return new XmlResponse($document !== null, $document, $error);
    }

    /**
     * The underlying HttpClient (auth, headers, retries)
     */
    public function http(): HttpClient
    {
        return $this->http;
    }
}

class XmlResponse
{
    private bool $success;
    private ?\SimpleXMLElement $xml;
    private ?string $error;
    private ?HttpResponse $response;

    /** @var array<string, string> prefix => namespace URI for find() / first() / value() */
    private array $namespaces = [];

    public function __construct(bool $success, ?\SimpleXMLElement $xml, ?string $error, ?HttpResponse $response = null)
    {
        $this->success = $success;
        $this->xml = $xml;
        $this->error = $error;
        $this->response = $response;

        if ($xml !== null) {
            foreach ($xml->getDocNamespaces(true) as $prefix => $uri) {
                if ($prefix !== '') {
                    $this->namespaces[$prefix] = $uri;
                }
            }
        }
    }

    public static function fromHttp(HttpResponse $response): self
    {
        if ($response->failed()) {
            $error = $response->errno !== 0 ? $response->error : "HTTP {$response->statusCode}";
            // an XML error body (SOAP fault, API error document) is still parsed for the caller
            [$document] = $response->body !== '' ? self::load($response->body) : [null];
            return new self(false, $document, $error, $response);
        }

        [$document, $error] = self::load($response->body);
        return new self($document !== null, $document, $error, $response);
    }

    /**
     * Parse without network access; returns [document, null] or [null, error message]
     *
     * @return array{0: ?\SimpleXMLElement, 1: ?string}
     */
    public static function load(string $content): array
    {
        if (trim($content) === '') {
            return [null, 'Empty XML document'];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if ($xml === false) {
                $last = libxml_get_last_error();
                $detail = $last ? ': ' . trim($last->message) . " (line {$last->line})" : '';
                return [null, 'Failed to parse XML' . $detail];
            }
            return [$xml, null];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function xml(): ?\SimpleXMLElement
    {
        return $this->xml;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /** HTTP status, 0 for parsed strings or connection errors */
    public function status(): int
    {
        return $this->response?->statusCode ?? 0;
    }

    /** Raw response body */
    public function body(): string
    {
        return $this->response?->body ?? (string) ($this->xml?->asXML() ?: '');
    }

    public function http(): ?HttpResponse
    {
        return $this->response;
    }

    /**
     * Register a prefix for XPath queries (needed for a default namespace: xmlns="...")
     */
    public function registerNamespace(string $prefix, string $uri): self
    {
        $this->namespaces[$prefix] = $uri;
        return $this;
    }

    public function toArray(): array
    {
        if ($this->xml === null) {
            return [];
        }

        $json = json_encode($this->xml, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $data = $json === false ? null : json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    public function find(string $xpath): array
    {
        if ($this->xml === null) {
            return [];
        }

        foreach ($this->namespaces as $prefix => $uri) {
            $this->xml->registerXPathNamespace($prefix, $uri);
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $result = $this->xml->xpath($xpath);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $result ?: [];
    }

    public function first(string $xpath): ?\SimpleXMLElement
    {
        $results = $this->find($xpath);
        return $results[0] ?? null;
    }

    public function attribute(string $name): ?string
    {
        if ($this->xml === null) {
            return null;
        }

        return isset($this->xml[$name]) ? (string) $this->xml[$name] : null;
    }

    public function value(?string $path = null): ?string
    {
        if ($this->xml === null) {
            return null;
        }

        if ($path === null) {
            return (string) $this->xml;
        }

        $node = $this->first($path);
        return $node !== null ? (string) $node : null;
    }
}
