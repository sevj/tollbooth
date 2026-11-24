<?php

declare(strict_types=1);

namespace Tollbooth\Core\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Endpoint
{
    public function __construct(
        public string $method,
        public string $path,
        public int $status = 200,
        public ?string $name = null,
        public ?string $group = null,
    ) {
    }
}
