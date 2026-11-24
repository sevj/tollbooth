<?php

declare(strict_types=1);

namespace Tollbooth\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Tollbooth\Core\Output\OutputRenderer;
use Tollbooth\Core\Problem\ProblemFactory;

/**
 * @internal
 */
final readonly class EndpointListener
{
    /**
     * @var list<string>
     */
    private array $apiPrefixes;

    /**
     * @param list<string> $apiPrefixes
     */
    public function __construct(
        private OutputRenderer $renderer,
        private ProblemFactory $problems,
        array $apiPrefixes = [],
    ) {
        $this->apiPrefixes = array_values(array_filter(array_map(static fn (string $prefix): string => rtrim($prefix, '/'), $apiPrefixes)));
    }

    public function onView(ViewEvent $event): void
    {
        $request = $event->getRequest();
        if (!EndpointRequest::isEndpoint($request)) {
            return;
        }

        $event->setResponse($this->renderer->render(
            $event->getControllerResult(),
            EndpointRequest::status($request),
            EndpointRequest::envelope($request),
            EndpointRequest::case($request),
        ));
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $isEndpoint = EndpointRequest::isEndpoint($request);
        if (!$isEndpoint && !$this->isUnderApiPrefix($request)) {
            return;
        }

        $problem = $this->problems->fromThrowable($event->getThrowable(), EndpointRequest::case($request));
        if (!$isEndpoint) {
            $problem = $problem->withoutDetail();
        }

        $event->allowCustomResponseCode();
        $event->setResponse(new Response(
            json_encode($problem->toArray(), OutputRenderer::JSON_FLAGS | \JSON_THROW_ON_ERROR),
            $problem->status,
            ['Content-Type' => 'application/problem+json'] + $problem->headers,
        ));
    }

    private function isUnderApiPrefix(Request $request): bool
    {
        $path = $request->getPathInfo();
        foreach ($this->apiPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
