<?php

namespace App\Core;

class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler, array $middlewares = []): void
    {
        $pattern = preg_replace_callback('/\{([a-zA-Z0-9_]+)(?::([^}]+))?\}/', function ($matches) {
            $name = $matches[1];
            $regex = $matches[2] ?? ($name === 'id' ? '[0-9]+' : '[^/]+');
            return "(?P<{$name}>{$regex})";
        }, $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler,
            'middlewares' => $middlewares
        ];
    }

    public function get(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('PUT', $path, $handler, $middlewares);
    }

    public function patch(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('PATCH', $path, $handler, $middlewares);
    }

    public function delete(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $path, $handler, $middlewares);
    }

    public function dispatch(Request $request): void
    {
        if ($request->getMethod() === 'OPTIONS') {
            Response::json(['status' => 'ok'], 200);
        }

        $method = $request->getMethod();
        $matchMethod = ($method === 'HEAD') ? 'GET' : $method;
        $uri = $request->getUri();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $matchMethod) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Execute middlewares
                foreach ($route['middlewares'] as $middleware) {
                    if (is_string($middleware)) {
                        $middlewareObj = new $middleware();
                        $middlewareObj->handle($request, $params);
                    } elseif (is_callable($middleware)) {
                        $middleware($request, $params);
                    }
                }

                // Execute handler
                $handler = $route['handler'];
                if (is_array($handler)) {
                    list($controllerClass, $action) = $handler;
                    $controller = new $controllerClass();
                    $controller->$action($request, $params);
                } elseif (is_callable($handler)) {
                    $handler($request, $params);
                }
                return;
            }
        }

        Response::error("Route {$method} {$uri} not found", 404);
    }
}
