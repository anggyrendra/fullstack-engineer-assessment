<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Support;

/**
 * Thin request helper.
 *
 * Reads the raw request body from php://input in production, but allows tests
 * to inject a fixed body via Request::setBody() so the controllers can be
 * exercised in-process without an HTTP server. It also exposes the HTTP
 * method and path in a testable way.
 */
final class Request
{
    private static ?string $body   = null;
    private static ?string $method = null;
    private static ?string $path   = null;

    public static function setBody(string $body): void
    {
        self::$body = $body;
    }

    public static function setMethod(string $method): void
    {
        self::$method = $method;
    }

    public static function setPath(string $path): void
    {
        self::$path = $path;
    }

    public static function method(): string
    {
        return strtoupper(self::$method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function path(): string
    {
        $uri = self::$path ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    public static function body(): string
    {
        if (self::$body !== null) {
            return self::$body;
        }
        return (string) file_get_contents('php://input');
    }

    /**
     * @return array<string, mixed>
     */
    public static function jsonBody(): array
    {
        $raw = self::body();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function reset(): void
    {
        self::$body   = null;
        self::$method = null;
        self::$path   = null;
    }
}
