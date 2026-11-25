<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

/**
 * @internal
 */
final readonly class MapResource
{
    /**
     * @param list<MapEndpoint> $endpoints
     */
    public function __construct(
        public string $name,
        public string $title,
        public array $endpoints,
        public int $calls,
    ) {
    }
}
