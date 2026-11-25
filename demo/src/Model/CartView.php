<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class CartView
{
    /**
     * @param list<CartLineView> $lines
     */
    public function __construct(
        public string $id,
        public array $lines,
        public Money $total,
    ) {
    }
}
