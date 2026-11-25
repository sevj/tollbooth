<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Changes;

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
    ) {
    }

    /**
     * @return list<string>
     */
    public function versions(): array
    {
        return $this->store->versions();
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
