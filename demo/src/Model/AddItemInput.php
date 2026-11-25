<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddItemInput
{
    public function __construct(
        #[Assert\NotBlank]
        public string $productId = '',
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(99)]
        public int $quantity = 1,
    ) {
    }
}
