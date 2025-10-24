<?php

declare(strict_types=1);

use App\Core\Env;

if (!function_exists('json_response')) {
    /**
     * Envia resposta JSON
     */
    function json_response(mixed $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }

        try {
            echo json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Falha ao serializar JSON',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}

if (!function_exists('env')) {
    /**
     * Lê variável de ambiente do .env ou retorna valor padrão
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}
