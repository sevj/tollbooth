<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit\Health;

use Symfony\Contracts\Cache\CacheInterface;
use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\HealthCheckInterface;
use Tollbooth\Core\Health\SchemaDrift;
use Tollbooth\RateLimit\Doctrine\DoctrinePlanStore;

/**
 * @internal
 */
final readonly class PlanStoreCheck implements HealthCheckInterface
{
    public function __construct(
        private DoctrinePlanStore $store,
        private bool $declared = false,
        private ?CacheInterface $cache = null,
    ) {
    }

    public function name(): string
    {
        return 'plan_storage';
    }

    public function check(): CheckResult
    {
        try {
            $set = $this->store->read();
        } catch (\Throwable) {
            return CheckResult::critical('The table of rate limit plans cannot be read: only the plans declared in the configuration apply. Run tollbooth:plan:setup, or check the connection.');
        }

        if (SchemaDrift::pending($this->name(), $this->store->setupSql(...), $this->cache)) {
            return CheckResult::warning('The table of rate limit plans is out of date. Run tollbooth:plan:setup.');
        }

        $count = \count($set['plans']);
        if (0 === $count) {
            return CheckResult::warning($this->declared
                ? 'The table of rate limit plans is empty, so calls are not limited. Run tollbooth:plan:setup to copy the declared plans into it.'
                : 'No rate limit plan exists yet, so calls are not limited. Create plans from the dashboard.');
        }

        return CheckResult::ok(\sprintf('%d plan%s, default %s.', $count, 1 === $count ? '' : 's', $set['default'] ?? 'none'));
    }
}
