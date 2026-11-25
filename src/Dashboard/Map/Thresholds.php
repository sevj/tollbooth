<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class Thresholds
{
    public function __construct(
        public float $errorRate = 0.05,
        public int $minimumCalls = 20,
        public float $slowMs = 250.0,
        public float $slowIncrease = 0.5,
        public float $throttledShare = 0.01,
        public int $minimumThrottled = 10,
    ) {
    }

    public function failing(?Stats $stats): bool
    {
        return null !== $stats && $stats->calls >= $this->minimumCalls && $stats->errorRate() > $this->errorRate;
    }

    public function throttling(?Stats $stats): bool
    {
        return null !== $stats && $stats->throttled >= $this->minimumThrottled && $stats->throttled / $stats->calls >= $this->throttledShare;
    }

    public function slow(?Stats $stats): bool
    {
        $p95 = $stats?->percentile(0.95);

        return null !== $p95 && $p95 >= $this->slowMs;
    }

    public function slower(?Stats $stats, ?Stats $baseline): ?float
    {
        if (null === $stats || null === $baseline || $stats->calls < $this->minimumCalls || $baseline->calls < $this->minimumCalls || !$this->slow($stats)) {
            return null;
        }

        $now = (float) $stats->percentile(0.95);
        $before = (float) $baseline->percentile(0.95);
        if ($before <= 0.0) {
            return null;
        }

        $increase = ($now - $before) / $before;

        return $increase >= $this->slowIncrease ? $increase : null;
    }
}
