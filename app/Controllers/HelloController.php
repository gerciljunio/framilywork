<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HelloController
{
    public function index(Request $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $name = $q['name'] ?? 'world';
        $params = $request->getAttribute('route.params', []);
        return json_response([
            'message' => "Hello, {$name}",
            'params'  => $params,
            'query'   => $q,
            'app'     => env('APP_NAME', 'Framilywork')
        ]);
    }

    public function show(Request $request): ResponseInterface
    {
        $id = $request->getAttribute('route.params')['id'] ?? null;
        return json_response(['id' => $id, 'detail' => 'request id: ' . $id]);
    }
}
