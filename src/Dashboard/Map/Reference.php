<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

/**
 * @internal
 */
final readonly class Reference
{
    public function __construct(
        public string $from,
        public string $to,
        public string $label,
    ) {
    }
}
