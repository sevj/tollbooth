<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\Core\Security\AuthMethod;

/**
 * @internal
 *
 * @phpstan-import-type LimitArray from Limit
 */
final class PlanRegistry
{
    /**
     * @var array<string, list<Limit>>|null
     */
    private ?array $limits = null;

    /**
     * @param array<string, list<LimitArray>> $plans
     */
    public function __construct(
        private readonly array $plans = [],
        private readonly ?string $defaultPlan = null,
        private readonly ?string $anonymousPlan = null,
    ) {
    }

    /**
     * @return list<Limit>
     */
    public function limitsFor(Consumer $consumer): array
    {
        $this->limits ??= array_map(
            static fn (array $limits): array => array_map(Limit::fromArray(...), $limits),
            $this->plans,
        );

        $plan = AuthMethod::Anonymous === $consumer->method ? ($this->anonymousPlan ?? $this->defaultPlan) : $consumer->plan;

        return $this->limits[$plan ?? ''] ?? $this->limits[$this->defaultPlan ?? ''] ?? [];
    }
}
