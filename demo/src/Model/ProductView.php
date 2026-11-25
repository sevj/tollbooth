<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class ProductView
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
        public Category $category,
        public Money $price,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
