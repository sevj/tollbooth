<?php

declare(strict_types=1);

namespace Tollbooth\Core\Http;

use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * @internal
 */
final class TimingListener
{
    public const string ARGUMENTS = 'arguments';
    public const string HANDLER = 'handler';
    public const string DONE = 'done';

    public function onController(ControllerEvent $event): void
    {
        if (EndpointRequest::isEndpoint($event->getRequest())) {
            EndpointRequest::mark($event->getRequest(), self::ARGUMENTS);
        }
    }

    public function onArgumentsResolved(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();
        $start = EndpointRequest::markedAt($request, self::ARGUMENTS);
        if (null !== $start) {
            EndpointRequest::addTiming($request, 'hydrate', $start);
        }
    }

    public function onHandlerStart(ControllerArgumentsEvent $event): void
    {
        if (EndpointRequest::isEndpoint($event->getRequest())) {
            EndpointRequest::mark($event->getRequest(), self::HANDLER);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && EndpointRequest::isEndpoint($event->getRequest())) {
            EndpointRequest::mark($event->getRequest(), self::DONE);
        }
    }
}
