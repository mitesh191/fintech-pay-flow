<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\RequestIdSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for RequestIdSubscriber.
 *
 * Distributed tracing: every HTTP request must carry a deterministic ID
 * for log correlation across services.  If none is provided, one is generated.
 */
final class RequestIdSubscriberTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private RequestIdSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->subscriber = new RequestIdSubscriber($this->logger);
    }

    // ─── Event subscriptions ──────────────────────────────────────────────────

    public function test_subscribes_to_request_event_with_high_priority(): void
    {
        $events = RequestIdSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    }

    public function test_subscribes_to_response_event(): void
    {
        $events = RequestIdSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    public function test_request_event_priority_is_256(): void
    {
        $events    = RequestIdSubscriber::getSubscribedEvents();
        $listeners = $events[KernelEvents::REQUEST];

        // Either [method, priority] or [[method, priority]]
        $priority = is_array($listeners[0]) ? $listeners[1] : $listeners[1] ?? null;
        $this->assertSame(256, $priority);
    }

    // ─── onRequest() ─────────────────────────────────────────────────────────

    public function test_uses_existing_x_request_id_from_header(): void
    {
        $request = new Request();
        $request->headers->set('X-Request-ID', 'preset-id-123');

        $event = $this->makeRequestEvent($request);
        $this->subscriber->onRequest($event);

        $this->assertSame('preset-id-123', $request->attributes->get('request_id'));
    }

    public function test_generates_request_id_when_header_absent(): void
    {
        $request = new Request();
        $event   = $this->makeRequestEvent($request);

        $this->subscriber->onRequest($event);

        $requestId = $request->attributes->get('request_id');
        $this->assertNotNull($requestId);
        $this->assertNotEmpty($requestId);
    }

    public function test_generated_id_has_expected_format(): void
    {
        $request = new Request();
        $event   = $this->makeRequestEvent($request);

        $this->subscriber->onRequest($event);

        $requestId = $request->attributes->get('request_id');
        // Default format: Ymd-{hex}
        $this->assertMatchesRegularExpression('/^\d{8}-[0-9a-f]+$/', $requestId);
    }

    public function test_request_id_stored_in_attributes(): void
    {
        $request = new Request();
        $event   = $this->makeRequestEvent($request);

        $this->subscriber->onRequest($event);

        $this->assertTrue($request->attributes->has('request_id'));
    }

    public function test_logs_debug_on_request(): void
    {
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Incoming request', $this->callback(fn(array $ctx) =>
                isset($ctx['request_id']) && isset($ctx['method']) && isset($ctx['path'])
            ));

        $event = $this->makeRequestEvent(new Request());
        $this->subscriber->onRequest($event);
    }

    public function test_sub_request_is_ignored(): void
    {
        $request = new Request();
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $event   = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->subscriber->onRequest($event);

        $this->assertNull($request->attributes->get('request_id'));
    }

    // ─── onResponse() ─────────────────────────────────────────────────────────

    public function test_echoes_request_id_in_response_header(): void
    {
        $request = new Request();
        $request->attributes->set('request_id', 'trace-abc-123');

        $response = new Response();
        $event    = $this->makeResponseEvent($request, $response);

        $this->subscriber->onResponse($event);

        $this->assertSame('trace-abc-123', $response->headers->get('X-Request-ID'));
    }

    public function test_does_not_set_header_when_no_request_id(): void
    {
        $request  = new Request(); // no request_id attribute
        $response = new Response();
        $event    = $this->makeResponseEvent($request, $response);

        $this->subscriber->onResponse($event);

        $this->assertNull($response->headers->get('X-Request-ID'));
    }

    public function test_sub_response_is_ignored(): void
    {
        $request = new Request();
        $request->attributes->set('request_id', 'trace-id');

        $response = new Response();
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $event    = new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, $response);

        $this->subscriber->onResponse($event);

        $this->assertNull($response->headers->get('X-Request-ID'));
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeRequestEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeResponseEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
