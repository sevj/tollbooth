<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class OrderLineView
{
    public function __construct(
        public string $productId,
        public int $quantity,
        public Money $unitPrice,
    ) {
    }
}
