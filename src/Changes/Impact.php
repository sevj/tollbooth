<?php

declare(strict_types=1);

namespace Tollbooth\Changes;

use Psr\Clock\ClockInterface;
use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallReaderInterface;

/**
 * @internal
 */
final readonly class Impact
{
    public function __construct(
        private ?CallReaderInterface $calls = null,
        private ?ClockInterface $clock = null,
        private int $days = 30,
    ) {
    }

    public function available(): bool
    {
        return null !== $this->calls;
    }

    /**
     * @param list<string>          $operations
     * @param array<string, string> $operationIds
     *
     * @return array{calls: int, consumers: list<string>, consumer_ids: list<string>}|null
     */
    public function of(array $operations, array $operationIds): ?array
    {
        if (null === $this->calls) {
            return null;
        }

        $now = ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
        $calls = 0;
        $consumers = [];
        foreach ($operations as $operation) {
            $endpoint = $operationIds[$operation] ?? null;
            if (null === $endpoint) {
                continue;
            }

            try {
                $totals = $this->calls->totals($now - $this->days * 86400, $now + 60, 'consumer', new CallQuery(endpoint: $endpoint));
            } catch (\Throwable) {
                return null;
            }
            foreach ($totals as $stats) {
                $calls += $stats->calls;
                $consumers[$stats->key] = $stats->label;
            }
        }

        return ['calls' => $calls, 'consumers' => array_values($consumers), 'consumer_ids' => array_map('strval', array_keys($consumers))];
    }

    public function days(): int
    {
        return $this->days;
    }
}
