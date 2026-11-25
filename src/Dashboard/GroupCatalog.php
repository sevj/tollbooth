<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard;

use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Core\Metadata\Group;

/**
 * @internal
 */
final class GroupCatalog
{
    /**
     * @var array<string, list<EndpointMetadata>>|null
     */
    private ?array $endpoints = null;

    /**
     * @param array<string, array{mount: string, prefix: string, path: ?string}> $groups
     */
    public function __construct(
        private readonly EndpointRegistry $registry,
        private readonly array $groups = [],
        private readonly string $defaultMount = '',
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $endpoints = $this->all();
        $names = array_values(array_filter([Group::DEFAULT, ...array_keys($this->groups)], static fn (string $name): bool => isset($endpoints[$name])));

        return array_values(array_unique([...$names, ...array_keys($endpoints)]));
    }

    public function has(string $group): bool
    {
        return isset($this->all()[$group]);
    }

    /**
     * @return list<EndpointMetadata>
     */
    public function endpoints(string $group): array
    {
        return $this->all()[$group] ?? [];
    }

    public function mount(string $group): string
    {
        $mount = $this->groups[$group]['mount'] ?? $this->defaultMount;

        return '' === $mount ? '/' : $mount;
    }

    /**
     * @return list<string>
     */
    public function localPrefixes(string $group): array
    {
        return array_values(array_filter([$this->groups[$group]['prefix'] ?? '', $this->groups[$group]['path'] ?? '']));
    }

    /**
     * @return array<string, list<EndpointMetadata>>
     */
    private function all(): array
    {
        if (null === $this->endpoints) {
            $this->endpoints = [];
            foreach ($this->registry->all() as $endpoint) {
                $this->endpoints[$endpoint->group][] = $endpoint;
            }
        }

        return $this->endpoints;
    }
}
