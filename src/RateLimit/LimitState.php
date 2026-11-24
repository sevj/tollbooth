<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit;

/**
 * @internal
 */
final readonly class LimitState
{
    public function __construct(
        public string $name,
        public int $limit,
        public int $window,
        public int $remaining,
        public int $resetIn,
        public bool $accepted,
    ) {
    }

    /**
     * @return array{name: string, limit: int, remaining: int, reset: int}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'limit' => $this->limit, 'remaining' => $this->remaining, 'reset' => $this->resetIn];
    }
}
