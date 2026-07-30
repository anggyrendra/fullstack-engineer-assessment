<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Models;

use Fomotoko\OnlineStore\Database\Database;
use PDO;

/**
 * Read/write access to the `products` table.
 */
final class Product
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
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM products ORDER BY id')->fetchAll();
    }

    /**
     * Create a product and its inventory row in one go.
     *
     * @return array<string, mixed>
     */
    public function create(string $name, string $description, string $price, ?string $flashPrice, int $stock): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO products (name, description, price, flash_price) VALUES (:name, :description, :price, :flash_price)'
            );
            $stmt->execute([
                'name'        => $name,
                'description' => $description,
                'price'       => $price,
                'flash_price' => $flashPrice,
            ]);
            $id = (int) $this->db->lastInsertId();

            $inv = $this->db->prepare('INSERT INTO inventories (product_id, quantity) VALUES (:pid, :qty)');
            $inv->execute(['pid' => $id, 'qty' => $stock]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->find($id);
    }

    /**
     * The effective price for a sale: flash_price when set, otherwise price.
     */
    public function effectivePrice(array $product): string
    {
        return ($product['flash_price'] !== null && $product['flash_price'] !== '')
            ? (string) $product['flash_price']
            : (string) $product['price'];
    }

    public function isOnFlashSale(array $product): bool
    {
        return $product['flash_price'] !== null && $product['flash_price'] !== '';
    }
}
