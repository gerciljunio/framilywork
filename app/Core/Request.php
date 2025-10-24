<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $body,
        public readonly array $params = [],
    ) {}

    public static function fromGlobals(): self
    {
        $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = self::headers();
        $query   = $_GET ?? [];

        $raw = file_get_contents('php://input') ?: '';
        $parsedBody = [];

        $contentType = $headers['content-type'] ?? $headers['Content-Type'] ?? '';
        if (stripos($contentType, 'application/json') !== false && $raw !== '') {
            $parsedBody = json_decode($raw, true) ?? [];
        } else {
            $parsedBody = $_POST ?: [];
        }

        return new self($method, $uri, $headers, $query, $parsedBody);
    }

    private static function headers(): array
    {
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $out[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $out['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $out['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        return $out;
    }

    public function withParams(array $params): self
    {
        return new self($this->method, $this->uri, $this->headers, $this->query, $this->body, $params);
    }
}