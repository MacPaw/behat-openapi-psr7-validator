<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\EventListener;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static holder to share request/response between kernel event subscriber and Behat context.
 */
final class RequestResponseHolder
{
    private static ?Request $lastRequest = null;
    private static ?Response $lastResponse = null;

    public static function capture(Request $request, Response $response): void
    {
        self::$lastRequest = $request;
        self::$lastResponse = $response;
    }

    public static function getLastRequest(): ?Request
    {
        return self::$lastRequest;
    }

    public static function getLastResponse(): ?Response
    {
        return self::$lastResponse;
    }

    public static function reset(): void
    {
        self::$lastRequest = null;
        self::$lastResponse = null;
    }
}
