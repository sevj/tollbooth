<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoints;

use Psr\Clock\ClockInterface;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Map\MapEndpoint;
use Tollbooth\Dashboard\Map\ResourceGrouper;
use Tollbooth\Dashboard\Map\Thresholds;
use Tollbooth\Dashboard\Traffic\Range;
use Tollbooth\Dashboard\Traffic\TrafficReader;
use Tollbooth\Dashboard\Traffic\Window;

/**
 * @internal
 */
final readonly class EndpointList
{
    public const array SORTS = ['calls', 'errors', 'p95', 'throttled', 'path'];
    public const array FILTERS = ['all', 'failing', 'slow', 'throttled', 'deprecated', 'idle'];

    public function __construct(
        private GroupCatalog $groups,
        private Thresholds $thresholds,
        private ?TrafficReader $traffic = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function hasTraffic(): bool
    {
        return null !== $this->traffic;
    }

    /**
     * @return list<array{resource: string, endpoint: MapEndpoint}>
     */
    public function rows(string $group, Range $range, string $filter = 'all', string $sort = 'path', bool $descending = false): array
    {
        $now = ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
        $stats = $this->traffic?->byEndpoint(Window::ending($now, $range), $group) ?? [];
        $strip = $this->groups->localPrefixes($group);

        $rows = [];
        foreach (ResourceGrouper::group($this->groups->endpoints($group), $strip) as $resource => $members) {
            foreach ($members as $metadata) {
                $calls = $stats[$metadata->routeName()] ?? null;
                $endpoint = new MapEndpoint(
                    $metadata,
                    ResourceGrouper::localPath($metadata->path, $strip),
                    $calls,
                    0.0,
                    $this->thresholds->failing($calls),
                    $this->thresholds->throttling($calls),
                    $this->thresholds->slow($calls),
                );
                if (self::keeps($endpoint, $filter, null !== $this->traffic)) {
                    $rows[] = ['resource' => ResourceGrouper::title((string) $resource), 'endpoint' => $endpoint];
                }
            }
        }

        usort($rows, static function (array $a, array $b) use ($sort, $descending): int {
            $order = self::value($a['endpoint'], $sort) <=> self::value($b['endpoint'], $sort);
            $order = $descending ? -$order : $order;

            return 0 !== $order ? $order : [$a['endpoint']->path, $a['endpoint']->metadata->method] <=> [$b['endpoint']->path, $b['endpoint']->metadata->method];
        });

        return $rows;
    }

    private static function keeps(MapEndpoint $endpoint, string $filter, bool $traffic): bool
    {
        return match ($filter) {
            'failing' => $endpoint->failing,
            'slow' => $endpoint->slow,
            'throttled' => ($endpoint->stats->throttled ?? 0) > 0,
            'deprecated' => $endpoint->deprecated(),
            'idle' => $traffic && 0 === $endpoint->calls(),
            default => true,
        };
    }

    private static function value(MapEndpoint $endpoint, string $sort): string|int|float
    {
        return match ($sort) {
            'calls' => $endpoint->calls(),
            'errors' => $endpoint->stats?->errorRate() ?? -1.0,
            'p95' => $endpoint->p95() ?? -1.0,
            'throttled' => $endpoint->stats->throttled ?? 0,
            default => $endpoint->path.' '.$endpoint->metadata->method,
        };
    }
}
