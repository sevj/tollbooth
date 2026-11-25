<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\Core\Security\AuthMethod;

/**
 * @internal
 */
final readonly class PlanRegistry
{
    public function __construct(
        private Plans $plans,
    ) {
    }

    /**
     * @return list<Limit>
     */
    public function limitsFor(Consumer $consumer): array
    {
        $limits = $this->plans->limits();
        $default = $this->plans->defaultPlan;
        $plan = AuthMethod::Anonymous === $consumer->method ? ($this->plans->anonymousPlan ?? $default) : $consumer->plan;

        return $limits[$plan ?? ''] ?? $limits[$default ?? ''] ?? [];
    }
}
