<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class OrderV1
{
    public function __construct(
        public int $order_id,
        public string $state,
        public int $total_cents,
    ) {
    }
}
