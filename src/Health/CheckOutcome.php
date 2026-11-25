<?php

declare(strict_types=1);

namespace Tollbooth\Health;

use Tollbooth\Core\Health\HealthStatus;

final readonly class CheckOutcome
{
    public function __construct(
        public string $name,
        public string $source,
        public HealthStatus $status,
        public string $summary,
        public float $duration,
    ) {
    }

    /**
     * @return array{name: string, source: string, status: string, summary: string, duration_ms: float}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'source' => $this->source,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'duration_ms' => $this->duration,
        ];
    }
}
