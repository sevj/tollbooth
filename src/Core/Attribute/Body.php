<?php

declare(strict_types=1);

namespace Tollbooth\Core\Attribute;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Body
{
    /**
     * @param list<string>|null $groups
     */
    public function __construct(
        public ?array $groups = null,
    ) {
    }
}
