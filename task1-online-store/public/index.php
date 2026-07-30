<?php

/**
 * Public entry point for the Online Store API.
 *
 * Run with the built-in server:
 *   composer serve
 * which executes: php -S localhost:8000 -t public public/index.php
 */

declare(strict_types=1);

use Fomotoko\OnlineStore\Controllers\OrderController;
use Fomotoko\OnlineStore\Controllers\ProductController;
use Fomotoko\OnlineStore\Support\Config;
use Fomotoko\OnlineStore\Support\JsonResponse;
use Fomotoko\OnlineStore\Support\Request;
use Fomotoko\OnlineStore\Support\Router;

require __DIR__ . '/../vendor/autoload.php';

Config::load();

// --- Build the router -------------------------------------------------------
$router = new Router();

$products = new ProductController();
$orders   = new OrderController();

// Products
$router->add('GET',  '/products',              [$products, 'show']);
$router->add('GET',  '/products/{id}',         [$products, 'show']);
$router->add('POST', '/products',              [$products, 'create']);
$router->add('GET',  '/products/{id}/inventory', [$products, 'inventory']);

// Orders
$router->add('GET',  '/orders',                [$orders, 'show']);
$router->add('GET',  '/orders/{id}',           [$orders, 'show']);
$router->add('POST', '/orders',                [$orders, 'create']);

// --- Dispatch ---------------------------------------------------------------
$method = Request::method();
$uri    = Request::path();

$match = $router->dispatch($method, $uri);

if ($match === null) {
    // Method not allowed vs. not found.
    $methodExists = $router->dispatch('GET',    $uri) !== null
                 || $router->dispatch('POST',   $uri) !== null
                 || $router->dispatch('PUT',    $uri) !== null
                 || $router->dispatch('DELETE', $uri) !== null;
    if ($methodExists) {
        JsonResponse::error("Method {$method} not allowed for {$uri}.", 405, 'method_not_allowed');
    }
    JsonResponse::error("No route found for {$method} {$uri}.", 404, 'not_found');
}

try {
    ($match['handler'])($match['params']);
} catch (\Fomotoko\OnlineStore\Support\ResponseSentException $e) {
    // Normal control flow in test/capture mode: the response was recorded by
    // JsonResponse and there is nothing more to do.
} catch (\Throwable $e) {
    // Last-resort handler so the API always returns JSON.
    JsonResponse::error(
        $e->getMessage() ?: 'Internal server error.',
        500,
        'internal_error'
    );
}
