<?php

/**
 * PHPUnit bootstrap.
 *
 * Sets up a throwaway SQLite database for the test run, applies the schema,
 * and seeds a flash-sale product. Using SQLite keeps the functional test
 * runnable from the command line with zero external dependencies.
 */

declare(strict_types=1);

use Fomotoko\OnlineStore\Database\Database;
use Fomotoko\OnlineStore\Support\Config;

require __DIR__ . '/../vendor/autoload.php';

// Use a fresh in-memory-ish SQLite file per process so parallel runs don't clash.
$tmpDir = sys_get_temp_dir();
$dbPath = $tmpDir . '/online_store_test_' . getmypid() . '.sqlite';

// Wipe any leftover file from a previous run.
if (is_file($dbPath)) {
    unlink($dbPath);
}

// Configure the environment for the test run. Setting these via putenv means
// they win over any .env file (see Config::get).
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $dbPath);

Config::load();          // pick up any local .env (won't override the putenv above)
Database::migrate();     // create the schema in the test database

// Seed a flash-sale product with a deliberately small stock.
$pdo = Database::connection();
$pdo->beginTransaction();
$pdo->prepare(
    'INSERT INTO products (name, description, price, flash_price) VALUES (:name, :desc, :price, :flash)'
)->execute([
    'name'  => 'Flash Sale Gadget',
    'desc'  => 'Heavily discounted gadget for the flash sale.',
    'price' => '100.00',
    'flash' => '10.00',
]);
$productId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO inventories (product_id, quantity) VALUES (:pid, :qty)')
    ->execute(['pid' => $productId, 'qty' => 10]);
$pdo->commit();

// Expose the product id to the tests via a constant-style global.
$GLOBALS['TEST_PRODUCT_ID']  = $productId;
$GLOBALS['TEST_DB_PATH']     = $dbPath;
$GLOBALS['TEST_FLASH_STOCK'] = 10;
