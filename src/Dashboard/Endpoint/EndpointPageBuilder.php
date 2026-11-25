<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

use Psr\Clock\ClockInterface;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Map\ResourceGrouper;
use Tollbooth\Dashboard\Traffic\Range;
use Tollbooth\Dashboard\Traffic\TrafficReader;
use Tollbooth\Dashboard\Traffic\Window;
use Tollbooth\Docs\OpenApiGenerator;
use Tollbooth\Monitoring\CallBreakdownInterface;
use Tollbooth\Monitoring\CallQuery;

/**
 * @internal
 */
final readonly class EndpointPageBuilder
{
    public function __construct(
        private GroupCatalog $groups,
        private bool $rateLimited,
        private string $projectDir = '',
        private ?TrafficReader $traffic = null,
        private ?CallBreakdownInterface $breakdown = null,
        private ?OpenApiGenerator $documents = null,
        private ?ClockInterface $clock = null,
        private bool $limitsEnforced = true,
    ) {
    }

    private function limited(EndpointMetadata $endpoint): bool
    {
        return $this->rateLimited || ($this->limitsEnforced && [] !== $endpoint->limits);
    }

    public function find(string $group, string $name): ?EndpointMetadata
    {
        foreach ($this->groups->endpoints($group) as $endpoint) {
            if ($endpoint->name === $name) {
                return $endpoint;
            }
        }

        return null;
    }

    public function build(EndpointMetadata $endpoint, Range $range): EndpointPage
    {
        $group = $endpoint->group;
        $document = $this->documents?->generate($group);
        $operation = null === $document ? null : OperationReader::read($document, $endpoint->routeName());
        $resource = ResourceGrouper::resourceOf(ResourceGrouper::localPath($endpoint->path, $this->groups->localPrefixes($group)));

        if (null === $this->traffic) {
            return new EndpointPage($endpoint, $resource, $operation, Declaration::of($endpoint->class, $this->projectDir), Pipeline::steps($endpoint, $operation, [], $this->limited($endpoint)));
        }

        $window = Window::ending(($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp(), $range);
        $route = $endpoint->routeName();
        $filter = new CallQuery(endpoint: $route, group: $group);
        $statuses = $this->breakdown?->statuses($window->from, $window->to, $filter) ?? [];

        return new EndpointPage(
            $endpoint,
            $resource,
            $operation,
            Declaration::of($endpoint->class, $this->projectDir),
            Pipeline::steps($endpoint, $operation, $statuses, $this->limited($endpoint)),
            $this->traffic->total($window, $group, $route),
            $statuses,
            $this->traffic->buckets($window, $range->buckets(), $group, $route),
            \array_slice($this->traffic->byConsumer($window, $group, $route), 0, 5),
            $this->breakdown?->errors($window->from, $window->to, $filter, 6) ?? [],
            TimeBreakdown::aroundP95($this->traffic->recent($window, $group, $route)),
        );
    }
}
