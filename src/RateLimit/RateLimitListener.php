<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Tollbooth\Auth\AccessListener;
use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Http\EndpointRequest;
use Tollbooth\Core\Metadata\Limit;

/**
 * @internal
 */
final readonly class RateLimitListener
{
    public const string STATES_ATTRIBUTE = '_tollbooth_rate_limits';

    public function __construct(
        private PlanRegistry $plans,
        private WindowCounter $counter,
        private bool $legacyHeaders = false,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $consumer = $request->attributes->get(AccessListener::CONSUMER_ATTRIBUTE);
        if (!$consumer instanceof Consumer || !EndpointRequest::isEndpoint($request)) {
            return;
        }

        $limits = [...$this->plans->limitsFor($consumer), ...EndpointRequest::limits($request)];
        if ([] === $limits) {
            return;
        }

        $states = [];
        $rejected = false;
        foreach ($limits as $limit) {
            $state = $this->counter->hit($consumer->id, $limit);
            $states[] = $state;
            $rejected = $rejected || !$state->accepted;
        }

        if ($rejected) {
            $states = array_map(
                fn (LimitState $state, Limit $limit): LimitState => $state->accepted ? $this->counter->refund($consumer->id, $limit) : $state,
                $states,
                $limits,
            );
        }

        $request->attributes->set(self::STATES_ATTRIBUTE, $states);
        if ($rejected) {
            throw new RateLimitExceededException($states);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $states = $event->getRequest()->attributes->get(self::STATES_ATTRIBUTE);
        if (!\is_array($states) || [] === $states) {
            return;
        }

        $states = array_values(array_filter($states, static fn (mixed $state): bool => $state instanceof LimitState));
        $this->addHeaders($event->getResponse(), $states);
    }

    /**
     * @param list<LimitState> $states
     */
    private function addHeaders(Response $response, array $states): void
    {
        $response->headers->set('RateLimit-Policy', implode(', ', array_map(
            static fn (LimitState $state): string => \sprintf('"%s";q=%d;w=%d', $state->name, $state->limit, $state->window),
            $states,
        )));
        $response->headers->set('RateLimit', implode(', ', array_map(
            static fn (LimitState $state): string => \sprintf('"%s";r=%d;t=%d', $state->name, $state->remaining, $state->resetIn),
            $states,
        )));

        if ($this->legacyHeaders) {
            usort($states, static fn (LimitState $a, LimitState $b): int => [$a->remaining, -$a->resetIn] <=> [$b->remaining, -$b->resetIn]);
            $tightest = $states[0];
            $response->headers->set('X-RateLimit-Limit', (string) $tightest->limit);
            $response->headers->set('X-RateLimit-Remaining', (string) $tightest->remaining);
            $response->headers->set('X-RateLimit-Reset', (string) $tightest->resetIn);
        }
    }
}
