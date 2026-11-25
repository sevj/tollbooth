<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class CategoryView
{
    public function __construct(
        public string $id,
        public Category $slug,
        public string $name,
        public int $products,
    ) {
    }
}
