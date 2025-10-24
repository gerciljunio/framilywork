<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class HelloController
{
    public function index(Request $request): Response
    {
        $name = $request->query['name'] ?? 'mundo';
        return new Response([
            'message' => "Olá, {$name}",
            'params'  => $request->params,
            'query'   => $request->query,
        ]);
    }

    public function show(Request $request): array
    {
        // Retornando array também funciona, o Router embrulha em Response
        return [
            'id' => $request->params['id'] ?? null,
            'detail' => 'Exemplo de leitura por id'
        ];
    }
}
