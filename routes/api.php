<?php

declare(strict_types=1);

use App\Controllers\HelloController;
use App\Core\Router;

return static function (Router $router): void {
    $router->get('/health', fn () => ['status' => 'ok']);

    $router->get('/hello', [HelloController::class, 'index']);
    $router->get('/hello/{id}', [HelloController::class, 'show']);

    // Exemplos:
    // $router->post('/users', [UserController::class, 'store']);
    // $router->put('/users/{id}', [UserController::class, 'update']);
    // $router->delete('/users/{id}', [UserController::class, 'delete']);
};
