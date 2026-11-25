<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

final readonly class ErrorGroup
{
    public function __construct(
        public int $status,
        public ?string $error,
        public int $calls,
        public int $consumers,
        public int $lastSeenAt,
    ) {
    }
}
