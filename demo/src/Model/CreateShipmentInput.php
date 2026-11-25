<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateShipmentInput
{
    public function __construct(
        #[Assert\Choice(choices: ['colissimo', 'dhl', 'ups', 'chronopost'])]
        public string $carrier = 'colissimo',
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(30)]
        public float $weightKg = 1.0,
    ) {
    }
}
