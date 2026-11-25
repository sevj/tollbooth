<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring\Health;

use Psr\Clock\ClockInterface;
use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\HealthCheckInterface;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;

/**
 * @internal
 */
final readonly class MonitoringCheck implements HealthCheckInterface
{
    public function __construct(
        private DoctrineCallStorage $storage,
        private int $callsRetentionDays = 7,
        private int $maxLagMinutes = 10,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function name(): string
    {
        return 'monitoring';
    }

    public function check(): CheckResult
    {
        try {
            $state = $this->storage->maintenanceState();
        } catch (\Throwable) {
            return CheckResult::critical('The monitoring tables cannot be read. Run tollbooth:monitoring:setup, or check the connection.');
        }

        $now = ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
        if (null === $state['oldest_call']) {
            return CheckResult::ok('Tables ready, no call recorded yet.');
        }

        $aggregatedUntil = $state['rolled_until'] ?? $state['oldest_call'];
        $lag = intdiv(max(0, $now - 120 - $aggregatedUntil), 60);
        if ($lag > $this->maxLagMinutes) {
            return CheckResult::warning(\sprintf('Aggregation is %d minutes behind. Check auto_maintenance, or schedule tollbooth:monitoring:maintain.', $lag));
        }

        $overdue = intdiv($now - $state['oldest_call'], 86400) - $this->callsRetentionDays;
        if ($overdue >= 1) {
            return CheckResult::warning(\sprintf('Calls older than the %d days of retention are still stored: the purge has not run for %d day%s.', $this->callsRetentionDays, $overdue, 1 === $overdue ? '' : 's'));
        }

        return CheckResult::ok(null === $state['rolled_until']
            ? 'Recording calls, nothing to aggregate yet.'
            : \sprintf('Recording calls, aggregated up to %s UTC.', gmdate('Y-m-d H:i', $state['rolled_until'])));
    }
}
