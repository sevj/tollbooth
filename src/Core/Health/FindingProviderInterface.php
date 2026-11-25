<?php

declare(strict_types=1);

namespace Tollbooth\Core\Health;

interface FindingProviderInterface
{
    public const string TAG = 'tollbooth.finding_provider';

    /**
     * @return iterable<Finding>
     */
    public function findings(): iterable;
}
