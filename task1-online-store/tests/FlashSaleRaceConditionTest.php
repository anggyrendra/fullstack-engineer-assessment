<?php

/**
 * Functional test: the API must handle a race condition during a flash sale.
 *
 * This test simulates a burst of concurrent orders that all try to buy the
 * same flash-sale product. It then asserts that:
 *
 *   1. The number of *successful* orders never exceeds the available stock.
 *   2. Inventory never goes negative (the hard requirement).
 *   3. Every order is either `completed` or `failed` (never silently dropped).
 *
 * Each concurrent buyer is a separate PHP process with its own PDO connection
 * (a faithful simulation of separate HTTP requests). SQLite's BEGIN IMMEDIATE
 * serialises the writers, proving the locking strategy works; on MySQL the
 * same logic uses SELECT ... FOR UPDATE.
 */

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Tests;

use Fomotoko\OnlineStore\Database\Database;
use Fomotoko\OnlineStore\Models\Inventory;
use Fomotoko\OnlineStore\Models\Order;
use PHPUnit\Framework\TestCase;

final class FlashSaleRaceConditionTest extends TestCase
{
    private int $productId;
    private int $initialStock;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->productId    = (int) $GLOBALS['TEST_PRODUCT_ID'];
        $this->initialStock = (int) $GLOBALS['TEST_FLASH_STOCK'];
        $this->dbPath       = (string) $GLOBALS['TEST_DB_PATH'];

        $this->resetStock($this->initialStock);
        $this->truncateOrders();
    }

    /**
     * The headline race-condition test: fire more concurrent buyers than
     * there is stock and confirm we never oversell.
     */
    public function testBurstOfOrdersDoesNotOversell(): void
    {
        $buyers   = $this->initialStock + 15; // more buyers than stock
        $quantity = 1;

        $results = $this->fireConcurrentOrders($buyers, $quantity);

        $successes  = $results['successes'];
        $failures   = $results['failures'];
        $failedRows = $results['failed_rows'];

        // 1. We never sold more than we had.
        self::assertLessThanOrEqual(
            $this->initialStock,
            $successes,
            "Sold {$successes} units but only {$this->initialStock} were in stock (oversold!)."
        );

        // 2. We sold exactly the available stock (all stock should be consumed).
        self::assertSame(
            $this->initialStock,
            $successes,
            "Expected to sell exactly {$this->initialStock} units, sold {$successes}."
        );

        // 3. The remaining buyers were told there was no stock.
        self::assertSame(
            $buyers - $this->initialStock,
            $failures,
            'Expected ' . ($buyers - $this->initialStock) . " failed orders, got {$failures}."
        );

        // 4. Inventory is exactly zero, never negative.
        $remaining = $this->currentStock();
        self::assertSame(0, $remaining, "Inventory should be 0 but is {$remaining} (went negative!).");
        self::assertGreaterThanOrEqual(0, $remaining, 'Inventory must never be negative.');

        // 5. Every failed buyer was recorded as a failed order.
        self::assertSame($failures, $failedRows, 'Failed orders should be recorded with status=failed.');
    }

    /**
     * A multi-item order is atomic: if it requests more than the available
     * stock the whole order fails and nothing is decremented.
     */
    public function testOversizedOrderFailsAtomically(): void
    {
        $payload = [
            'customer' => 'big-buyer',
            'items'    => [
                ['product_id' => $this->productId, 'quantity' => $this->initialStock + 1],
            ],
        ];

        $response = $this->callApiInProcess('POST', '/orders', $payload);

        self::assertSame(409, $response['status'], 'Oversized order must be rejected with 409.');
        self::assertSame($this->initialStock, $this->currentStock(), 'Failed order must not decrement stock.');

        $counts = (new Order())->countByStatus();
        self::assertSame(1, $counts['failed'] ?? 0, 'A failed order should be recorded.');
    }

    /**
     * A normal (within-stock) order succeeds and decrements inventory.
     */
    public function testNormalOrderSucceeds(): void
    {
        $response = $this->callApiInProcess('POST', '/orders', [
            'customer' => 'happy-customer',
            'items'    => [['product_id' => $this->productId, 'quantity' => 2]],
        ]);

        self::assertSame(201, $response['status'], 'In-stock order must succeed (201).');
        self::assertSame($this->initialStock - 2, $this->currentStock(), 'Stock must be decremented by 2.');

        $counts = (new Order())->countByStatus();
        self::assertSame(1, $counts['completed'] ?? 0, 'One completed order should exist.');
    }

    // -----------------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------------

    /**
     * Spawn $count PHP child processes that each POST an order over a fresh
     * PDO connection, then tally the HTTP status codes they return.
     *
     * @return array{successes:int, failures:int, failed_rows:int}
     */
    private function fireConcurrentOrders(int $count, int $quantity): array
    {
        $script = __DIR__ . '/_concurrent_buyer.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Launch all buyers as concurrently as possible.
        $handles = [];
        for ($i = 0; $i < $count; $i++) {
            $cmd = PHP_BINARY . ' ' . escapeshellarg($script)
                 . ' ' . escapeshellarg($this->dbPath)
                 . ' ' . escapeshellarg((string) $this->productId)
                 . ' ' . escapeshellarg((string) $quantity)
                 . ' ' . escapeshellarg('buyer-' . $i);
            $handles[] = [proc_open($cmd, $descriptors, $pipes), $pipes];
        }

        // Collect every child's stdout.
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

        $failedRows = (new Order())->countByStatus()['failed'] ?? 0;

        return ['successes' => $successes, 'failures' => $failures, 'failed_rows' => $failedRows];
    }

    /**
     * Call the API in-process by including public/index.php with a captured
     * request/response. No HTTP server is required.
     *
     * @param array<string, mixed> $body
     * @return array{status:int, body:array<string, mixed>}
     */
    private function callApiInProcess(string $method, string $path, array $body = []): array
    {
        // Fresh PDO so previous test state is not reused.
        Database::reset();

        $request = \Fomotoko\OnlineStore\Support\Request::class;
        $json    = \Fomotoko\OnlineStore\Support\JsonResponse::class;

        $request::reset();
        $request::setMethod($method);
        $request::setPath($path);
        $request::setBody($body === [] ? '' : (string) json_encode($body));

        $json::reset();
        $json::capture(true);

        try {
            require __DIR__ . '/../public/index.php';
        } catch (\Throwable $e) {
            // JsonResponse throws ResponseSentException in capture mode; any
            // other throwable is surfaced below via lastResponse() / rethrow.
            if (!($e instanceof \Fomotoko\OnlineStore\Support\ResponseSentException)) {
                $json::capture(false);
                throw $e;
            }
        }

        $last = $json::lastResponse();
        $json::capture(false);

        return $last ?? ['status' => 500, 'body' => ['error' => ['message' => 'No response captured.']]];
    }

    private function currentStock(): int
    {
        $inv = (new Inventory())->findByProduct($this->productId);
        return $inv === null ? 0 : (int) $inv['quantity'];
    }

    private function resetStock(int $qty): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE inventories SET quantity = :qty WHERE product_id = :pid')
            ->execute(['qty' => $qty, 'pid' => $this->productId]);
    }

    private function truncateOrders(): void
    {
        $pdo = Database::connection();
        $pdo->exec('DELETE FROM order_items');
        $pdo->exec('DELETE FROM orders');
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('orders','order_items')");
    }
}
