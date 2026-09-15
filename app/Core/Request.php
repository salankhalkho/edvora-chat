<?php

namespace App\Core;

class Request
{
    private string $method;
    private string $uri;
    private array $headers;
    private array $queryParams;
    private array $bodyParams;
    private ?array $jsonBody = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->uri = parse_url($rawUri, PHP_URL_PATH) ?: '/';
        $this->headers = $this->extractHeaders();
        $this->queryParams = $_GET;
        $this->bodyParams = $_POST;

        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $decoded = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->jsonBody = $decoded;
            }
        }
    }

    private function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strtolower($k) === strtolower($name)) {
                return $v;
            }
        }
        return $default;
    }

    public function getBearerToken(): ?string
    {
        $authHeader = $this->getHeader('Authorization');
        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }
        if (!empty($this->queryParams['token'])) {
            return trim($this->queryParams['token']);
        }
        return null;
    }

    public function getBotToken(): ?string
    {
        return $this->getHeader('X-Bot-Token') ?? $this->get('bot') ?? $this->get('bot_token');
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->queryParams;
        }
        return $this->queryParams[$key] ?? $default;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->jsonBody !== null && array_key_exists($key, $this->jsonBody)) {
            return $this->jsonBody[$key];
        }
        if (array_key_exists($key, $this->bodyParams)) {
            return $this->bodyParams[$key];
        }
        if (array_key_exists($key, $this->queryParams)) {
            return $this->queryParams[$key];
        }
        return $default;
    }

    public function all(): array
    {
        return array_merge($this->queryParams, $this->bodyParams, $this->jsonBody ?? []);
    }

    public function getBody(): array
    {
        return $this->all();
    }

    public function getClientIp(): string
    {
        return $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->jsonBody === null) {
            $input = file_get_contents('php://input');
            if (!empty($input)) {
                $decoded = json_decode($input, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $this->jsonBody = $decoded;
                }
            }
        }
        $body = $this->jsonBody ?? [];
        if ($key === null) {
            return $body;
        }
        return $body[$key] ?? $default;
    }

    public function getFile(string $name): ?array
    {
        return $_FILES[$name] ?? null;
    }
}
