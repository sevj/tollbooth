<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

/**
 * @internal
 */
interface ActiveConsumersInterface
{
    /**
     * @return list<int> the number of distinct consumers in each of the $buckets equal parts of [$from, $to)
     */
    public function activeConsumers(int $from, int $to, int $buckets, ?CallQuery $filter = null): array;
}
