<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Models;

use Fomotoko\OnlineStore\Database\Database;
use PDO;

/**
 * Read/write access to the `orders` and `order_items` tables.
 *
 * `createWithItems()` is the heart of the flash-sale flow. It is meant to be
 * called from inside the controller's transaction together with the locked
 * inventory decrement, so an order and its stock reservation are committed
 * atomically.
 */
final class Order
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch an order together with its line items.
     *
     * @return array<string, mixed>|null
     */
    public function findWithItems(int $id): ?array
    {
        $order = $this->find($id);
        if ($order === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT oi.*, p.name AS product_name
               FROM order_items oi
               JOIN products p ON p.id = oi.product_id
              WHERE oi.order_id = :id
              ORDER BY oi.id'
        );
        $stmt->execute(['id' => $id]);
        $order['items'] = $stmt->fetchAll();

        return $order;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM orders ORDER BY id DESC')->fetchAll();
    }

    /**
     * Create an order header plus its line items.
     *
     * The caller is responsible for the surrounding transaction and for having
     * already locked/decremented the inventory. We deliberately accept the
     * already-resolved unit price so the customer pays the flash-sale price
     * that was in effect at the moment of reservation.
     *
     * @param list<array{product_id:int, quantity:int, unit_price:string}> $items
     *
     * @return array<string, mixed> the created order (with items)
     */
    public function createWithItems(string $customer, array $items): array
    {
        if ($items === []) {
            throw new \InvalidArgumentException('An order must contain at least one order item.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO orders (customer, status) VALUES (:customer, :status)'
        );
        $stmt->execute(['customer' => $customer, 'status' => 'completed']);
        $orderId = (int) $this->db->lastInsertId();

        $itemStmt = $this->db->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, unit_price)
             VALUES (:order_id, :product_id, :quantity, :unit_price)'
        );

        foreach ($items as $item) {
            $itemStmt->execute([
                'order_id'    => $orderId,
                'product_id'  => $item['product_id'],
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
            ]);
        }

        return $this->findWithItems($orderId);
    }

    /**
     * Record a failed order (used when stock runs out during a flash sale so
     * the race condition is observable in the data).
     */
    public function markFailed(string $customer): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO orders (customer, status) VALUES (:customer, :status)'
        );
        $stmt->execute(['customer' => $customer, 'status' => 'failed']);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Count orders grouped by status (used by the test harness/reporting).
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->db->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status')->fetchAll();
        $out  = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['total'];
        }
        return $out;
    }
}
