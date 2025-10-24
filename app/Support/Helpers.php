<?php

declare(strict_types=1);

use App\Support\Env;
use Nyholm\Psr7\Response;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('json')) {
    function json_response(mixed $data, int $status = 200, array $headers = []): Response
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json; charset=utf-8'] + $headers;
        return new Response($status, $headers, $body);
    }
}
