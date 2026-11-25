<?php

declare(strict_types=1);

namespace Tollbooth\Core\RateLimit;

/**
 * @internal
 */
interface PlanCatalogInterface
{
    /**
     * @return list<string>
     */
    public function names(): array;
}
