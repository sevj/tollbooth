<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit\Health;

use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\HealthCheckInterface;
use Tollbooth\RateLimit\Doctrine\DoctrinePlanStore;

/**
 * @internal
 */
final readonly class PlanStoreCheck implements HealthCheckInterface
{
    public function __construct(
        private DoctrinePlanStore $store,
    ) {
    }

    public function name(): string
    {
        return 'plan_storage';
    }

    public function check(): CheckResult
    {
        try {
            $count = \count($this->store->all());
        } catch (\Throwable) {
            return CheckResult::critical('The table of rate limit plans cannot be read: only the plans declared in the configuration apply. Run tollbooth:plan:setup, or check the connection.');
        }

        try {
            $pending = $this->store->setupSql();
        } catch (\Throwable) {
            $pending = [];
        }

        if ([] !== $pending) {
            return CheckResult::warning('The table of rate limit plans is out of date. Run tollbooth:plan:setup.');
        }

        return CheckResult::ok(\sprintf('%d stored plan%s.', $count, 1 === $count ? '' : 's'));
    }
}
