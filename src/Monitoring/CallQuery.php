<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

final readonly class CallQuery
{
    public function __construct(
        public ?string $endpoint = null,
        public ?string $group = null,
        public ?string $consumerId = null,
        public ?int $statusClass = null,
        public ?int $since = null,
        public ?string $before = null,
        public int $limit = 50,
        public ?int $status = null,
        public ?float $minDuration = null,
        public ?string $after = null,
        public ?string $id = null,
    ) {
    }
}
