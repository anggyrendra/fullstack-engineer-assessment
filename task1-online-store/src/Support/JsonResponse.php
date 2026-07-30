<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Support;

/**
 * Small helper to emit a JSON HTTP response with the correct status code and
 * Content-Type header, then stop execution.
 *
 * In tests, call JsonResponse::capture() once: instead of writing headers and
 * exiting, responses are recorded and JsonResponse::lastResponse() returns the
 * most recent [status, payload] pair. This lets the test harness exercise the
 * controllers in-process without an HTTP server.
 */
final class JsonResponse
{
    /** @var array{status:int, body:array<string,mixed>}|null */
    private static ?array $last = null;

    private static bool $capture = false;

    /**
     * Enable capture mode for the current process. Subsequent send()/error()
     * calls will store the response and throw a ResponseSentException instead
     * of calling exit(), so control returns to the caller.
     */
    public static function capture(bool $on = true): void
    {
        self::$capture = $on;
    }

    /**
     * @return array{status:int, body:array<string,mixed>}|null
     */
    public static function lastResponse(): ?array
    {
        return self::$last;
    }

    public static function reset(): void
    {
        self::$last    = null;
        self::$capture = false;
    }

    /**
     * Send a JSON response.
     *
     * @param array<string, mixed>|array<int, mixed> $data
     */
    public static function send(array $data, int $status = 200): never
    {
        if (self::$capture) {
            self::$last = ['status' => $status, 'body' => $data];
            throw new ResponseSentException();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Convenience wrapper for error payloads. Always uses a consistent shape:
     *   { "error": { "code": "...", "message": "..." } }
     */
    public static function error(string $message, int $status, string $code = '', array $extra = []): never
    {
        $payload = [
            'error' => array_filter([
                'code'    => $code,
                'message' => $message,
            ], static fn ($v) => $v !== '') + $extra,
        ];

        self::send($payload, $status);
    }
}
