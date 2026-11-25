<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

use Tollbooth\Dashboard\Traffic\Overview;

/**
 * @internal
 */
final readonly class ApiMap
{
    /**
     * @param list<MapResource> $resources
     * @param list<Reference>   $references
     * @param list<Attention>   $attention
     */
    public function __construct(
        public string $group,
        public string $envelope,
        public string $case,
        public array $resources,
        public array $references,
        public int $models,
        public Diagram $diagram,
        public ?Overview $overview = null,
        public array $attention = [],
    ) {
    }

    public function endpointCount(): int
    {
        return array_sum(array_map(static fn (MapResource $resource): int => \count($resource->endpoints), $this->resources));
    }

    public function resource(string $name): ?MapResource
    {
        foreach ($this->resources as $resource) {
            if ($resource->name === $name) {
                return $resource;
            }
        }

        return null;
    }
}
