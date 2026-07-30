<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Controllers;

use Fomotoko\OnlineStore\Models\Product;
use Fomotoko\OnlineStore\Models\Inventory;
use Fomotoko\OnlineStore\Support\JsonResponse;
use Fomotoko\OnlineStore\Support\Request;

/**
 * CRUD-ish endpoints for products and their inventory.
 */
final class ProductController
{
    private Product $products;
    private Inventory $inventory;

    public function __construct()
    {
        $this->products   = new Product();
        $this->inventory  = new Inventory();
    }

    /**
     * GET /products            -> list all products (with stock)
     * GET /products/{id}       -> a single product (with stock)
     */
    public function show(array $params): void
    {
        if (isset($params['id'])) {
            $this->showOne((int) $params['id']);
            return;
        }
        $this->list();
    }

    private function list(): void
    {
        $products = $this->products->all();
        $out = [];
        foreach ($products as $p) {
            $inv = $this->inventory->findByProduct((int) $p['id']);
            $out[] = $this->present($p, $inv);
        }
        JsonResponse::send(['data' => $out]);
    }

    private function showOne(int $id): void
    {
        $product = $this->products->find($id);
        if ($product === null) {
            JsonResponse::error("Product {$id} not found.", 404, 'not_found');
        }
        $inv = $this->inventory->findByProduct($id);
        JsonResponse::send(['data' => $this->present($product, $inv)]);
    }

    /**
     * POST /products
     *
     * Body:
     *   {
     *     "name": "Flash Sale Laptop",
     *     "description": "...",
     *     "price": "1000.00",
     *     "flash_price": "100.00",   // optional; null means no flash sale
     *     "stock": 50
     *   }
     */
    public function create(): void
    {
        $body = $this->jsonBody();

        $name     = trim((string) ($body['name'] ?? ''));
        $price    = trim((string) ($body['price'] ?? ''));
        $stock    = (int) ($body['stock'] ?? 0);
        $flash    = isset($body['flash_price']) && $body['flash_price'] !== null
            ? trim((string) $body['flash_price'])
            : null;
        $desc     = trim((string) ($body['description'] ?? ''));

        if ($name === '') {
            JsonResponse::error('The "name" field is required.', 422, 'validation_error');
        }
        if ($price === '' || !is_numeric($price)) {
            JsonResponse::error('The "price" field is required and must be numeric.', 422, 'validation_error');
        }
        if ($flash !== null && !is_numeric($flash)) {
            JsonResponse::error('The "flash_price" field must be numeric when provided.', 422, 'validation_error');
        }
        if ($stock < 0) {
            JsonResponse::error('The "stock" field must not be negative.', 422, 'validation_error');
        }

        $product = $this->products->create($name, $desc, $price, $flash, $stock);
        $inv     = $this->inventory->findByProduct((int) $product['id']);

        JsonResponse::send(['data' => $this->present($product, $inv)], 201);
    }

    /**
     * GET /products/{id}/inventory  -> just the stock for a product.
     */
    public function inventory(array $params): void
    {
        $id   = (int) $params['id'];
        $inv  = $this->inventory->findByProduct($id);
        if ($inv === null) {
            JsonResponse::error("No inventory for product {$id}.", 404, 'not_found');
        }
        JsonResponse::send(['data' => $inv]);
    }

    /**
     * Build the JSON representation of a product with its stock embedded.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed>|null $inventory
     * @return array<string, mixed>
     */
    private function present(array $product, ?array $inventory): array
    {
        return [
            'id'           => (int) $product['id'],
            'name'         => (string) $product['name'],
            'description'  => (string) ($product['description'] ?? ''),
            'price'        => (string) $product['price'],
            'flash_price'  => $product['flash_price'] === null || $product['flash_price'] === ''
                ? null
                : (string) $product['flash_price'],
            'on_flash_sale'=> $this->products->isOnFlashSale($product),
            'stock'        => $inventory === null ? 0 : (int) $inventory['quantity'],
        ];
    }

}
