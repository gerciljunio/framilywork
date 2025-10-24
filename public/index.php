<?php

declare(strict_types=1);

use App\Http\Middleware\CorsMiddleware;
use App\Http\Router;
use App\Support\Env;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Relay\Relay;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$psr17 = new Psr17Factory();
$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$request = $creator->fromGlobals();

$router = new Router();
(require __DIR__ . '/../routes/api.php')($router);

// Global middlewares
$queue = [
    new CorsMiddleware(),
    $router
];

$response = (new Relay($queue))->handle($request);

// Garanta que o emissor SAPI não rode sob Swoole
if (!isset($_SERVER['FW_SWOOLE_BRIDGE'])) {
    $emitter = new SapiEmitter();
    $emitter->emit($response);
}
