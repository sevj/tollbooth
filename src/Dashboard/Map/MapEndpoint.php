<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class MapEndpoint
{
    public function __construct(
        public EndpointMetadata $metadata,
        public string $path,
        public ?Stats $stats = null,
        public float $share = 0.0,
        public bool $failing = false,
        public bool $throttling = false,
        public bool $slow = false,
    ) {
    }

    public function calls(): int
    {
        return $this->stats->calls ?? 0;
    }

    public function p95(): ?float
    {
        return $this->stats?->percentile(0.95);
    }

    public function deprecated(): bool
    {
        return null !== $this->metadata->deprecation;
    }

    public function sunset(): ?int
    {
        return $this->metadata->deprecation['sunset'] ?? null;
    }
}
