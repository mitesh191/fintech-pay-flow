<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\RequestContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Populates the request-scoped RequestContext with the client IP
 * from the incoming HTTP request.
 */
final class RequestContextListener implements EventSubscriberInterface
{
    public function __construct(private readonly RequestContext $requestContext) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 255],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->requestContext->setClientIp($event->getRequest()->getClientIp());
    }
}
