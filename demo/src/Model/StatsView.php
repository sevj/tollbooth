<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class StatsView
{
    public function __construct(
        public int $ordersToday,
        public Money $revenueToday,
        public int $activeCarts,
        public int $customers,
    ) {
    }
}
