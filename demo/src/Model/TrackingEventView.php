<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class TrackingEventView
{
    public function __construct(
        public string $status,
        public string $location,
        public \DateTimeImmutable $at,
    ) {
    }
}
