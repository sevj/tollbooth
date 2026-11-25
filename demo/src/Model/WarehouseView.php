<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class WarehouseView
{
    public function __construct(
        public string $id,
        public string $city,
        public int $capacity,
    ) {
    }
}
