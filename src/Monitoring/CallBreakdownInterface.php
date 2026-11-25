<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

interface CallBreakdownInterface
{
    /**
     * @return array<int, int>
     */
    public function statuses(int $from, int $to, ?CallQuery $filter = null): array;

    /**
     * @return list<ErrorGroup>
     */
    public function errors(int $from, int $to, ?CallQuery $filter = null, int $limit = 10): array;
}
