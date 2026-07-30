<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Database;

use Fomotoko\OnlineStore\Support\Config;
use PDO;
use PDOException;

/**
 * Singleton PDO connection factory.
 *
 * Supports MySQL/MariaDB and SQLite. The driver is chosen from the DB_DRIVER
 * config value (default: mysql). For SQLite the file is created on demand so
 * the functional tests can run without an external database server.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = strtolower((string) Config::get('DB_DRIVER', 'mysql'));

        if ($driver === 'sqlite') {
            $path = Config::get('DB_SQLITE_PATH', __DIR__ . '/../../database/online_store.sqlite');
            $dir  = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $dsn = 'sqlite:' . $path;
            $pdo = new PDO($dsn);
            // SQLite needs these pragmas for sane concurrent behaviour.
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $host    = Config::get('DB_HOST', '127.0.0.1');
            $port    = Config::get('DB_PORT', '3306');
            $name    = Config::get('DB_NAME', 'online_store');
            $charset = 'utf8mb4';
            $dsn     = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
            $pdo     = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS', ''));
        }

        // Throw exceptions on error so we can handle them cleanly.
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        self::$pdo = $pdo;

        return self::$pdo;
    }

    /**
     * Force a fresh connection (used by tests that want isolation).
     */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Apply the matching schema file for the configured driver.
     */
    public static function migrate(): void
    {
        $pdo      = self::connection();
        $driver   = strtolower((string) Config::get('DB_DRIVER', 'mysql'));
        $sqlFile  = $driver === 'sqlite'
            ? __DIR__ . '/../../database/schema_sqlite.sql'
            : __DIR__ . '/../../database/schema.sql';

        if (!is_file($sqlFile)) {
            throw new \RuntimeException("Schema file not found: {$sqlFile}");
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read schema file: {$sqlFile}");
        }

        // Split on semicolons followed by a newline so multi-statement files
        // work for both MySQL and SQLite.
        $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: []));

        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
    }
}
