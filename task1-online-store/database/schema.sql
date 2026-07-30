-- ============================================================================
--  Task 1: Online Store - Database schema
--  MySQL / MariaDB version.
--
--  Tables:
--    products      - the catalogue of products that can be sold.
--    inventories   - the on-hand stock for each product. Keeping inventory in
--                    its own table lets us lock/atomically decrement a single
--                    row during a flash sale without touching the product row.
--    orders        - a customer order (header).
--    order_items   - the line items of an order. An order has >= 1 item.
--
--  Note: the quantity column on `inventories` is NOT NULL and defaults to 0.
--  All decrements happen inside a transaction with SELECT ... FOR UPDATE so
--  the quantity can never go negative (see OrderController::create).
-- ============================================================================

CREATE TABLE IF NOT EXISTS products (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(255) NOT NULL,
    description  TEXT NULL,
    price        DECIMAL(12, 2) NOT NULL,                  -- normal price
    flash_price  DECIMAL(12, 2) NULL,                      -- discounted price during a flash sale (NULL = not on flash sale)
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventories (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id   INT UNSIGNED NOT NULL UNIQUE,
    quantity     INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_inventory_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer     VARCHAR(255) NOT NULL,                    -- free-form customer identifier
    status       VARCHAR(32) NOT NULL DEFAULT 'completed',  -- completed | failed
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_orders_customer (customer),
    INDEX idx_orders_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id     INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    quantity     INT NOT NULL,
    unit_price   DECIMAL(12, 2) NOT NULL,                  -- the price actually paid (flash price when on sale)
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orderitem_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_orderitem_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_orderitem_order (order_id),
    INDEX idx_orderitem_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
