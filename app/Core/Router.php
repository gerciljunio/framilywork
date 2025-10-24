<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class Router
{
    private array $routes = [];

    public function get(string $path, mixed $handler): void
    {
        $this->map('GET', $path, $handler);
    }
    public function post(string $path, mixed $handler): void
    {
        $this->map('POST', $path, $handler);
    }
    public function put(string $path, mixed $handler): void
    {
        $this->map('PUT', $path, $handler);
    }
    public function patch(string $path, mixed $handler): void
    {
        $this->map('PATCH', $path, $handler);
    }
    public function delete(string $path, mixed $handler): void
    {
        $this->map('DELETE', $path, $handler);
    }

    public function map(string $method, string $path, mixed $handler): void
    {
        $pattern = $this->compile($path);
        $this->routes[$method][] = ['pattern' => $pattern, 'callback' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        $set = $this->routes[$request->method] ?? [];
        foreach ($set as $route) {
            $matches = [];
            if (preg_match($route['pattern'], $request->uri, $matches)) {
                $params = [];
                foreach ($matches as $k => $v) {
                    if (!is_int($k)) {
                        $params[$k] = $v;
                    }
                }
                $req = $request->withParams($params);
                $handler = $route['callback'];
                $result = $this->invoke($handler, $req);
                return $result instanceof Response ? $result : new Response($result, 200);
            }
        }
        return new Response(['error' => 'Route not found', 'path' => $request->uri], 404);
    }

    private function compile(string $path): string
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        $regex = '#^' . rtrim($regex, '/') . '/?$#';
        return $regex;
    }

    private function invoke(mixed $handler, Request $req): mixed
    {
        // Controller no formato [ClassName::class, 'method']
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && is_string($handler[1])) {
            $class = $handler[0];
            $method = $handler[1];
            if (!class_exists($class)) {
                throw new InvalidArgumentException("Controller {$class} not found");
            }
            $instance = new $class();
            if (!method_exists($instance, $method)) {
                throw new InvalidArgumentException("Method {$method} not found in {$class}");
            }
            return $instance->$method($req);
        }

        // Closure ou função
        if (is_callable($handler)) {
            return $handler($req);
        }

        throw new InvalidArgumentException('Invalid Route Handler');
    }
}
