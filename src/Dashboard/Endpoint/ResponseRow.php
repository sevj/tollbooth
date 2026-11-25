<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

/**
 * @internal
 */
final readonly class ResponseRow
{
    public function __construct(
        public int $status,
        public ?string $model,
        public string $description,
        public bool $problem = false,
    ) {
    }
}
