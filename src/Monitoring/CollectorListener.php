<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Tollbooth\Auth\AccessListener;
use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Http\EndpointRequest;
use Tollbooth\Core\Http\TimingListener;

/**
 * @internal
 */
final readonly class CollectorListener
{
    public function __construct(
        private CallRecorderInterface $recorder,
        private ?AutoRollup $rollup = null,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if (!EndpointRequest::isEndpoint($request)) {
            return;
        }

        try {
            $now = $this->clock?->now() ?? new \DateTimeImmutable();
            $this->recorder->record($this->call($request, $event->getResponse(), (int) $now->format('Uv')));
            $this->rollup?->maybeRun($now->getTimestamp());
        } catch (\Throwable $exception) {
            $this->logger?->error('Tollbooth could not record an API call: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
        }
    }

    private function call(Request $request, Response $response, int $occurredAt): CallRecord
    {
        $consumer = $request->attributes->get(AccessListener::CONSUMER_ATTRIBUTE);
        $consumer = $consumer instanceof Consumer ? $consumer : null;
        $ip = $request->getClientIp();

        return new CallRecord(
            id: CallRecord::newId($occurredAt),
            occurredAt: $occurredAt,
            endpoint: EndpointRequest::routeName($request),
            group: EndpointRequest::group($request),
            method: $request->getMethod(),
            path: $request->getPathInfo(),
            status: $response->getStatusCode(),
            duration: $this->duration($request),
            consumerId: $consumer->id ?? 'ip:'.($ip ?? 'unknown'),
            consumerName: $consumer->name ?? 'ip:'.($ip ?? 'unknown'),
            authMethod: $consumer?->method->value ?? 'none',
            ip: $ip,
            responseSize: $response instanceof StreamedResponse ? null : \strlen((string) $response->getContent()),
            error: $response->getStatusCode() >= 400 ? EndpointRequest::error($request) : null,
            steps: EndpointRequest::timings($request),
            limiters: RateLimitHeaders::parse($response->headers->get('RateLimit-Policy'), $response->headers->get('RateLimit')),
        );
    }

    private function duration(Request $request): float
    {
        $start = $request->server->get('REQUEST_TIME_FLOAT');
        $start = is_numeric($start) ? (float) $start : null;
        $end = EndpointRequest::markedAt($request, TimingListener::DONE) ?? microtime(true);

        return null === $start ? 0.0 : round(max(0.0, $end - $start) * 1000, 3);
    }
}
