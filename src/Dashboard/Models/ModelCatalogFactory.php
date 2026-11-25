<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Models;

use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Docs\OpenApiGenerator;

/**
 * @internal
 */
final class ModelCatalogFactory
{
    /**
     * @var array<string, array{array<mixed>, ModelCatalog}|null>
     */
    private array $catalogs = [];

    public function __construct(
        private readonly OpenApiGenerator $documents,
        private readonly GroupCatalog $groups,
    ) {
    }

    public function catalog(string $group): ?ModelCatalog
    {
        return $this->load($group)[1] ?? null;
    }

    /**
     * @return array<mixed>|null
     */
    public function document(string $group): ?array
    {
        return $this->load($group)[0] ?? null;
    }

    /**
     * @return array{array<mixed>, ModelCatalog}|null
     */
    private function load(string $group): ?array
    {
        if (!\array_key_exists($group, $this->catalogs)) {
            $document = $this->documents->generate($group);
            $endpoints = [];
            foreach ($this->groups->endpoints($group) as $endpoint) {
                $endpoints[$endpoint->routeName()] = $endpoint;
            }
            $this->catalogs[$group] = null === $document ? null : [$document, new ModelCatalog($document, $endpoints)];
        }

        return $this->catalogs[$group];
    }

    /**
     * @return list<EndpointMetadata>
     */
    public function endpoints(string $group): array
    {
        return $this->groups->endpoints($group);
    }
}
