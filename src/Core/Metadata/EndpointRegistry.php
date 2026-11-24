<?php

declare(strict_types=1);

namespace Tollbooth\Core\Metadata;

/**
 * @internal
 *
 * @phpstan-import-type EndpointArray from EndpointMetadata
 */
final class EndpointRegistry
{
    /**
     * @var list<EndpointMetadata>|null
     */
    private ?array $endpoints = null;

    /**
     * @param list<EndpointArray> $compiled
     */
    public function __construct(
        private readonly array $compiled = [],
    ) {
    }

    /**
     * @return list<EndpointMetadata>
     */
    public function all(): array
    {
        return $this->endpoints ??= array_map(EndpointMetadata::fromArray(...), $this->compiled);
    }
}
