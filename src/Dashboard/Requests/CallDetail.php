<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Requests;

use Symfony\Component\HttpFoundation\Response;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Monitoring\CallRecord;

/**
 * @internal
 */
final readonly class CallDetail
{
    public const array STEPS = ['auth', 'rate_limit', 'hydrate', 'handler', 'serialize'];

    public function __construct(
        public CallRecord $call,
        public ?EndpointMetadata $endpoint,
    ) {
    }

    public function stoppedAt(): ?string
    {
        $status = $this->call->status;

        return match (true) {
            \in_array($status, [401, 403], true) => 'auth',
            429 === $status => 'rate_limit',
            \in_array($status, [400, 415, 422], true) => 'hydrate',
            $status >= 400 => 'handler',
            default => null,
        };
    }

    /**
     * @return list<array{key: string, state: 'ok'|'stopped'|'skipped'|'none', duration: ?float}>
     */
    public function pipeline(): array
    {
        $stop = $this->stoppedAt();
        $stopped = false;
        $steps = [];
        foreach (self::STEPS as $key) {
            $duration = $this->call->steps[$key] ?? null;
            $state = match (true) {
                $stopped => 'skipped',
                $key === $stop => 'stopped',
                'rate_limit' === $key && null === $this->call->limiters => 'none',
                'serialize' === $key && null === $duration && 204 !== $this->call->status => 'none',
                default => 'ok',
            };
            if ($key === $stop) {
                $stopped = true;
            }
            $steps[] = ['key' => $key, 'state' => $state, 'duration' => $duration];
        }

        return $steps;
    }

    /**
     * @return list<array{name: string, limit: int, window: int, remaining: int, reset: int, used: float, exhausted: bool, resetsAt: int}>
     */
    public function limiters(): array
    {
        return array_map(fn (array $limiter): array => [
            ...$limiter,
            'used' => 0 === $limiter['limit'] ? 0.0 : min(1.0, (float) ($limiter['limit'] - $limiter['remaining']) / $limiter['limit']),
            'exhausted' => 0 === $limiter['remaining'],
            'resetsAt' => intdiv($this->call->occurredAt, 1000) + $limiter['reset'],
        ], $this->call->limiters ?? []);
    }

    /**
     * @return list<array{string, string, bool}>
     */
    public function headers(): array
    {
        $status = $this->call->status;
        $headers = [['HTTP/1.1', $status.' '.(Response::$statusTexts[$status] ?? ''), false]];
        if (204 !== $status) {
            $headers[] = ['Content-Type', $status >= 400 ? 'application/problem+json' : ('json_api' === $this->endpoint?->envelope ? 'application/vnd.api+json' : 'application/json'), false];
        }
        if (null !== $this->call->responseSize) {
            $headers[] = ['Content-Length', (string) $this->call->responseSize, false];
        }
        $limiters = $this->call->limiters ?? [];
        if ([] !== $limiters) {
            $headers[] = ['RateLimit-Policy', implode(', ', array_map(static fn (array $limiter): string => \sprintf('"%s";q=%d;w=%d', $limiter['name'], $limiter['limit'], $limiter['window']), $limiters)), true];
            $headers[] = ['RateLimit', implode(', ', array_map(static fn (array $limiter): string => \sprintf('"%s";r=%d;t=%d', $limiter['name'], $limiter['remaining'], $limiter['reset']), $limiters)), true];
        }
        if (429 === $status) {
            $exhausted = array_filter($limiters, static fn (array $limiter): bool => 0 === $limiter['remaining']);
            $headers[] = ['Retry-After', (string) max([0, ...array_map(static fn (array $limiter): int => $limiter['reset'], $exhausted)]), true];
        }
        $deprecation = $this->endpoint?->deprecation;
        if (null !== $deprecation) {
            $headers[] = ['Deprecation', '@'.$deprecation['since'], false];
            if (null !== $deprecation['sunset']) {
                $headers[] = ['Sunset', gmdate('D, d M Y H:i:s', $deprecation['sunset']).' GMT', false];
            }
        }

        return $headers;
    }

    public function problem(): ?string
    {
        $status = $this->call->status;
        if ($status < 400) {
            return null;
        }

        $problem = ['type' => 'about:blank', 'title' => Response::$statusTexts[$status] ?? 'Error', 'status' => $status];
        $error = $this->call->error;
        if (422 === $status && null !== $error && 1 === preg_match('/^([^:\s]+): (.+)$/', $error, $match)) {
            $problem['title'] = 'Validation Failed';
            $problem['violations'] = [['field' => $match[1], 'message' => $match[2]]];
        } elseif (null !== $error && $status < 500) {
            $problem['detail'] = $error;
        }
        if (429 === $status && [] !== ($this->call->limiters ?? [])) {
            $problem['limiters'] = array_map(static fn (array $limiter): array => ['name' => $limiter['name'], 'limit' => $limiter['limit'], 'remaining' => $limiter['remaining'], 'reset' => $limiter['reset']], $this->call->limiters ?? []);
        }

        return (string) json_encode($problem, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    public function totalSteps(): float
    {
        return round(array_sum($this->call->steps), 1);
    }
}
