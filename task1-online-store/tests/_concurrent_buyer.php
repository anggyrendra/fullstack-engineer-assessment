<?php

/**
 * Concurrent buyer helper used by the race-condition test.
 *
 * Each invocation opens its OWN PDO connection (mimicking a separate HTTP
 * request) and exercises the real OrderController::create() code path by
 * including public/index.php with a captured request. It prints "HTTP=<code>"
 * to stdout so the parent test process can tally successes/failures.
 *
 * Usage (called by the test harness, not by humans):
 *   php tests/_concurrent_buyer.php <db_path> <product_id> <quantity> <customer>
 */

declare(strict_types=1);

use Fomotoko\OnlineStore\Database\Database;
use Fomotoko\OnlineStore\Support\Config;
use Fomotoko\OnlineStore\Support\JsonResponse;
use Fomotoko\OnlineStore\Support\Request;
use Fomotoko\OnlineStore\Support\ResponseSentException;

require __DIR__ . '/../vendor/autoload.php';

if ($argc < 5) {
    fwrite(STDERR, "Usage: _concurrent_buyer.php <db_path> <product_id> <quantity> <customer>\n");
    exit(2);
}

$dbPath    = (string) $argv[1];
$productId = (int) $argv[2];
$quantity  = (int) $argv[3];
$customer  = (string) $argv[4];

// Each child shares the same SQLite file but via a fresh connection.
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $dbPath);
Config::load();

// Build the JSON request body and feed it to the request helper.
Request::reset();
Request::setMethod('POST');
Request::setPath('/orders');
Request::setBody((string) json_encode([
    'customer' => $customer,
    'items'    => [
        ['product_id' => $productId, 'quantity' => $quantity],
    ],
]));

JsonResponse::reset();
JsonResponse::capture(true);

try {
    Database::reset(); // ensure a brand-new PDO connection in this child
    require __DIR__ . '/../public/index.php';
} catch (ResponseSentException $e) {
    // Expected: the response was captured by JsonResponse.
} catch (\Throwable $e) {
    // Unexpected error: report it so the test fails loudly.
    fwrite(STDERR, 'BUYER_ERROR: ' . $e->getMessage() . "\n");
    echo "HTTP=500\n";
    exit(0);
}

$last = JsonResponse::lastResponse();
$code = $last['status'] ?? 500;

echo 'HTTP=' . (int) $code . "\n";
if ((int) $code !== 201 && isset($last['body']['error']['message'])) {
    echo 'REASON=' . $last['body']['error']['message'] . "\n";
}

exit(0);
