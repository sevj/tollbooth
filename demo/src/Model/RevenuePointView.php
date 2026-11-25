<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class RevenuePointView
{
    public function __construct(
        public string $period,
        public Money $revenue,
        public int $orders,
    ) {
    }
}
