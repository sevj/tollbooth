<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class ShippingRateView
{
    public function __construct(
        public string $carrier,
        public Money $price,
        public int $days,
        public bool $express,
    ) {
    }
}
