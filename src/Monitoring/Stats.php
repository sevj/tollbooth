<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

final readonly class Stats
{
    /**
     * @param list<int> $latency one count per LatencyHistogram bucket
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $calls,
        public int $clientErrors,
        public int $serverErrors,
        public int $throttled,
        public float $durationSum,
        public float $durationMax,
        public array $latency,
    ) {
    }

    public function errorRate(): float
    {
        return 0 === $this->calls ? 0.0 : ($this->clientErrors + $this->serverErrors) / $this->calls;
    }

    public function percentile(float $percentile): ?float
    {
        return LatencyHistogram::percentile($this->latency, $percentile, $this->durationMax);
    }

    public function merge(self $other): self
    {
        return new self(
            $this->key,
            $this->label,
            $this->calls + $other->calls,
            $this->clientErrors + $other->clientErrors,
            $this->serverErrors + $other->serverErrors,
            $this->throttled + $other->throttled,
            $this->durationSum + $other->durationSum,
            max($this->durationMax, $other->durationMax),
            array_map(static fn (int $a, int $b): int => $a + $b, $this->latency, $other->latency),
        );
    }
}
