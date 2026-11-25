<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Traffic;

use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallReaderInterface;

/**
 * @internal
 */
final readonly class NoCalls implements CallReaderInterface
{
    public function recent(CallQuery $query): array
    {
        return [];
    }

    public function series(int $from, int $to, ?CallQuery $filter = null): array
    {
        return [];
    }

    public function totals(int $from, int $to, string $by, ?CallQuery $filter = null): array
    {
        return [];
    }
}
