<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestIdSubscriber implements EventSubscriberInterface
{
    private const HEADER = 'X-Request-ID';

    public function __construct(private readonly LoggerInterface $logger) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onRequest', 256],
            KernelEvents::RESPONSE => ['onResponse', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request   = $event->getRequest();
        $requestId = $request->headers->get(self::HEADER) ?? $this->generate();

        $request->attributes->set('request_id', $requestId);

        $this->logger->debug('Incoming request', [
            'request_id' => $requestId,
            'method'     => $request->getMethod(),
            'path'       => $request->getPathInfo(),
            'ip'         => $request->getClientIp(),
        ]);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get('request_id');
        if ($requestId !== null) {
            $event->getResponse()->headers->set(self::HEADER, $requestId);
        }
    }

    private function generate(): string
    {
        return sprintf('%s-%s', date('Ymd'), bin2hex(random_bytes(8)));
    }
}
