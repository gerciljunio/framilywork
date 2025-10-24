<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error'   => 'Erro interno',
        'type'    => $e::class,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

$router = new Router();
$routes = require __DIR__ . '/../routes/api.php';
$routes($router);

$request  = Request::fromGlobals();
$response = $router->dispatch($request);

if ($response instanceof Response) {
    $response->send();
}
