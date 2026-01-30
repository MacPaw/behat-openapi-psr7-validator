<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Tests\Unit\EventListener;

use BehatOpenApiValidator\EventListener\ApiContextListener;
use BehatOpenApiValidator\EventListener\RequestResponseHolder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiContextListenerTest extends TestCase
{
    protected function setUp(): void
    {
        RequestResponseHolder::reset();
    }

    #[Test]
    public function itSubscribesToKernelResponseEvent(): void
    {
        $subscribedEvents = ApiContextListener::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::RESPONSE, $subscribedEvents);
        self::assertSame(['onKernelResponse', -1000], $subscribedEvents[KernelEvents::RESPONSE]);
    }

    #[Test]
    public function itCapturesRequestAndResponseForMainRequest(): void
    {
        $request = Request::create('/api/users', 'GET');
        $response = new Response('{"users":[]}', 200);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $event = new ResponseEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        $listener = new ApiContextListener();
        $listener->onKernelResponse($event);

        self::assertSame($request, RequestResponseHolder::getLastRequest());
        self::assertSame($response, RequestResponseHolder::getLastResponse());
    }

    #[Test]
    public function itDoesNotCaptureForSubRequest(): void
    {
        $request = Request::create('/api/users', 'GET');
        $response = new Response('{"users":[]}', 200);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $event = new ResponseEvent(
            $kernel,
            $request,
            HttpKernelInterface::SUB_REQUEST,
            $response
        );

        $listener = new ApiContextListener();
        $listener->onKernelResponse($event);

        self::assertNull(RequestResponseHolder::getLastRequest());
        self::assertNull(RequestResponseHolder::getLastResponse());
    }
}
