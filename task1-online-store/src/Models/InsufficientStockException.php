<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Models;

/**
 * Thrown by Inventory::decrementForUpdate() when there is not enough stock to
 * fulfil a request. The controller turns this into a 409 Conflict response.
 */
final class InsufficientStockException extends \RuntimeException
{
}
