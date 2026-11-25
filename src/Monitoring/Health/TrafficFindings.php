<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring\Health;

use Psr\Clock\ClockInterface;
use Tollbooth\Core\Health\Finding;
use Tollbooth\Core\Health\FindingProviderInterface;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Monitoring\CallReaderInterface;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class TrafficFindings implements FindingProviderInterface
{
    public function __construct(
        private CallReaderInterface $calls,
        private EndpointRegistry $registry,
        private int $unusedDays = 30,
        private float $errorRate = 0.05,
        private int $minimumCalls = 20,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function findings(): iterable
    {
        $now = ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();

        foreach ($this->calls->totals($now - 3600, $now + 60, 'endpoint') as $stats) {
            if ($stats->calls >= $this->minimumCalls && $stats->errorRate() > $this->errorRate) {
                yield new Finding(\sprintf('%s answered %.1f%% errors in the last hour (%d of %d calls).', $stats->key, $stats->errorRate() * 100, $stats->clientErrors + $stats->serverErrors, $stats->calls), true);
            }
        }

        $called = array_map(static fn (Stats $stats): string => $stats->key, $this->calls->totals($now - $this->unusedDays * 86400, $now + 60, 'endpoint'));
        $unused = array_values(array_diff(
            array_map(static fn (EndpointMetadata $endpoint): string => $endpoint->routeName(), $this->registry->all()),
            $called,
        ));
        if ([] !== $unused) {
            $shown = \array_slice($unused, 0, 5);
            yield new Finding(\sprintf(
                '%d endpoint%s received no call in %d days: %s%s.',
                \count($unused),
                1 === \count($unused) ? '' : 's',
                $this->unusedDays,
                implode(', ', $shown),
                \count($unused) > \count($shown) ? '…' : '',
            ));
        }
    }
}
