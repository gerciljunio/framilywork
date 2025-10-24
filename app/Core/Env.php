<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path = __DIR__ . '/../../.env'): void
    {
        if (self::$loaded || !file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Limpa linha e ignora comentários/vazios
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Divide chave e valor
            [$name, $rawValue] = array_map('trim', explode('=', $line, 2));

            // Divide chave e valor
            // isset($rawValue[0]) garante que o valor NÃO é vazio antes de acessar caracteres por índice.
            $quoted = isset($rawValue[0]) && (
                ($rawValue[0] === '"' && substr($rawValue, -1) === '"') ||
                ($rawValue[0] === "'" && substr($rawValue, -1) === "'")
            );

            // Remove aspas apenas se estavam presentes
            $value = $quoted ? substr($rawValue, 1, -1) : $rawValue;

            // Normalização de tokens comuns
            $lower = strtolower($value);
            if ($lower === 'true') {
                $value = true;
            } elseif ($lower === 'false') {
                $value = false;
            } elseif ($lower === 'null') {
                $value = null;
            } elseif ($value === '' && !$quoted) {
                // Sem aspas e vazio => trata como "sem valor"
                $value = null;
            }

            self::$vars[$name] = $value;
            // Propaga para getenv/$_ENV se quiser
            if (is_scalar($value) || $value === null) {
                putenv("$name=" . (is_bool($value) ? ($value ? 'true' : 'false') : (string)($value ?? '')));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$vars[$key] ?? getenv($key) ?? $_ENV[$key] ?? null;

        // Se o valor for null ou string vazia, usa o default
        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }
}
