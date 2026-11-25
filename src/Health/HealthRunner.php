<?php

declare(strict_types=1);

namespace Tollbooth\Health;

use Psr\Clock\ClockInterface;
use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\Finding;
use Tollbooth\Core\Health\FindingProviderInterface;
use Tollbooth\Core\Health\HealthCheckInterface;
use Tollbooth\Core\Health\HealthStatus;

final readonly class HealthRunner
{
    public const string SOURCE_TOLLBOOTH = 'tollbooth';
    public const string SOURCE_APPLICATION = 'application';

    /**
     * @param iterable<HealthCheckInterface>     $tollboothChecks
     * @param iterable<HealthCheckInterface>     $applicationChecks
     * @param iterable<FindingProviderInterface> $findingProviders
     * @param array<string, string>              $facts
     */
    public function __construct(
        private iterable $tollboothChecks = [],
        private iterable $applicationChecks = [],
        private iterable $findingProviders = [],
        private array $facts = [],
        private ?ClockInterface $clock = null,
    ) {
    }

    public function run(bool $withFindings = true): HealthReport
    {
        $checks = [];
        $status = HealthStatus::Ok;
        foreach ([self::SOURCE_TOLLBOOTH => $this->tollboothChecks, self::SOURCE_APPLICATION => $this->applicationChecks] as $source => $group) {
            foreach ($group as $check) {
                $outcome = $this->runCheck($check, $source);
                $checks[] = $outcome;
                $status = $status->worst($outcome->status);
            }
        }

        return new HealthReport(
            $status,
            $this->clock?->now() ?? new \DateTimeImmutable(),
            $checks,
            $withFindings ? $this->findings() : [],
            $this->facts,
        );
    }

    private function runCheck(HealthCheckInterface $check, string $source): CheckOutcome
    {
        $start = microtime(true);
        try {
            $result = $check->check();
        } catch (\Throwable $exception) {
            $result = CheckResult::critical(\sprintf('The check failed with %s.', $exception::class));
        }

        return new CheckOutcome($check->name(), $source, $result->status, $result->summary, round((microtime(true) - $start) * 1000, 2));
    }

    /**
     * @return list<Finding>
     */
    private function findings(): array
    {
        $findings = [];
        foreach ($this->findingProviders as $provider) {
            try {
                foreach ($provider->findings() as $finding) {
                    $findings[] = $finding;
                }
            } catch (\Throwable $exception) {
                $findings[] = new Finding(\sprintf('Some findings could not be computed: %s.', $exception::class), true);
            }
        }

        usort($findings, static fn (Finding $a, Finding $b): int => $b->warning <=> $a->warning);

        return $findings;
    }
}
