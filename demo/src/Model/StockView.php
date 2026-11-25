<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class StockView
{
    public function __construct(
        public string $productId,
        public int $stock,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
