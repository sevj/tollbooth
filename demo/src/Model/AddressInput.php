<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddressInput
{
    public function __construct(
        #[Assert\NotBlank]
        public string $line1 = '',
        #[Assert\NotBlank]
        public string $city = '',
        #[Assert\NotBlank]
        public string $postalCode = '',
        #[Assert\Length(exactly: 2)]
        public string $country = 'FR',
    ) {
    }
}
