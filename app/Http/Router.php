<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Router implements RequestHandlerInterface
{
    private array $routes = [];

    public function get(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->map('GET', $path, $handler, $middlewares);
    }
    public function post(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->map('POST', $path, $handler, $middlewares);
    }
    public function put(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->map('PUT', $path, $handler, $middlewares);
    }
    public function patch(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->map('PATCH', $path, $handler, $middlewares);
    }
    public function delete(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->map('DELETE', $path, $handler, $middlewares);
    }

    public function map(string $method, string $path, mixed $handler, array $middlewares = []): void
    {
        $this->routes[$method][] = [
            'pattern'     => $this->compile($path),
            'handler'     => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function handle(Request $request): ResponseInterface
    {
        $method = $request->getMethod();
        $uri    = rtrim($request->getUri()->getPath(), '/') ?: '/';

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $uri, $m)) {
                $params = [];
                foreach ($m as $k => $v) {
                    if (!is_int($k)) {
                        $params[$k] = $v;
                    }
                }

                $request = $request
                    ->withAttribute('route.params', $params)
                    ->withAttribute('route.path', $uri);

                return $this->runRouteMiddlewares(
                    $request,
                    $route['middlewares'],
                    fn (Request $req) => $this->invokeToResponse($route['handler'], $req)
                );
            }
        }

        return new Response(404, ['Content-Type' => 'application/json; charset=utf-8'], json_encode([
            'error' => 'Route not found',
            'path'  => $uri
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function compile(string $path): string
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . rtrim($regex, '/') . '/?$#';
    }

    private function invokeToResponse(mixed $handler, Request $request): ResponseInterface
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
            $class  = $handler[0];
            $method = $handler[1];
            if (!class_exists($class)) {
                throw new InvalidArgumentException("Controller {$class} not found");
            }
            $instance = new $class();
            if (!method_exists($instance, $method)) {
                throw new InvalidArgumentException("Method {$method} not found in {$class}");
            }
            $result = $instance->$method($request);
            return $this->toResponse($result);
        }

        if (is_callable($handler)) {
            $result = $handler($request);
            return $this->toResponse($result);
        }

        throw new InvalidArgumentException('Invalid route handler');
    }

    private function toResponse(mixed $result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        return new Response(
            200,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Executes PSR-15 pipeline for current route only.
     *
     * @param array<int, MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     * @param callable(Request):ResponseInterface $final
     */
    private function runRouteMiddlewares(Request $request, array $middlewares, callable $final): ResponseInterface
    {
        $handler = new class ($final) implements RequestHandlerInterface {
            /** @var callable */
            private $final;
            public function __construct(callable $final)
            {
                $this->final = $final;
            }
            public function handle(Request $request): ResponseInterface
            {
                return ($this->final)($request);
            }
        };

        $stack = array_map(function ($mw) {
            if (is_string($mw)) {
                $mw = new $mw();
            }
            if (!$mw instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('Invalid middleware in route');
            }
            return $mw;
        }, $middlewares);

        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $next = $handler;
            $current = $stack[$i];
            $handler = new class ($current, $next) implements RequestHandlerInterface {
                public function __construct(
                    private MiddlewareInterface $mw,
                    private RequestHandlerInterface $next
                ) {
                }
                public function handle(Request $request): ResponseInterface
                {
                    return $this->mw->process($request, $this->next);
                }
            };
        }

        return $handler->handle($request);
    }
}
