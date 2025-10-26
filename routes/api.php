<?php

declare(strict_types=1);

use App\Controllers\HelloController;
use App\Http\Middleware\ExampleMiddleware;
use App\Http\Router;

return static function (Router $r): void {
    $r->get('/', fn () => ['status' => 'ok home']);
    $r->get('/health', fn () => ['status' => 'ok health']);

    // Rota com middleware por rota (ExampleMiddleware)
    $r->get('/hello', [HelloController::class, 'index'], [ExampleMiddleware::class]);
    $r->get('/hello/{id}', [HelloController::class, 'show']);
};
