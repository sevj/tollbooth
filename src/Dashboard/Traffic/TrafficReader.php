<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Traffic;

use Tollbooth\Monitoring\ActiveConsumersInterface;
use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallReaderInterface;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\LatencyHistogram;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class TrafficReader
{
    private const int SERIES_LIMIT = 7 * 86400;

    public function __construct(
        private CallReaderInterface $calls,
    ) {
    }

    /**
     * @return array<string, Stats>
     */
    public function byEndpoint(Window $window, string $group): array
    {
        $stats = [];
        foreach ($this->calls->totals($window->from, $window->to, 'endpoint', new CallQuery(group: $group)) as $endpoint) {
            $stats[$endpoint->key] = $endpoint;
        }

        return $stats;
    }

    /**
     * @return list<Stats>
     */
    public function byConsumer(Window $window, string $group, ?string $endpoint = null): array
    {
        return $this->calls->totals($window->from, $window->to, 'consumer', new CallQuery(endpoint: $endpoint, group: $group));
    }

    /**
     * @return list<int>
     */
    public function activeConsumers(Window $window, int $parts, string $group): array
    {
        if ($this->calls instanceof ActiveConsumersInterface) {
            return $this->calls->activeConsumers($window->from, $window->to, $parts, new CallQuery(group: $group));
        }

        return array_map(fn (Window $bucket): int => \count($this->byConsumer($bucket, $group)), $window->split($parts));
    }

    public function total(Window $window, string $group, ?string $endpoint = null): Stats
    {
        return self::sum($this->calls->totals($window->from, $window->to, 'endpoint', new CallQuery(endpoint: $endpoint, group: $group)));
    }

    /**
     * @return list<Stats>
     */
    public function buckets(Window $window, int $parts, string $group, ?string $endpoint = null): array
    {
        $windows = $window->split($parts);
        if ($window->to - $window->from > self::SERIES_LIMIT) {
            return array_map(fn (Window $bucket): Stats => $this->total($bucket, $group, $endpoint), $windows);
        }

        $buckets = array_map(static fn (): Stats => self::empty(), $windows);
        $size = intdiv($window->to - $window->from, max(1, $parts));
        foreach ($this->calls->series($window->from, $window->to, new CallQuery(endpoint: $endpoint, group: $group)) as $minute => $stats) {
            $index = min($parts - 1, max(0, intdiv($minute - $window->from, max(1, $size))));
            $buckets[$index] = $buckets[$index]->merge($stats);
        }

        return array_values($buckets);
    }

    /**
     * @return list<CallRecord>
     */
    public function recent(Window $window, string $group, string $endpoint, int $limit = 500): array
    {
        return $this->calls->recent(new CallQuery(endpoint: $endpoint, group: $group, since: $window->from * 1000, limit: $limit));
    }

    /**
     * @param 4|5 $statusClass
     *
     * @return list<CallRecord>
     */
    public function recentErrors(Window $window, string $group, string $endpoint, int $statusClass, int $limit = 200): array
    {
        return $this->calls->recent(new CallQuery(endpoint: $endpoint, group: $group, statusClass: $statusClass, since: $window->from * 1000, limit: $limit));
    }

    /**
     * @param iterable<Stats> $stats
     */
    public static function sum(iterable $stats, string $key = 'total'): Stats
    {
        $sum = self::empty($key);
        foreach ($stats as $item) {
            $sum = $sum->merge($item);
        }

        return $sum;
    }

    public static function empty(string $key = 'total'): Stats
    {
        return new Stats($key, $key, 0, 0, 0, 0, 0.0, 0.0, array_fill(0, LatencyHistogram::buckets(), 0));
    }
}
