<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StockMovementInput
{
    public function __construct(
        #[Assert\NotBlank]
        public string $warehouseId = '',
        #[Assert\NotBlank]
        public string $productId = '',
        #[Assert\NotEqualTo(0)]
        public int $delta = 0,
    ) {
    }
}
