<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Support;

/**
 * Minimal dependency-free HTTP router.
 *
 * Routes are registered with the HTTP method + a path pattern. Path segments
 * wrapped in braces (e.g. "{id}") are captured as named parameters and passed
 * to the controller callable.
 *
 * Example:
 *   $router->add('GET', '/products/{id}', [ProductController::class, 'show']);
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:callable, params:string[]}> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $params = [];

        // Convert "/products/{id}" into a regex with a named capture group.
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $path
        ) ?? $path;

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex'  => '#^' . $regex . '$#',
            'handler'=> $handler,
            'params' => $params,
        ];
    }

    /**
     * Dispatch the current request. Returns an array with the handler and the
     * extracted parameters, or null when no route matches.
     *
     * @return array{handler: callable, params: array<string,string>}|null
     */
    public function dispatch(string $method, string $path): ?array
    {
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches)) {
                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i + 1];
                }
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }

        return null;
    }
}
