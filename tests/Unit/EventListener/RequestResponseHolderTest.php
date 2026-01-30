<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Tests\Unit\EventListener;

use BehatOpenApiValidator\EventListener\RequestResponseHolder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequestResponseHolderTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestResponseHolder::reset();
    }

    #[Test]
    public function itReturnsNullWhenNothingCaptured(): void
    {
        RequestResponseHolder::reset();

        self::assertNull(RequestResponseHolder::getLastRequest());
        self::assertNull(RequestResponseHolder::getLastResponse());
    }

    #[Test]
    public function itCapturesRequestAndResponse(): void
    {
        $request = Request::create('/test', 'GET');
        $response = new Response('OK', 200);

        RequestResponseHolder::capture($request, $response);

        self::assertSame($request, RequestResponseHolder::getLastRequest());
        self::assertSame($response, RequestResponseHolder::getLastResponse());
    }

    #[Test]
    public function itResetsState(): void
    {
        $request = Request::create('/test', 'GET');
        $response = new Response('OK', 200);

        RequestResponseHolder::capture($request, $response);
        RequestResponseHolder::reset();

        self::assertNull(RequestResponseHolder::getLastRequest());
        self::assertNull(RequestResponseHolder::getLastResponse());
    }

    #[Test]
    public function itOverwritesPreviousCapture(): void
    {
        $request1 = Request::create('/test1', 'GET');
        $response1 = new Response('OK1', 200);
        $request2 = Request::create('/test2', 'POST');
        $response2 = new Response('OK2', 201);

        RequestResponseHolder::capture($request1, $response1);
        RequestResponseHolder::capture($request2, $response2);

        self::assertSame($request2, RequestResponseHolder::getLastRequest());
        self::assertSame($response2, RequestResponseHolder::getLastResponse());
    }
}
