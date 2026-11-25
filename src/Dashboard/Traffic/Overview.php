<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Traffic;

use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class Overview
{
    /**
     * @param list<Stats> $buckets
     * @param list<int>   $consumerBuckets
     */
    public function __construct(
        public Stats $current,
        public Stats $previous,
        public array $buckets,
        public int $consumers,
        public int $previousConsumers,
        public ?int $keys,
        public array $consumerBuckets = [],
    ) {
    }

    public function callsChange(): ?float
    {
        return 0 === $this->previous->calls ? null : ($this->current->calls - $this->previous->calls) / $this->previous->calls;
    }

    public function errorRateChange(): ?float
    {
        return 0 === $this->previous->calls ? null : $this->current->errorRate() - $this->previous->errorRate();
    }

    public function p95Change(): ?float
    {
        $current = $this->current->percentile(0.95);
        $previous = $this->previous->percentile(0.95);

        return null === $current || null === $previous ? null : $current - $previous;
    }

    public function throttledShare(): float
    {
        return 0 === $this->current->calls ? 0.0 : $this->current->throttled / $this->current->calls;
    }

    /**
     * @param 'calls'|'errors'|'p95'|'throttled'|'consumers' $metric
     *
     * @return list<float>
     */
    public function series(string $metric): array
    {
        if ('consumers' === $metric) {
            return array_map(static fn (int $count): float => (float) $count, $this->consumerBuckets);
        }

        return array_map(static fn (Stats $bucket): float => match ($metric) {
            'calls' => (float) $bucket->calls,
            'errors' => $bucket->errorRate(),
            'p95' => $bucket->percentile(0.95) ?? 0.0,
            'throttled' => (float) $bucket->throttled,
        }, $this->buckets);
    }
}
