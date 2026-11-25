<?php

declare(strict_types=1);

namespace Tollbooth\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Tollbooth\Core\Output\OutputRenderer;
use Tollbooth\Core\Problem\Problem;
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
        private bool $etag = true,
    ) {
        $this->apiPrefixes = array_values(array_filter(array_map(static fn (string $prefix): string => rtrim($prefix, '/'), $apiPrefixes)));
    }

    public function onView(ViewEvent $event): void
    {
        $request = $event->getRequest();
        if (!EndpointRequest::isEndpoint($request)) {
            return;
        }

        $this->timeHandler($request);
        $start = microtime(true);
        $response = $this->renderer->render(
            $event->getControllerResult(),
            EndpointRequest::status($request),
            EndpointRequest::envelope($request),
            EndpointRequest::case($request),
        );
        if ($this->etag && $request->isMethodCacheable() && Response::HTTP_OK === $response->getStatusCode()) {
            $response->setEtag(hash('xxh128', (string) $response->getContent()));
            $response->isNotModified($request);
        }
        $event->setResponse($response);
        EndpointRequest::addTiming($request, 'serialize', $start);
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
        } else {
            $this->timeHandler($request);
            EndpointRequest::setError($request, $this->describe($problem, $event->getThrowable()));
        }

        $event->allowCustomResponseCode();
        $event->setResponse(new Response(
            json_encode($problem->toArray(), OutputRenderer::JSON_FLAGS | \JSON_THROW_ON_ERROR),
            $problem->status,
            ['Content-Type' => 'application/problem+json'] + $problem->headers,
        ));
    }

    private function timeHandler(Request $request): void
    {
        $start = EndpointRequest::markedAt($request, TimingListener::HANDLER);
        if (null !== $start && !isset(EndpointRequest::timings($request)['handler'])) {
            EndpointRequest::addTiming($request, 'handler', $start);
        }
    }

    private function describe(Problem $problem, \Throwable $throwable): string
    {
        if ($problem->status >= 500) {
            return $throwable::class;
        }

        $violation = $problem->violations[0] ?? null;
        if (null !== $violation) {
            return $violation['field'].': '.$violation['message'];
        }

        return $problem->detail ?? $problem->title;
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
