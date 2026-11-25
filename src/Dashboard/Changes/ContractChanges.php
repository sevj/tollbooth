<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Changes;

use Symfony\Contracts\Cache\CacheInterface;
use Tollbooth\Changes\Change;
use Tollbooth\Changes\ChangeKind;
use Tollbooth\Changes\ChangeReport;
use Tollbooth\Changes\ContractDiffer;
use Tollbooth\Changes\CurrentContract;
use Tollbooth\Changes\SnapshotStore;

/**
 * @internal
 */
final class ContractChanges
{
    /**
     * @var array<string, array{base: string, target: string, report: ChangeReport, ids: array<string, string>}|null>
     */
    private array $reports = [];

    public function __construct(
        private readonly CurrentContract $contract,
        private readonly SnapshotStore $store,
        private readonly ?CacheInterface $cache = null,
        private readonly string $contractHash = '',
    ) {
    }

    public function breaking(string $group): int
    {
        $count = function () use ($group): int {
            $changes = $this->compare()['report']->changes ?? [];

            return \count(array_filter($changes, static fn (Change $change): bool => $change->group === $group && ChangeKind::Breaking === $change->kind));
        };
        if (null === $this->cache) {
            return $count();
        }

        $value = $this->cache->get('tollbooth_dashboard_breaking_'.hash('xxh128', $group.'|'.$this->contractHash.'|'.implode(',', $this->store->versions())), $count);

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    public function versions(): array
    {
        return $this->store->versions();
    }

    /**
     * @return list<array{version: string, createdAt: string, breaking: ?int, changes: ?int}>
     */
    public function releases(?string $group = null, int $limit = 8): array
    {
        $versions = \array_slice($this->store->versions(), 0, $limit + 1);
        $releases = [];
        foreach (\array_slice($versions, 0, $limit) as $index => $version) {
            try {
                $snapshot = $this->store->load($version);
            } catch (\RuntimeException) {
                continue;
            }
            $previous = $versions[$index + 1] ?? null;
            $comparison = null === $previous ? null : $this->compare($previous, $version);
            $changes = null === $comparison ? null : array_values(array_filter($comparison['report']->changes, static fn (Change $change): bool => null === $group || $change->group === $group));
            $releases[] = [
                'version' => $version,
                'createdAt' => $snapshot['created_at'],
                'breaking' => null === $changes ? null : \count(array_filter($changes, static fn (Change $change): bool => ChangeKind::Breaking === $change->kind)),
                'changes' => null === $changes ? null : \count($changes),
            ];
        }

        return $releases;
    }

    /**
     * @return array{base: string, target: string, report: ChangeReport, ids: array<string, string>}|null
     */
    public function compare(?string $base = null, ?string $target = null): ?array
    {
        $base ??= $this->store->latest();
        if (null === $base) {
            return null;
        }

        $key = $base.'|'.($target ?? '');
        if (!\array_key_exists($key, $this->reports)) {
            try {
                $before = $this->store->load($base);
                $after = null === $target ? ['version' => 'current', 'groups' => $this->contract->documents()] : $this->store->load($target);
                $this->reports[$key] = [
                    'base' => (string) $before['version'],
                    'target' => null === $target ? 'current' : (string) $after['version'],
                    'report' => (new ContractDiffer())->diff($before['groups'], $after['groups']),
                    'ids' => ContractDiffer::operationIds($before['groups'], $after['groups']),
                ];
            } catch (\RuntimeException) {
                $this->reports[$key] = null;
            }
        }

        return $this->reports[$key];
    }
}
