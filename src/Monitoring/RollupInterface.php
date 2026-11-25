<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

interface RollupInterface
{
    public function rollup(int $now): int;

    /**
     * @return array{calls: int, minutes: int}
     */
    public function purge(int $callsBefore, int $minutesBefore): array;
}
