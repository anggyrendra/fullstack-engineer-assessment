<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Support;

/**
 * Tiny .env loader / config reader.
 *
 * It parses a .env file (KEY=VALUE, ";" comments) on first use and exposes
 * values through Config::get(). Environment variables always override the
 * values found in the file.
 */
final class Config
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function load(string $path = __DIR__ . '/../../.env'): void
    {
        if (self::$cache === null) {
            self::$cache = [];
        }

        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip blank lines and comments (";" or "#").
            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2) + [1 => '']);
            if ($key === '') {
                continue;
            }

            // Strip surrounding quotes if present.
            self::$cache[$key] = self::stripQuotes($value);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            self::load();
        }

        // Real environment variables win over the .env file.
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }

        return self::$cache[$key] ?? $default;
    }

    public static function getInt(string $key, int $default): int
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    private static function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
