<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * SoapClient - SOAP Web Service client wrapper
 * Provides a fluent interface for SOAP operations
 */

namespace Miko\Core\Http;

use SoapClient as PhpSoapClient;
use SoapFault;

class SoapClient
{
    private string $wsdl;
    private array $options;
    private ?PhpSoapClient $client = null;
    private ?string $lastRequest = null;
    private ?string $lastResponse = null;
    private ?SoapFault $lastError = null;

    public function __construct(string $wsdl, array $options = [])
    {
        $this->wsdl = $wsdl;
        $this->options = array_merge([
            'trace' => true,
            'exceptions' => true,
            'connection_timeout' => 30,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'soap_version' => SOAP_1_1,
            'encoding' => 'UTF-8'
        ], $options);
    }

    public static function create(string $wsdl, array $options = []): self
    {
        return new self($wsdl, $options);
    }

    private function getClient(): PhpSoapClient
    {
        if ($this->client === null) {
            $this->client = new PhpSoapClient($this->wsdl, $this->options);
        }
        return $this->client;
    }

    public function call(string $method, array $params = []): SoapResponse
    {
        $this->lastError = null;
        $this->lastRequest = null;
        $this->lastResponse = null;

        try {
            $result = $this->getClient()->__soapCall($method, [$params]);
            
            if ($this->options['trace']) {
                $this->lastRequest = $this->getClient()->__getLastRequest();
                $this->lastResponse = $this->getClient()->__getLastResponse();
            }

            return new SoapResponse(true, $result, null);

        } catch (SoapFault $e) {
            $this->lastError = $e;
            
            if ($this->options['trace']) {
                $this->lastRequest = $this->getClient()->__getLastRequest();
                $this->lastResponse = $this->getClient()->__getLastResponse();
            }

            return new SoapResponse(false, null, $e->getMessage());
        }
    }

    public function __call(string $method, array $arguments): SoapResponse
    {
        $params = $arguments[0] ?? [];
        return $this->call($method, $params);
    }

    public function getFunctions(): array
    {
        return $this->getClient()->__getFunctions();
    }

    public function getTypes(): array
    {
        return $this->getClient()->__getTypes();
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
        $this->getClient()->__setLocation($location);
        return $this;
    }

    public function setSoapHeaders(array $headers): self
    {
        $this->getClient()->__setSoapHeaders($headers);
        return $this;
    }
}

class SoapResponse
{
    private bool $success;
    private mixed $data;
    private ?string $error;

    public function __construct(bool $success, mixed $data, ?string $error)
    {
        $this->success = $success;
        $this->data = $data;
        $this->error = $error;
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

    public function toArray(): array
    {
        if (is_object($this->data)) {
            return json_decode(json_encode($this->data), true);
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
