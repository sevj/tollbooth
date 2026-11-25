<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class StockMovementView
{
    public function __construct(
        public string $id,
        public string $warehouseId,
        public string $productId,
        public int $delta,
        public \DateTimeImmutable $at,
    ) {
    }
}
