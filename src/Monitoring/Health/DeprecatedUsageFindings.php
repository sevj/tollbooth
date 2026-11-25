<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring\Health;

use Psr\Clock\ClockInterface;
use Tollbooth\Core\Health\Finding;
use Tollbooth\Core\Health\FindingProviderInterface;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallReaderInterface;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class DeprecatedUsageFindings implements FindingProviderInterface
{
    public function __construct(
        private CallReaderInterface $calls,
        private EndpointRegistry $registry,
        private int $days = 7,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function findings(): iterable
    {
        $now = ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
        foreach ($this->registry->all() as $endpoint) {
            if (null === $endpoint->deprecation) {
                continue;
            }

            $consumers = $this->calls->totals($now - $this->days * 86400, $now + 60, 'consumer', new CallQuery(endpoint: $endpoint->routeName()));
            if ([] === $consumers) {
                continue;
            }

            $calls = array_sum(array_map(static fn (Stats $stats): int => $stats->calls, $consumers));
            $names = array_map(static fn (Stats $stats): string => $stats->label, \array_slice($consumers, 0, 3));
            $sunset = $endpoint->deprecation['sunset'];
            $soon = null !== $sunset && $sunset - $now < 30 * 86400;

            yield new Finding(\sprintf(
                '%s %s is deprecated%s and was called %d time%s in %d days by %s%s.',
                $endpoint->method,
                $endpoint->path,
                null === $sunset ? '' : \sprintf(' (sunset %s)', gmdate('Y-m-d', $sunset)),
                $calls,
                1 === $calls ? '' : 's',
                $this->days,
                implode(', ', $names),
                \count($consumers) > \count($names) ? \sprintf(' and %d more', \count($consumers) - \count($names)) : '',
            ), $soon);
        }
    }
}
