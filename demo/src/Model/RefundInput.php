<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RefundInput
{
    public function __construct(
        #[Assert\Positive]
        public ?int $amount = null,
        #[Assert\Length(max: 200)]
        public ?string $reason = null,
    ) {
    }
}
