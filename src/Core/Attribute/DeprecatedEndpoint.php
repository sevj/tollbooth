<?php

declare(strict_types=1);

namespace Tollbooth\Core\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class DeprecatedEndpoint
{
    public function __construct(
        public string $since,
        public ?string $sunset = null,
        public ?string $link = null,
    ) {
    }
}
