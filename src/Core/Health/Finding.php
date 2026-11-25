<?php

declare(strict_types=1);

namespace Tollbooth\Core\Health;

final readonly class Finding
{
    public function __construct(
        public string $message,
        public bool $warning = false,
    ) {
    }
}
