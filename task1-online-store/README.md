# Task 1 — Online Store (Flash-Sale API)

A small, framework-free PHP REST API for an online store that holds a flash
sale. The central requirement is **handling a race condition**: a burst of
concurrent orders that all try to buy the same heavily-discounted product must
never drive the inventory negative, and every unit must be sold exactly once.

The solution ships with two database schemas (MySQL/MariaDB and SQLite) so the
functional test can run from the command line with **zero external
dependencies** (no MySQL server required).

## Business rules covered

1. An Order consists of at least one Order Item (`orders` 1—N `order_items`,
   enforced by validation in `OrderController::create`).
2. A flash sale is modelled by the `products.flash_price` column. When it is
   set, that is the price the customer pays (`Product::effectivePrice`).
3. Inventory can never go negative — protected by a transaction, row locking,
   an application-level stock check, **and** a `WHERE quantity >= :qty` guard
   in the `UPDATE` statement (see `Inventory::decrementForUpdate`).

## How the race condition is handled

`OrderController::reserveAndCreate()` wraps the whole "reserve stock + write
order" sequence in a single database transaction:

- **MySQL/MariaDB** — `SELECT ... FOR UPDATE` locks the inventory row for the
  duration of the transaction, so concurrent writers serialise on that row.
  Deadlocks (`SQLSTATE 40001`) are retried a few times with a small jittered
  back-off.
- **SQLite** — a `BEGIN IMMEDIATE` transaction acquires the write lock up
  front, serialising all writers (SQLite has no `FOR UPDATE`, but the
  immediate transaction gives equivalent row-level safety for this workload).

On top of the lock there are two independent guards against negative stock:

1. The application checks `available >= requested` *after* acquiring the lock
   and throws `InsufficientStockException` (→ HTTP 409) if there is not enough.
2. The `UPDATE inventories SET quantity = quantity - :qty WHERE product_id =
   :pid AND quantity >= :qty` only decrements when stock is sufficient, so a
   negative value is impossible even if the application check were bypassed.

When an order fails because the stock ran out, a row with `status = 'failed'`
is still recorded, so the race is fully observable in the data (every request
produces an order row).

## API endpoints

All endpoints speak JSON and use proper HTTP status codes
(200 / 201 / 404 / 405 / 409 / 422 / 500) with a consistent error shape:

```json
{ "error": { "code": "insufficient_stock", "message": "..." } }
```

| Method | Path                          | Description                                  |
|--------|-------------------------------|----------------------------------------------|
| GET    | `/products`                   | List all products (with stock)               |
| GET    | `/products/{id}`              | A single product (with stock)                |
| POST   | `/products`                   | Create a product + its inventory row         |
| GET    | `/products/{id}/inventory`    | The stock for a product                      |
| GET    | `/orders`                     | List all orders                              |
| GET    | `/orders/{id}`                | An order with its line items                 |
| POST   | `/orders`                     | Create an order (the flash-sale endpoint)    |

### `POST /products` body

```json
{
  "name": "Flash Sale Gadget",
  "description": "Heavily discounted gadget.",
  "price": "100.00",
  "flash_price": "10.00",
  "stock": 50
}
```

### `POST /orders` body

```json
{
  "customer": "customer-42",
  "items": [
    { "product_id": 1, "quantity": 1 }
  ]
}
```

## Getting started

Requirements: PHP 8.1+ with `pdo`, `pdo_sqlite` (for the test) and
`pdo_mysql` (for MySQL deployments). [Composer](https://getcomposer.org/).

```bash
# 1. Install dependencies
composer install

# 2. Configure the database (SQLite by default — easiest for a quick run)
cp .env.example .env            # already defaults to SQLite

# 3. Create the schema and seed a flash-sale product
composer migrate
composer seed                   # creates a product with 10 units at flash price 10.00

# 4. Run the API with the built-in server
composer serve                  # http://localhost:8000
```

To use MySQL instead, edit `.env`:

```
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=online_store
DB_USER=root
DB_PASS=
```

`composer migrate` will then apply `database/schema.sql`.

## Running the tests

### Standalone functional test (no PHPUnit needed)

This is the test explicitly requested by the assessment: a functional test
runnable from the command line that exercises the race condition.

```bash
composer race-test
# or
php tests/RaceConditionTest.php
```

It spins up a throwaway SQLite database, seeds a product with 10 units, then
fires **30 concurrent buyer processes** that all POST an order for the same
product. A passing run looks like:

```
Stock    : 10 units
Buyers   : 30 concurrent requests, each buying 1 unit(s)

Done. Successes=10, failures(409)=20

Assertions:
  [PASS] No overselling: sold 10 of 10 available units
  [PASS] All available stock was sold (10)
  [PASS] Excess buyers rejected with 409 (20)
  [PASS] Inventory is exactly 0 after the sale (0)
  [PASS] Inventory is never negative
  [PASS] Every request produced an order row (completed or failed) (30)

RESULT: ALL TESTS PASSED ✓
```

Exit code is `0` on success and `1` on failure, so it works in CI.

### PHPUnit suite

```bash
composer test
# or
vendor/bin/phpunit
```

Runs `tests/FlashSaleRaceConditionTest.php` which contains:

- `testBurstOfOrdersDoesNotOversell` — the concurrent burst (same as the
  standalone test, asserted through PHPUnit).
- `testOversizedOrderFailsAtomically` — a single order requesting more than
  the stock is rejected with 409 and decrements nothing.
- `testNormalOrderSucceeds` — an in-stock order succeeds with 201 and
  decrements inventory.

## Verifying the race over real HTTP

```bash
composer migrate && composer seed
composer serve &

# 25 concurrent orders for a product that has 10 units
for i in $(seq 1 25); do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST localhost:8000/orders \
    -H 'Content-Type: application/json' \
    -d "{\"customer\":\"b$i\",\"items\":[{\"product_id\":1,\"quantity\":1}]}" &
done; wait | sort | uniq -c

curl -s localhost:8000/products/1/inventory   # -> "quantity": 0
```

You will see exactly `10` × `201` and `15` × `409`, and the inventory ends at
`0` — never negative.

## Project layout

```
task1-online-store/
├── composer.json
├── .env.example
├── phpunit.xml
├── public/
│   └── index.php                 # HTTP entry point + router wiring
├── bin/
│   ├── migrate.php               # apply the schema
│   └── seed.php                  # seed a flash-sale product
├── database/
│   ├── schema.sql                # MySQL/MariaDB schema
│   └── schema_sqlite.sql         # SQLite schema (used by the tests)
├── src/
│   ├── Controllers/
│   │   ├── ProductController.php
│   │   └── OrderController.php   # the race-condition-safe flash-sale endpoint
│   ├── Models/
│   │   ├── Product.php
│   │   ├── Inventory.php         # decrementForUpdate() — the locking core
│   │   ├── Order.php
│   │   └── InsufficientStockException.php
│   ├── Database/
│   │   └── Database.php          # PDO factory + migrate()
│   └── Support/
│       ├── Config.php            # .env loader
│       ├── Router.php            # tiny regex router
│       ├── Request.php           # testable request body/method/path helper
│       ├── JsonResponse.php      # JSON responses (+ capture mode for tests)
│       └── ResponseSentException.php
└── tests/
    ├── RaceConditionTest.php             # standalone CLI functional test
    ├── FlashSaleRaceConditionTest.php    # PHPUnit test class
    ├── _concurrent_buyer.php             # one concurrent buyer process
    └── bootstrap.php                     # PHPUnit bootstrap (SQLite setup)
```

## Design notes

- **No framework on purpose.** A few small, well-commented classes keep the
  concurrency logic front-and-centre rather than hidden behind a framework's
  ORM. It also keeps the dependency surface tiny.
- **Inventory in its own table** so the hot row that gets locked during a
  flash sale is as narrow as possible (just `product_id` + `quantity`).
- **The flash-sale price is captured at reservation time** (`unit_price` on
  `order_items`), so the customer pays the price that was in effect the moment
  their stock was reserved — even if the flash sale ends mid-burst.
- **Failed orders are recorded**, which makes the race condition observable
  and auditable after the fact.
