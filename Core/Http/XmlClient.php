<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * XmlClient - XML Web Service client
 * Provides easy XML fetching and parsing
 */

namespace Miko\Core\Http;

class XmlClient
{
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'timeout' => 30,
            'user_agent' => 'Miko/1.0',
            'verify_ssl' => true
        ], $options);
    }

    public static function create(array $options = []): self
    {
        return new self($options);
    }

    public function get(string $url): XmlResponse
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->options['timeout'],
                'user_agent' => $this->options['user_agent']
            ],
            'ssl' => [
                'verify_peer' => $this->options['verify_ssl'],
                'verify_peer_name' => $this->options['verify_ssl']
            ]
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false)
        {
            return new XmlResponse(false, null, "Failed to fetch URL: {$url}");
        }

        $xml = @simplexml_load_string($content);

        if ($xml === false)
        {
            return new XmlResponse(false, null, "Failed to parse XML");
        }

        return new XmlResponse(true, $xml, null);
    }

    public function getWithCurl(string $url): XmlResponse
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->options['timeout'],
            CURLOPT_USERAGENT => $this->options['user_agent'],
            CURLOPT_SSL_VERIFYPEER => $this->options['verify_ssl'],
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $content = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($content === false)
        {
            return new XmlResponse(false, null, $error ?: "cURL request failed");
        }

        $xml = @simplexml_load_string($content);

        if ($xml === false)
        {
            return new XmlResponse(false, null, "Failed to parse XML");
        }

        return new XmlResponse(true, $xml, null);
    }
}

class XmlResponse
{
    private bool $success;
    private ?\SimpleXMLElement $xml;
    private ?string $error;

    public function __construct(bool $success, ?\SimpleXMLElement $xml, ?string $error)
    {
        $this->success = $success;
        $this->xml = $xml;
        $this->error = $error;
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

    public function toArray(): array
    {
        if ($this->xml === null)
        {
            return [];
        }

        return json_decode(json_encode($this->xml), true);
    }

    public function find(string $xpath): array
    {
        if ($this->xml === null)
        {
            return [];
        }

        return $this->xml->xpath($xpath) ?: [];
    }

    public function first(string $xpath): ?\SimpleXMLElement
    {
        $results = $this->find($xpath);
        return $results[0] ?? null;
    }

    public function attribute(string $name): ?string
    {
        if ($this->xml === null)
        {
            return null;
        }

        return isset($this->xml[$name]) ? (string)$this->xml[$name] : null;
    }

    public function value(string $path = null): ?string
    {
        if ($this->xml === null)
        {
            return null;
        }

        if ($path === null)
        {
            return (string)$this->xml;
        }

        $node = $this->first($path);
        return $node ? (string)$node : null;
    }
}
