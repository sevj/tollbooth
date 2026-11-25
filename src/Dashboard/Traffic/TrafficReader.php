<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Traffic;

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

    public function total(Window $window, string $group, ?string $endpoint = null): Stats
    {
        return self::sum($this->calls->totals($window->from, $window->to, 'endpoint', new CallQuery(endpoint: $endpoint, group: $group)));
    }

    /**
     * @return list<Stats>
     */
    public function buckets(Window $window, int $parts, string $group, ?string $endpoint = null): array
    {
        return array_map(fn (Window $bucket): Stats => $this->total($bucket, $group, $endpoint), $window->split($parts));
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
