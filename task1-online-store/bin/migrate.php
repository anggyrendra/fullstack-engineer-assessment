<?php

/**
 * CLI: apply the database schema.
 *
 *   php bin/migrate.php
 *
 * Uses the DB_DRIVER from .env (mysql or sqlite).
 */

declare(strict_types=1);

use Fomotoko\OnlineStore\Database\Database;
use Fomotoko\OnlineStore\Support\Config;

require __DIR__ . '/../vendor/autoload.php';

Config::load();

try {
    Database::migrate();
    $driver = Config::get('DB_DRIVER', 'mysql');
    echo "[migrate] Schema applied successfully using driver: {$driver}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "[migrate] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
