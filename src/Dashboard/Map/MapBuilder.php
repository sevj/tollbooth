<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

use Psr\Clock\ClockInterface;
use Tollbooth\Auth\ApiKey\ApiKeyProviderInterface;
use Tollbooth\Auth\ApiKey\ListableApiKeyProviderInterface;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Traffic\Overview;
use Tollbooth\Dashboard\Traffic\Range;
use Tollbooth\Dashboard\Traffic\TrafficReader;
use Tollbooth\Dashboard\Traffic\Window;
use Tollbooth\Docs\OpenApiGenerator;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class MapBuilder
{
    private const int BASELINE = 7 * 86400;

    public function __construct(
        private GroupCatalog $groups,
        private Thresholds $thresholds,
        private ?TrafficReader $traffic = null,
        private ?OpenApiGenerator $documents = null,
        private ?ApiKeyProviderInterface $keys = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function hasTraffic(): bool
    {
        return null !== $this->traffic;
    }

    public function build(string $group, Range $range, bool $withTraffic = true): ?ApiMap
    {
        $endpoints = $this->groups->endpoints($group);
        if ([] === $endpoints) {
            return null;
        }

        $traffic = $withTraffic ? $this->traffic : null;
        $window = Window::ending(($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp(), $range);
        $stats = $traffic?->byEndpoint($window, $group) ?? [];
        $baseline = $traffic?->byEndpoint($window->previous(self::BASELINE), $group) ?? [];

        $resources = [];
        $resourceByOperation = [];
        $strip = $this->groups->localPrefixes($group);
        foreach (ResourceGrouper::group($endpoints, $strip) as $name => $members) {
            $calls = array_sum(array_map(static fn (EndpointMetadata $endpoint): int => ($stats[$endpoint->routeName()] ?? null)->calls ?? 0, $members));
            $rows = [];
            foreach ($members as $endpoint) {
                $resourceByOperation[$endpoint->routeName()] = $name;
                $rows[] = $this->endpoint($endpoint, ResourceGrouper::localPath($endpoint->path, $strip), $stats[$endpoint->routeName()] ?? null, $calls);
            }
            $resources[] = new MapResource($name, ResourceGrouper::title($name), $rows, $calls);
        }
        usort($resources, static fn (MapResource $a, MapResource $b): int => [$b->calls, \count($b->endpoints), $a->name] <=> [$a->calls, \count($a->endpoints), $b->name]);

        $document = $this->documents?->generate($group);
        $models = null === $document ? null : new ModelReferences($document, $resourceByOperation);
        $references = $models?->references() ?? [];
        $rows = [];
        foreach ($resources as $resource) {
            $rows[$resource->name] = \count($resource->endpoints);
        }

        $overview = null;
        $attention = [];
        if (null !== $traffic) {
            $overview = $this->overview($traffic, $window, $range, $group, $stats);
            $attention = (new AttentionFinder($traffic, $this->thresholds))->find(
                $group,
                $window,
                array_merge(...array_map(static fn (MapResource $resource): array => $resource->endpoints, $resources)),
                $baseline,
            );
        }

        return new ApiMap(
            $group,
            $endpoints[0]->envelope,
            $endpoints[0]->case,
            $resources,
            $references,
            $models?->modelCount() ?? 0,
            MapLayout::arrange($rows, $references),
            $overview,
            $attention,
        );
    }

    private function endpoint(EndpointMetadata $endpoint, string $path, ?Stats $stats, int $resourceCalls): MapEndpoint
    {
        return new MapEndpoint(
            $endpoint,
            $path,
            $stats,
            null === $stats || 0 === $resourceCalls ? 0.0 : $stats->calls / $resourceCalls,
            $this->thresholds->failing($stats),
            $this->thresholds->throttling($stats),
            $this->thresholds->slow($stats),
        );
    }

    /**
     * @param array<string, Stats> $stats
     */
    private function overview(TrafficReader $traffic, Window $window, Range $range, string $group, array $stats): Overview
    {
        $previous = $window->previous();

        return new Overview(
            TrafficReader::sum($stats),
            $traffic->total($previous, $group),
            $traffic->buckets($window, $range->buckets(), $group),
            \count($traffic->byConsumer($window, $group)),
            \count($traffic->byConsumer($previous, $group)),
            $this->keyCount(),
            array_map(static fn (Window $bucket): int => \count($traffic->byConsumer($bucket, $group)), $window->split($range->buckets())),
        );
    }

    private function keyCount(): ?int
    {
        if (!$this->keys instanceof ListableApiKeyProviderInterface) {
            return null;
        }

        $count = 0;
        foreach ($this->keys->all() as $ignored) {
            ++$count;
        }

        return $count;
    }
}
