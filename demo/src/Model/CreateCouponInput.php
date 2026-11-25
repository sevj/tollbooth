<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateCouponInput
{
    public function __construct(
        #[Assert\Length(min: 6, max: 12)]
        public string $code = '',
        #[Assert\Range(min: 1, max: 90)]
        public int $percent = 10,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
