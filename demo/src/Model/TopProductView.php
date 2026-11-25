<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class TopProductView
{
    public function __construct(
        public string $productId,
        public string $name,
        public int $sold,
    ) {
    }
}
