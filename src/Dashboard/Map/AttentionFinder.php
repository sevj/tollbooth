<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

use Tollbooth\Dashboard\Traffic\TrafficReader;
use Tollbooth\Dashboard\Traffic\Window;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class AttentionFinder
{
    private const int LIMIT = 10;

    public function __construct(
        private TrafficReader $traffic,
        private Thresholds $thresholds,
    ) {
    }

    /**
     * @param list<MapEndpoint>    $endpoints
     * @param array<string, Stats> $baseline
     *
     * @return list<Attention>
     */
    public function find(string $group, Window $window, array $endpoints, array $baseline): array
    {
        usort($endpoints, static fn (MapEndpoint $a, MapEndpoint $b): int => [$b->calls(), $a->metadata->path] <=> [$a->calls(), $b->metadata->path]);

        $found = [];
        foreach ($endpoints as $endpoint) {
            if ($endpoint->deprecated() && $endpoint->calls() > 0) {
                $sunset = $endpoint->sunset();
                $consumers = $this->consumers($group, $window, $endpoint, false);
                $found[] = new Attention(Attention::DEPRECATED, $endpoint->metadata, $endpoint->path, null === $sunset ? 'deprecated' : 'deprecated_sunset', [
                    'calls' => $endpoint->calls(),
                    'consumers' => self::names($consumers),
                    'sunset' => null === $sunset ? '' : gmdate('Y-m-d', $sunset),
                ], $consumers);
            }
        }
        foreach ($endpoints as $endpoint) {
            if ($endpoint->failing && null !== $endpoint->stats) {
                $found[] = $this->errors($group, $window, $endpoint, $endpoint->stats);
            }
        }
        foreach ($endpoints as $endpoint) {
            if ($endpoint->throttling && null !== $endpoint->stats) {
                $consumers = $this->consumers($group, $window, $endpoint, true);
                $found[] = new Attention(Attention::THROTTLING, $endpoint->metadata, $endpoint->path, 'throttling', [
                    'count' => $endpoint->stats->throttled,
                    'consumers' => self::names($consumers),
                ], $consumers);
            }
        }
        foreach ($endpoints as $endpoint) {
            $increase = $this->thresholds->slower($endpoint->stats, $baseline[$endpoint->metadata->routeName()] ?? null);
            if (null !== $increase) {
                $found[] = new Attention(Attention::SLOW, $endpoint->metadata, $endpoint->path, 'slow', [
                    'p95' => (int) round((float) $endpoint->p95()),
                    'increase' => (int) round($increase * 100),
                ]);
            }
        }

        return \array_slice($found, 0, self::LIMIT);
    }

    private function errors(string $group, Window $window, MapEndpoint $endpoint, Stats $stats): Attention
    {
        $statusClass = $stats->serverErrors > $stats->clientErrors ? 5 : 4;
        $causes = [];
        foreach ($this->traffic->recentErrors($window, $group, $endpoint->metadata->routeName(), $statusClass) as $call) {
            if (429 === $call->status) {
                continue;
            }
            $cause = self::cause($call);
            $causes[$cause] = ($causes[$cause] ?? 0) + 1;
        }
        arsort($causes);
        $cause = array_key_first($causes);

        return new Attention(Attention::ERRORS, $endpoint->metadata, $endpoint->path, null === $cause ? 'errors' : 'errors_cause', [
            'rate' => round($stats->errorRate() * 100, 1),
            'cause' => (string) $cause,
        ]);
    }

    private static function cause(CallRecord $call): string
    {
        $error = trim((string) $call->error);
        if ('' === $error) {
            return (string) $call->status;
        }
        if (mb_strlen($error) > 80) {
            $error = mb_substr($error, 0, 79).'…';
        }

        return $call->status.' · '.$error;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function consumers(string $group, Window $window, MapEndpoint $endpoint, bool $throttledOnly): array
    {
        $consumers = [];
        foreach ($this->traffic->byConsumer($window, $group, $endpoint->metadata->routeName()) as $consumer) {
            if (!$throttledOnly || $consumer->throttled > 0) {
                $consumers[] = ['id' => $consumer->key, 'label' => $consumer->label];
            }
        }

        return $consumers;
    }

    /**
     * @param list<array{id: string, label: string}> $consumers
     */
    private static function names(array $consumers): string
    {
        $shown = array_column(\array_slice($consumers, 0, 2), 'label');
        $more = \count($consumers) - \count($shown);

        return implode(', ', $shown).($more > 0 ? ' +'.$more : '');
    }
}
