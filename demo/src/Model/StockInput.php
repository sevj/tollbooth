<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StockInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public int $stock = 0,
    ) {
    }
}
