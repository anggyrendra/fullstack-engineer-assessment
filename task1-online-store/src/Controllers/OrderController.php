<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Controllers;

use Fomotoko\OnlineStore\Database\Database;
use Fomotoko\OnlineStore\Models\InsufficientStockException;
use Fomotoko\OnlineStore\Models\Inventory;
use Fomotoko\OnlineStore\Models\Order;
use Fomotoko\OnlineStore\Models\Product;
use Fomotoko\OnlineStore\Support\Config;
use Fomotoko\OnlineStore\Support\JsonResponse;
use Fomotoko\OnlineStore\Support\Request;
use PDO;

/**
 * Order endpoints.
 *
 * `create()` is the flash-sale endpoint and the core of the assessment. It
 * must safely handle a burst of concurrent orders that all target the same
 * product. Concurrency safety is achieved by:
 *
 *   - wrapping the whole "reserve stock + write order" sequence in a single
 *     database transaction;
 *   - locking the inventory row (SELECT ... FOR UPDATE on MySQL, an IMMEDIATE
 *     transaction on SQLite) so only one request can decrement it at a time;
 *   - refusing to decrement below zero both in application logic and in the
 *     UPDATE statement's WHERE clause (double guard against negative stock);
 *   - on any failure (insufficient stock, deadlock) rolling the transaction
 *     back and recording a `failed` order so the race is observable.
 *
 * On MySQL a deadlock (SQLSTATE 40001) is retried a small number of times
 * before giving up, which is standard practice for pessimistic locking.
 */
final class OrderController
{
    private const MAX_DEADLOCK_RETRIES = 3;

    private Order $orders;
    private Product $products;
    private Inventory $inventory;

    public function __construct()
    {
        $this->orders    = new Order();
        $this->products  = new Product();
        $this->inventory = new Inventory();
    }

    /**
     * GET /orders        -> list all orders
     * GET /orders/{id}   -> a single order with its items
     */
    public function show(array $params): void
    {
        if (isset($params['id'])) {
            $order = $this->orders->findWithItems((int) $params['id']);
            if ($order === null) {
                JsonResponse::error("Order {$params['id']} not found.", 404, 'not_found');
            }
            JsonResponse::send(['data' => $order]);
        }
        JsonResponse::send(['data' => $this->orders->all()]);
    }

    /**
     * POST /orders
     *
     * Body:
     *   {
     *     "customer": "customer-42",
     *     "items": [
     *       { "product_id": 1, "quantity": 1 }
     *     ]
     *   }
     *
     * The endpoint accepts multiple items (an order has >= 1 item). During a
     * flash sale the burst of orders all hit the same product, so this is
     * where the race condition is exercised and handled.
     */
    public function create(): void
    {
        $body     = Request::jsonBody();
        $customer = trim((string) ($body['customer'] ?? ''));
        $items    = $body['items'] ?? [];

        // --- Validate input -------------------------------------------------
        if ($customer === '') {
            JsonResponse::error('The "customer" field is required.', 422, 'validation_error');
        }
        if (!is_array($items) || $items === []) {
            JsonResponse::error('An order must contain at least one order item (in "items").', 422, 'validation_error');
        }

        $normalised = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['product_id'], $item['quantity'])) {
                JsonResponse::error('Each item needs "product_id" and "quantity".', 422, 'validation_error');
            }
            $productId = (int) $item['product_id'];
            $quantity  = (int) $item['quantity'];
            if ($productId <= 0) {
                JsonResponse::error('Item "product_id" must be a positive integer.', 422, 'validation_error');
            }
            if ($quantity <= 0) {
                JsonResponse::error('Item "quantity" must be a positive integer.', 422, 'validation_error');
            }
            $normalised[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        // --- Resolve products + effective price (outside the locked section) -
        $resolved = [];
        foreach ($normalised as $item) {
            $product = $this->products->find($item['product_id']);
            if ($product === null) {
                JsonResponse::error("Product {$item['product_id']} not found.", 404, 'not_found');
            }
            $resolved[] = [
                'product_id' => $item['product_id'],
                'quantity'   => $item['quantity'],
                'unit_price' => $this->products->effectivePrice($product),
            ];
        }

        // --- Reserve stock + create order, with retries on deadlock ---------
        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                $order = $this->reserveAndCreate($customer, $resolved);
                JsonResponse::send(['data' => $order], 201);
            } catch (InsufficientStockException $e) {
                // Stock genuinely ran out: record the failure and report it.
                $this->orders->markFailed($customer);
                JsonResponse::error($e->getMessage(), 409, 'insufficient_stock');
            } catch (\Throwable $e) {
                if ($this->isDeadlock($e) && $attempts <= self::MAX_DEADLOCK_RETRIES) {
                    // Retry after a tiny, jittered back-off.
                    usleep(random_int(1000, 5000));
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * The atomic "reserve stock and write order" sequence, run inside a
     * transaction with the inventory row locked.
     *
     * @param list<array{product_id:int, quantity:int, unit_price:string}> $items
     * @return array<string, mixed>
     */
    private function reserveAndCreate(string $customer, array $items): array
    {
        $db = Database::connection();
        $driver = strtolower((string) Config::get('DB_DRIVER', 'mysql'));

        // SQLite: BEGIN IMMEDIATE acquires a write lock up front, serialising
        // writers and giving us row-level safety without FOR UPDATE support.
        if ($driver === 'sqlite') {
            $db->exec('BEGIN IMMEDIATE');
        } else {
            $db->beginTransaction();
        }

        try {
            // Decrement each product's inventory under the row lock.
            foreach ($items as $item) {
                $this->inventory->decrementForUpdate($item['product_id'], $item['quantity']);
            }

            $order = $this->orders->createWithItems($customer, $items);

            if ($driver === 'sqlite') {
                $db->exec('COMMIT');
            } else {
                $db->commit();
            }

            return $order;
        } catch (\Throwable $e) {
            if ($driver === 'sqlite') {
                $db->exec('ROLLBACK');
            } else {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Recognise a deadlock / lock-timeout so we can retry.
     */
    private function isDeadlock(\Throwable $e): bool
    {
        $sqlState = '';
        if ($e instanceof \PDOException && isset($e->errorInfo[0])) {
            $sqlState = (string) $e->errorInfo[0];
        }
        // 40001 = serialization failure, 40P01 = deadlock detected (Postgres).
        return $sqlState === '40001' || $sqlState === '40P01'
            || str_contains((string) $e->getMessage(), 'Deadlock')
            || str_contains((string) $e->getMessage(), 'database is locked');
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
