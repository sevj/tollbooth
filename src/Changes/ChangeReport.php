<?php

declare(strict_types=1);

namespace Tollbooth\Changes;

final readonly class ChangeReport
{
    /**
     * @param list<Change> $changes
     */
    public function __construct(
        public array $changes,
    ) {
    }

    /**
     * @return list<Change>
     */
    public function of(ChangeKind $kind): array
    {
        return array_values(array_filter($this->changes, static fn (Change $change): bool => $change->kind === $kind));
    }

    public function count(ChangeKind $kind): int
    {
        return \count($this->of($kind));
    }

    public function isEmpty(): bool
    {
        return [] === $this->changes;
    }

    public function reaches(ChangeKind $threshold): bool
    {
        foreach ($this->changes as $change) {
            if ($change->kind->rank() <= $threshold->rank()) {
                return true;
            }
        }

        return false;
    }

    public function nextVersion(string $base): ?string
    {
        if (1 !== preg_match('/^v?(\d+)\.(\d+)\.(\d+)/', $base, $matches)) {
            return null;
        }

        [$major, $minor, $patch] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        if ($this->count(ChangeKind::Breaking) > 0) {
            return 0 === $major ? \sprintf('0.%d.0', $minor + 1) : \sprintf('%d.0.0', $major + 1);
        }

        if ($this->count(ChangeKind::Added) + $this->count(ChangeKind::Risky) + $this->count(ChangeKind::Deprecated) > 0) {
            return \sprintf('%d.%d.0', $major, $minor + 1);
        }

        return $this->isEmpty() ? $base : \sprintf('%d.%d.%d', $major, $minor, $patch + 1);
    }
}
