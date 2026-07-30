<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Models;

use Fomotoko\OnlineStore\Database\Database;
use PDO;

/**
 * Read/write access to the `inventories` table.
 *
 * The key method here is `decrementForUpdate()`: it locks the inventory row
 * for the duration of the surrounding transaction (SELECT ... FOR UPDATE on
 * MySQL, SQLite serialises via BEGIN IMMEDIATE) and only decrements when there
 * is enough stock. This is the core of the race-condition protection.
 */
final class Inventory
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProduct(int $productId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM inventories WHERE product_id = :pid');
        $stmt->execute(['pid' => $productId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Atomically reserve `quantity` units of a product inside the caller's
     * transaction.
     *
     * Strategy:
     *   1. SELECT the inventory row with an exclusive lock (FOR UPDATE).
     *   2. If quantity < requested, throw an InsufficientStockException.
     *   3. UPDATE the row, subtracting the requested quantity. A CHECK-like
     *      guard (`quantity >= 0`) is enforced in the UPDATE WHERE clause as a
     *      second line of defence, so even a bug in step 2 can never produce a
     *      negative value.
     *
     * @throws InsufficientStockException when there is not enough stock.
     */
    public function decrementForUpdate(int $productId, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        // 1. Lock the row for the duration of the transaction.
        $driver = strtolower((string) \Fomotoko\OnlineStore\Support\Config::get('DB_DRIVER', 'mysql'));

        if ($driver === 'sqlite') {
            // SQLite does not support SELECT ... FOR UPDATE; row-level locking
            // is achieved through the IMMEDIATE transaction begun by the
            // controller, so a plain SELECT is safe here.
            $stmt = $this->db->prepare('SELECT quantity FROM inventories WHERE product_id = :pid');
        } else {
            $stmt = $this->db->prepare('SELECT quantity FROM inventories WHERE product_id = :pid FOR UPDATE');
        }

        $stmt->execute(['pid' => $productId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new InsufficientStockException("Product {$productId} has no inventory record.");
        }

        $available = (int) $row['quantity'];

        // 2. Verify stock before decrementing.
        if ($available < $quantity) {
            throw new InsufficientStockException(
                "Insufficient stock for product {$productId}: requested {$quantity}, available {$available}."
            );
        }

        // 3. Decrement with a WHERE guard that refuses to go negative.
        $update = $this->db->prepare(
            'UPDATE inventories SET quantity = quantity - :qty '
            . 'WHERE product_id = :pid AND quantity >= :qty'
        );
        $update->execute(['pid' => $productId, 'qty' => $quantity]);

        if ($update->rowCount() === 0) {
            // Should not happen given the checks above, but guard anyway.
            throw new InsufficientStockException("Could not decrement stock for product {$productId} (concurrent modification).");
        }
    }
}
