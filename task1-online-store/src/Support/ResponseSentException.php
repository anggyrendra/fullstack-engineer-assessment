<?php

declare(strict_types=1);

namespace Fomotoko\OnlineStore\Support;

/**
 * Thrown by JsonResponse when in capture mode, so the controller code that
 * would normally `exit` instead unwinds back to the test harness. Tests catch
 * this and read JsonResponse::lastResponse().
 */
final class ResponseSentException extends \RuntimeException
{
}
