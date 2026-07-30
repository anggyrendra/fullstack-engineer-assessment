<?php

/**
 * Standalone, runnable-from-the-command-line functional test for the race
 * condition during a flash sale.
 *
 * This is the test referenced by the assessment ("at least one functional test
 * that can be run from the command line, which tests the API's ability to
 * handle a race condition"). It needs no PHPUnit, no HTTP server and no MySQL:
 * it spins up a throwaway SQLite database, seeds a flash-sale product, then
 * fires a burst of concurrent buyer processes that all try to buy the same
 * product, and verifies the inventory can never go negative.
 *
 * Usage:
 *   php tests/RaceConditionTest.php
 *
 * Exit code 0  -> all assertions passed
 * Exit code 1  -> one or more assertions failed
 */

declare(strict_types=1);

use Fomotoko\OnlineStore\Database\Database;
use Fomotoko\OnlineStore\Support\Config;

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
//  Test harness: a tiny assertion helper so we don't depend on PHPUnit.
// ---------------------------------------------------------------------------
$failures = 0;
function assertTrue(bool $cond, string $message): void
{
    global $failures;
    if ($cond) {
        echo "  [PASS] {$message}\n";
    } else {
        echo "  [FAIL] {$message}\n";
        $failures++;
    }
}
function assertEqual(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected === $actual) {
        echo "  [PASS] {$message} ({$actual})\n";
    } else {
        echo "  [FAIL] {$message} -- expected {$expected}, got {$actual}\n";
        $failures++;
    }
}

// ---------------------------------------------------------------------------
//  Set up a fresh SQLite database for this run.
// ---------------------------------------------------------------------------
$dbPath = sys_get_temp_dir() . '/online_store_race_' . getmypid() . '.sqlite';
if (is_file($dbPath)) {
    unlink($dbPath);
}

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $dbPath);
Config::load();

Database::migrate();

$STOCK       = 10;
$BUYERS      = $STOCK + 20;   // 30 concurrent buyers for 10 units
$QUANTITY    = 1;
$PRODUCT_ID  = seedFlashSaleProduct($STOCK);

echo "\n";
echo "Flash Sale race-condition test\n";
echo "------------------------------\n";
echo "Database : {$dbPath}\n";
echo "Product  : id={$PRODUCT_ID}, normal price=100.00, flash price=10.00\n";
echo "Stock    : {$STOCK} units\n";
echo "Buyers   : {$BUYERS} concurrent requests, each buying {$QUANTITY} unit(s)\n\n";

// ---------------------------------------------------------------------------
//  Fire the burst of concurrent orders.
// ---------------------------------------------------------------------------
echo "Launching {$BUYERS} concurrent buyer processes...\n";
$results = fireConcurrentBuyers($dbPath, $PRODUCT_ID, $QUANTITY, $BUYERS);

$successes  = $results['successes'];
$failures409 = $results['failures'];

echo "Done. Successes={$successes}, failures(409)={$failures409}\n\n";

// ---------------------------------------------------------------------------
//  Assertions.
// ---------------------------------------------------------------------------
echo "Assertions:\n";

// 1. We never sold more than we had.
assertTrue(
    $successes <= $STOCK,
    "No overselling: sold {$successes} of {$STOCK} available units"
);

// 2. We sold exactly the available stock (all stock should be consumed).
assertEqual($STOCK, $successes, 'All available stock was sold');

// 3. The remaining buyers were rejected.
assertEqual($BUYERS - $STOCK, $failures409, 'Excess buyers rejected with 409');

// 4. Inventory is exactly zero, never negative.
$remaining = currentStock($PRODUCT_ID);
assertEqual(0, $remaining, 'Inventory is exactly 0 after the sale');
assertTrue($remaining >= 0, 'Inventory is never negative');

// 5. No order row is silently dropped: successes + failures == buyers.
$orderCounts = orderCountsByStatus();
$totalRows   = ($orderCounts['completed'] ?? 0) + ($orderCounts['failed'] ?? 0);
assertEqual($BUYERS, $totalRows, 'Every request produced an order row (completed or failed)');

// ---------------------------------------------------------------------------
//  Verdict.
// ---------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "RESULT: ALL TESTS PASSED ✓\n";
    echo "The API handled the race condition correctly: inventory never went negative.\n\n";
    cleanup($dbPath);
    exit(0);
} else {
    echo "RESULT: {$failures} ASSERTION(S) FAILED ✗\n\n";
    cleanup($dbPath);
    exit(1);
}

// ---------------------------------------------------------------------------
//  Helpers.
// ---------------------------------------------------------------------------

/**
 * Seed a flash-sale product and return its id.
 */
function seedFlashSaleProduct(int $stock): int
{
    $pdo = Database::connection();
    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO products (name, description, price, flash_price)
         VALUES (:name, :desc, :price, :flash)'
    )->execute([
        'name'  => 'Flash Sale Gadget',
        'desc'  => 'Heavily discounted gadget for the flash sale.',
        'price' => '100.00',
        'flash' => '10.00',
    ]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO inventories (product_id, quantity) VALUES (:pid, :qty)')
        ->execute(['pid' => $id, 'qty' => $stock]);
    $pdo->commit();
    return $id;
}

/**
 * Launch $count concurrent buyer processes and tally their HTTP status codes.
 *
 * @return array{successes:int, failures:int}
 */
function fireConcurrentBuyers(string $dbPath, int $productId, int $quantity, int $count): array
{
    $script = __DIR__ . '/_concurrent_buyer.php';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $handles = [];
    for ($i = 0; $i < $count; $i++) {
        $cmd = PHP_BINARY . ' ' . escapeshellarg($script)
             . ' ' . escapeshellarg($dbPath)
             . ' ' . escapeshellarg((string) $productId)
             . ' ' . escapeshellarg((string) $quantity)
             . ' ' . escapeshellarg('buyer-' . $i);
        $handles[] = [proc_open($cmd, $descriptors, $pipes), $pipes];
    }

    $successes = 0;
    $failures  = 0;
    foreach ($handles as [$proc, $pipes]) {
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if (preg_match('/HTTP=(\d+)/', $out, $m)) {
            ((int) $m[1] === 201) ? $successes++ : $failures++;
        } else {
            $failures++;
        }
    }

    return ['successes' => $successes, 'failures' => $failures];
}

function currentStock(int $productId): int
{
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE product_id = :pid');
    $stmt->execute(['pid' => $productId]);
    $row = $stmt->fetch();
    return $row === false ? 0 : (int) $row['quantity'];
}

/**
 * @return array<string, int>
 */
function orderCountsByStatus(): array
{
    $pdo = Database::connection();
    $rows = $pdo->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['status']] = (int) $row['total'];
    }
    return $out;
}

function cleanup(string $dbPath): void
{
    // Remove the test database (and SQLite WAL/SHM sidecars) so temp stays clean.
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}
