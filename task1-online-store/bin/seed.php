<?php

/**
 * CLI: seed the database with a flash-sale product for testing.
 *
 *   php bin/seed.php
 *
 * Creates one flash-sale product ("Flash Sale Gadget") with a small stock so
 * the race-condition test can trigger overselling. Idempotent-ish: it always
 * inserts a fresh product so you can re-run it between test runs.
 */

declare(strict_types=1);

use Fomotoko\OnlineStore\Database\Database;
use Fomotoko\OnlineStore\Support\Config;

require __DIR__ . '/../vendor/autoload.php';

Config::load();

try {
    $db = Database::connection();

    $db->beginTransaction();

    $stmt = $db->prepare(
        'INSERT INTO products (name, description, price, flash_price)
         VALUES (:name, :description, :price, :flash_price)'
    );
    $stmt->execute([
        'name'        => 'Flash Sale Gadget',
        'description' => 'A heavily discounted gadget sold during a flash sale.',
        'price'       => '100.00',
        'flash_price' => '10.00',
    ]);
    $productId = (int) $db->lastInsertId();

    $stock = (int) (getenv('SEED_STOCK') ?: 10);

    $inv = $db->prepare('INSERT INTO inventories (product_id, quantity) VALUES (:pid, :qty)');
    $inv->execute(['pid' => $productId, 'qty' => $stock]);

    $db->commit();

    echo "[seed] Created flash-sale product id={$productId} with stock={$stock}\n";
    echo "[seed] Normal price=100.00, flash price=10.00\n";
} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "[seed] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
