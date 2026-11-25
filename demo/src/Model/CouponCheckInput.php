<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CouponCheckInput
{
    public function __construct(
        #[Assert\Regex('/^[A-Z0-9]{6,12}$/')]
        public string $code = '',
        #[Assert\PositiveOrZero]
        public int $cartTotal = 0,
    ) {
    }
}
