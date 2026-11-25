<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Consumers;

use Psr\Clock\ClockInterface;
use Tollbooth\Changes\ChangeKind;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Dashboard\Changes\ContractChanges;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Map\ResourceGrouper;
use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallReaderInterface;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class ConsumerInsights
{
    private const int TOP_CONSUMERS = 4;
    private const int TOP_RESOURCES = 5;
    private const int CHANGE_DAYS = 30;

    public function __construct(
        private ConsumerDirectory $directory,
        private CallReaderInterface $calls,
        private GroupCatalog $groups,
        private ?ContractChanges $changes = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function flow(string $group, string $others): FlowDiagram
    {
        $now = $this->now();
        $resources = $this->resources($group);
        $consumers = array_values(array_filter(
            $this->calls->totals($now - 86400, $now + 60, 'consumer', new CallQuery(group: $group)),
            static fn (Stats $stats): bool => !str_starts_with($stats->key, 'ip:'),
        ));
        $top = \array_slice($consumers, 0, self::TOP_CONSUMERS);

        $calls = [];
        $ids = [];
        $remaining = [];
        foreach ($this->calls->totals($now - 86400, $now + 60, 'endpoint', new CallQuery(group: $group)) as $stats) {
            $remaining[$stats->key] = $stats->calls;
        }
        foreach ($top as $consumer) {
            $ids[$consumer->label] = $consumer->key;
            foreach ($this->calls->totals($now - 86400, $now + 60, 'endpoint', new CallQuery(group: $group, consumerId: $consumer->key)) as $stats) {
                $resource = $resources[$stats->key] ?? '/';
                $calls[$consumer->label][$resource] = ($calls[$consumer->label][$resource] ?? 0) + $stats->calls;
                $remaining[$stats->key] = ($remaining[$stats->key] ?? 0) - $stats->calls;
            }
        }
        $rest = [];
        foreach ($remaining as $endpoint => $count) {
            if ($count > 0) {
                $resource = $resources[$endpoint] ?? '/';
                $rest[$resource] = ($rest[$resource] ?? 0) + $count;
            }
        }
        if ([] !== $rest) {
            $calls[$others] = $rest;
        }

        $totals = [];
        foreach ($calls as $targets) {
            foreach ($targets as $resource => $count) {
                $totals[$resource] = ($totals[$resource] ?? 0) + $count;
            }
        }
        arsort($totals);
        $kept = \array_slice(array_keys($totals), 0, self::TOP_RESOURCES);
        foreach ($calls as $source => $targets) {
            $folded = [];
            foreach ($targets as $resource => $count) {
                $name = \in_array($resource, $kept, true) ? ResourceGrouper::title((string) $resource) : $others;
                $folded[$name] = ($folded[$name] ?? 0) + $count;
            }
            $calls[$source] = $folded;
        }

        return FlowDiagram::build($calls, $ids);
    }

    public function page(string $group, string $id): ?ConsumerPage
    {
        $consumer = $this->directory->find($group, $id);
        if (null === $consumer) {
            return null;
        }

        $now = $this->now();
        $filter = new CallQuery(consumerId: $id);
        $endpoints = $this->endpoints();
        $used = array_map(
            static fn (Stats $stats): array => ['endpoint' => $endpoints[$stats->key] ?? null, 'stats' => $stats],
            $this->calls->totals($now - 86400, $now + 60, 'endpoint', $filter),
        );

        $dayStart = ConsumerDirectory::startOfDay($now);
        $series = $this->calls->series($dayStart, $now + 60, $filter);
        $cumulative = [];
        $running = 0;
        $exhaustedAt = null;
        $cap = $consumer->dayLimit;
        $quarters = intdiv($now - $dayStart, 900) + 1;
        $byMinute = [];
        foreach ($series as $minute => $stats) {
            $byMinute[$minute] = $stats;
        }
        for ($quarter = 0; $quarter < $quarters; ++$quarter) {
            for ($minute = 0; $minute < 15; ++$minute) {
                $at = $dayStart + $quarter * 900 + $minute * 60;
                $running += ($byMinute[$at] ?? null)->calls ?? 0;
                if (null !== $cap && null === $exhaustedAt && $running >= $cap) {
                    $exhaustedAt = $at;
                }
            }
            $cumulative[] = $running;
        }

        $lastHour = 0;
        $minutes = [];
        $start = intdiv($now, 60) * 60 - 59 * 60;
        $hour = $start < $dayStart ? $this->calls->series($start, $now + 60, $filter) : $byMinute;
        for ($at = $start; $at <= $now; $at += 60) {
            $stats = $hour[$at] ?? null;
            $minutes[] = ['calls' => $stats->calls ?? 0, 'throttled' => $stats->throttled ?? 0];
            $lastHour += $stats->calls ?? 0;
        }
        if (null !== $cap && null === $exhaustedAt && $lastHour > 0) {
            $projected = $now + (int) (($cap - $running) / $lastHour * 3600);
            $exhaustedAt = $projected < $dayStart + 86400 ? $projected : null;
        }

        return new ConsumerPage($consumer, $used, $cumulative, $exhaustedAt, $minutes, $this->upcoming($id, $endpoints), $now);
    }

    /**
     * @param array<string, EndpointMetadata> $endpoints
     *
     * @return list<array{kind: string, element: string, message: ?string, sunset: ?string, calls: int}>
     */
    private function upcoming(string $id, array $endpoints): array
    {
        $now = $this->now();
        $called = [];
        foreach ($this->calls->totals($now - self::CHANGE_DAYS * 86400, $now + 60, 'endpoint', new CallQuery(consumerId: $id)) as $stats) {
            $called[$stats->key] = $stats->calls;
        }

        $items = [];
        $covered = [];
        $comparison = $this->changes?->compare();
        foreach ($comparison['report']->changes ?? [] as $change) {
            if (!\in_array($change->kind, [ChangeKind::Breaking, ChangeKind::Risky], true)) {
                continue;
            }
            $calls = 0;
            foreach ($change->operations as $operation) {
                $route = $comparison['ids'][$operation] ?? null;
                if (null !== $route && isset($called[$route])) {
                    $calls += $called[$route];
                    $covered[$route] = true;
                }
            }
            if ($calls > 0) {
                $items[] = ['kind' => $change->kind->value, 'element' => $change->element, 'message' => $change->message, 'sunset' => null, 'calls' => $calls];
            }
        }

        foreach ($called as $route => $calls) {
            $endpoint = $endpoints[$route] ?? null;
            if (null !== $endpoint && null !== $endpoint->deprecation && !isset($covered[$route])) {
                $sunset = $endpoint->deprecation['sunset'];
                $items[] = [
                    'kind' => 'deprecated',
                    'element' => $endpoint->method.' '.$endpoint->path,
                    'message' => null,
                    'sunset' => null === $sunset ? null : gmdate('Y-m-d', $sunset),
                    'calls' => $calls,
                ];
            }
        }

        return $items;
    }

    /**
     * @return array<string, EndpointMetadata>
     */
    private function endpoints(): array
    {
        $endpoints = [];
        foreach ($this->groups->names() as $group) {
            foreach ($this->groups->endpoints($group) as $endpoint) {
                $endpoints[$endpoint->routeName()] = $endpoint;
            }
        }

        return $endpoints;
    }

    /**
     * @return array<string, string>
     */
    private function resources(string $group): array
    {
        $prefixes = $this->groups->localPrefixes($group);
        $resources = [];
        foreach ($this->groups->endpoints($group) as $endpoint) {
            $resources[$endpoint->routeName()] = ResourceGrouper::resourceOf(ResourceGrouper::localPath($endpoint->path, $prefixes));
        }

        return $resources;
    }

    private function now(): int
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
    }
}
