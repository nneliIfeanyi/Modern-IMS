<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use Closure;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler, array $middlewares = []): void
    {
        $method = strtoupper($method);
        $normalized = $this->normalizePath($path);

        $this->routes[$method][] = [
            'path' => $normalized,
            'pattern' => $this->pathToRegex($normalized),
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function dispatch(Request $request): HttpResponse
    {
        $methodRoutes = $this->routes[$request->method()] ?? [];

        foreach ($methodRoutes as $route) {
            if (!preg_match($route['pattern'], $request->path(), $matches)) {
                continue;
            }

            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $request->setAttribute($key, $value);
                }
            }

            $handler = $this->makeCallable($route['handler']);
            $pipeline = $this->buildPipeline($route['middlewares'], $handler);

            return $pipeline($request);
        }

        return Response::error('Route not found.', null, 404);
    }

    private function buildPipeline(array $middlewares, callable $handler): Closure
    {
        $next = static fn(Request $request): HttpResponse => $handler($request);

        foreach (array_reverse($middlewares) as $middleware) {
            $current = $this->resolveMiddleware($middleware);
            $next = static fn(Request $request): HttpResponse => $current($request, $next);
        }

        return $next;
    }

    private function resolveMiddleware(callable|array|string $middleware): callable
    {
        if (is_string($middleware)) {
            if ($middleware === 'auth') {
                return [new AuthMiddleware(), 'handle'];
            }

            if (str_starts_with($middleware, 'role:')) {
                $roles = explode(',', substr($middleware, 5));
                return [new RoleMiddleware($roles), 'handle'];
            }
        }

        return $this->makeCallable($middleware);
    }

    private function makeCallable(callable|array|string $handler): callable
    {
        if (is_callable($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            $instance = new $handler[0]();
            return [$instance, $handler[1]];
        }

        if (is_string($handler) && class_exists($handler)) {
            $instance = new $handler();
            if (method_exists($instance, 'handle')) {
                return [$instance, 'handle'];
            }
            if (is_callable($instance)) {
                return $instance;
            }
        }

        throw new \RuntimeException('Invalid route handler/middleware supplied.');
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '//' ? '/' : $path;
    }

    private function pathToRegex(string $path): string
    {
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }
}
