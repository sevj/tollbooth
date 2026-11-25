<?php

declare(strict_types=1);

namespace Tollbooth\Core\Health;

use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Core\Metadata\EndpointRegistry;

/**
 * @internal
 */
final readonly class MetadataCheck implements HealthCheckInterface
{
    public function __construct(
        private EndpointRegistry $registry,
    ) {
    }

    public function name(): string
    {
        return 'metadata';
    }

    public function check(): CheckResult
    {
        $endpoints = $this->registry->all();
        if ([] === $endpoints) {
            return CheckResult::warning('No endpoint is declared, or the container was built before they were.');
        }

        $groups = \count(array_unique(array_map(static fn (EndpointMetadata $endpoint): string => $endpoint->group, $endpoints)));

        return CheckResult::ok(\sprintf('%d endpoint%s compiled in %d group%s.', \count($endpoints), 1 === \count($endpoints) ? '' : 's', $groups, 1 === $groups ? '' : 's'));
    }
}
