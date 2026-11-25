<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

/**
 * @internal
 */
interface ConsumerActivityInterface
{
    /**
     * @param list<string> $consumers
     *
     * @return array<string, CallRecord> the latest recorded call of each consumer that has one
     */
    public function lastCalls(array $consumers): array;

    /**
     * @return array<string, int> the most calls each consumer made in a single minute of [$from, $to)
     */
    public function minutePeaks(int $from, int $to): array;
}
