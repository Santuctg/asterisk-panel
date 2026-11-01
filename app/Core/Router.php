<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Regex based router with {param} placeholders.
 *
 *   $router->get('/extensions/{id}', [ExtensionController::class, 'show']);
 *   $router->post('/extensions', [ExtensionController::class, 'store']);
 */
final class Router
{
    /** @var array<string, array<int, array{pattern:string, handler:mixed, middleware:array}>> */
    private array $routes = [];

    /** @var array<int, callable> */
    private array $globalMiddleware = [];

    public function middleware(callable $mw): void
    {
        $this->globalMiddleware[] = $mw;
    }

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, mixed $handler, array $middleware): void
    {
        $this->routes[$method][] = [
            'pattern'    => $this->compile($path),
            'handler'    => $handler,
            'middleware' => $middleware,
            'path'       => $path,
        ];
    }

    private function compile(string $path): string
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }

    public function dispatch(Request $request): Response
    {
        // Method spoofing for HTML forms.
        if ($request->method === 'POST' && $request->input('_method')) {
            $spoofed = strtoupper((string) $request->input('_method'));
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                $request = new Request($spoofed, $request->path, $_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
            }
        }

        foreach ($this->routes[$request->method] ?? [] as $route) {
            if (!preg_match($route['pattern'], $request->path, $m)) {
                continue;
            }

            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $request->params[$k] = $v;
                }
            }

            $pipeline = array_merge($this->globalMiddleware, $route['middleware']);
            $handler  = $this->resolve($route['handler']);

            return $this->runPipeline($pipeline, $request, static fn (Request $req) => $handler($req));
        }

        return $this->notFound($request);
    }

    private function resolve(mixed $handler): callable
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            return static function (Request $req) use ($class, $method) {
                $controller = new $class();
                return $controller->$method($req);
            };
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            return static function (Request $req) use ($class, $method) {
                $controller = new $class();
                return $controller->$method($req);
            };
        }

        return $handler;
    }

    /** @param array<int, callable> $pipeline */
    private function runPipeline(array $pipeline, Request $request, callable $destination): Response
    {
        $next = array_reduce(
            array_reverse($pipeline),
            static fn (callable $prev, callable $mw): callable => static fn (Request $req) => $mw($req, $prev),
            $destination
        );

        $result = $next($request);

        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::html((string) $result);
    }

    private function notFound(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => 'Not found'], 404);
        }

        return Response::html(
            View::getInstance()->render('errors/404', ['path' => $request->path]),
            404
        );
    }
}
