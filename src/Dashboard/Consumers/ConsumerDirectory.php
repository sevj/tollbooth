<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Consumers;

use Psr\Clock\ClockInterface;
use Tollbooth\Auth\ApiKey\ApiKey;
use Tollbooth\Auth\ApiKey\ApiKeyProviderInterface;
use Tollbooth\Auth\ApiKey\ListableApiKeyProviderInterface;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\Dashboard\Traffic\TrafficReader;
use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallReaderInterface;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 *
 * @phpstan-import-type LimitArray from Limit
 */
final class ConsumerDirectory
{
    /**
     * @var array<string, ApiKey>|null
     */
    private ?array $keys = null;

    /**
     * @param array<string, list<LimitArray>> $plans
     */
    public function __construct(
        private readonly CallReaderInterface $calls,
        private readonly ?ApiKeyProviderInterface $provider = null,
        private readonly array $plans = [],
        private readonly ?string $defaultPlan = null,
        private readonly ?string $anonymousPlan = null,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    /**
     * @return list<array{name: string, limits: list<LimitArray>, keys: int, default: bool}>
     */
    public function plans(): array
    {
        $counts = [];
        foreach ($this->keys() as $key) {
            $plan = $key->plan ?? $this->defaultPlan;
            if (null !== $plan) {
                $counts[$plan] = ($counts[$plan] ?? 0) + 1;
            }
        }

        $plans = [];
        foreach ($this->plans as $name => $limits) {
            $plans[] = ['name' => $name, 'limits' => $limits, 'keys' => $counts[$name] ?? 0, 'default' => $name === $this->defaultPlan];
        }

        return $plans;
    }

    public function keyCount(): ?int
    {
        return $this->provider instanceof ListableApiKeyProviderInterface ? \count($this->keys()) : null;
    }

    /**
     * @return list<ConsumerRow>
     */
    public function rows(string $group): array
    {
        $now = $this->now();
        $traffic = self::keyed($this->calls->totals($now - 86400, $now + 60, 'consumer', new CallQuery(group: $group)));
        $today = self::keyed($this->calls->totals(self::startOfDay($now), $now + 60, 'consumer'));

        $rows = [];
        foreach ($this->keys() as $key) {
            $rows[$key->id] = $this->row($key->id, $key->name, $key, $traffic[$key->id] ?? null, $today);
        }

        $anonymous = [];
        foreach ($traffic as $id => $stats) {
            if (isset($rows[$id])) {
                continue;
            }
            if (str_starts_with($id, 'ip:')) {
                $anonymous[] = $stats;
                continue;
            }
            $rows[$id] = $this->row($id, $stats->label, null, $stats, $today);
        }

        if ([] !== $anonymous) {
            $plan = $this->anonymousPlan ?? $this->defaultPlan;
            $rows[ConsumerRow::ANONYMOUS] = new ConsumerRow(
                ConsumerRow::ANONYMOUS,
                'anonymous',
                null,
                $plan,
                TrafficReader::sum($anonymous, ConsumerRow::ANONYMOUS),
                members: \count($anonymous),
            );
        }

        $rows = array_values($rows);
        usort($rows, static fn (ConsumerRow $a, ConsumerRow $b): int => [$b->calls(), $a->name] <=> [$a->calls(), $b->name]);

        return $rows;
    }

    public function find(string $group, string $id): ?ConsumerRow
    {
        $now = $this->now();
        $key = $this->keys()[$id] ?? null;
        $traffic = self::keyed($this->calls->totals($now - 86400, $now + 60, 'consumer', new CallQuery(group: $group, consumerId: $id)));
        $name = $key->name ?? $traffic[$id]->label ?? null;
        if (null === $name) {
            $name = ($this->calls->recent(new CallQuery(consumerId: $id, since: ($now - 30 * 86400) * 1000, limit: 1))[0] ?? null)?->consumerName;
        }
        if (null === $name) {
            return null;
        }

        $today = self::keyed($this->calls->totals(self::startOfDay($now), $now + 60, 'consumer', new CallQuery(consumerId: $id)));

        return $this->row($id, $name, $key, $traffic[$id] ?? null, $today);
    }

    /**
     * @return list<LimitArray>
     */
    public function limitsOf(?string $plan): array
    {
        return $this->plans[$plan ?? ''] ?? $this->plans[$this->defaultPlan ?? ''] ?? [];
    }

    public static function startOfDay(int $now): int
    {
        return intdiv($now, 86400) * 86400;
    }

    /**
     * @param list<LimitArray> $limits
     */
    public static function limit(array $limits, int $window): ?int
    {
        foreach ($limits as $limit) {
            if ($window === $limit['window']) {
                return $limit['limit'];
            }
        }

        return null;
    }

    /**
     * @param array<string, Stats> $today
     */
    private function row(string $id, string $name, ?ApiKey $key, ?Stats $stats, array $today): ConsumerRow
    {
        $now = $this->now();
        $plan = null !== $key ? ($key->plan ?? $this->defaultPlan) : null;
        $limits = null !== $key ? $this->limitsOf($plan) : [];
        $last = $this->calls->recent(new CallQuery(consumerId: $id, limit: 1))[0] ?? null;
        $peak = null;
        if (null !== $last && $last->occurredAt >= ($now - 86400) * 1000) {
            $peak = 0;
            foreach ($this->calls->series($now - 86400, $now + 60, new CallQuery(consumerId: $id)) as $minute) {
                $peak = max($peak, $minute->calls);
            }
        }

        return new ConsumerRow(
            $id,
            $name,
            $key,
            $plan,
            $stats,
            ($today[$id] ?? null)->calls ?? 0,
            self::limit($limits, 86400),
            self::limit($limits, 60),
            $peak,
            $last,
        );
    }

    /**
     * @return array<string, ApiKey>
     */
    private function keys(): array
    {
        if (null === $this->keys) {
            $this->keys = [];
            if ($this->provider instanceof ListableApiKeyProviderInterface) {
                foreach ($this->provider->all() as $key) {
                    $this->keys[$key->id] = $key;
                }
            }
        }

        return $this->keys;
    }

    /**
     * @param list<Stats> $stats
     *
     * @return array<string, Stats>
     */
    private static function keyed(array $stats): array
    {
        $keyed = [];
        foreach ($stats as $item) {
            $keyed[$item->key] = $item;
        }

        return $keyed;
    }

    private function now(): int
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
    }
}
