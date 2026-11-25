<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

interface CallReaderInterface
{
    /**
     * @return list<CallRecord>
     */
    public function recent(CallQuery $query): array;

    /**
     * @return array<int, Stats>
     */
    public function series(int $from, int $to, ?CallQuery $filter = null): array;

    /**
     * @param 'endpoint'|'consumer' $by
     *
     * @return list<Stats>
     */
    public function totals(int $from, int $to, string $by, ?CallQuery $filter = null): array;
}
