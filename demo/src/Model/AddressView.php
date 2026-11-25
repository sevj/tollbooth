<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class AddressView
{
    public function __construct(
        public string $id,
        public string $label,
        public string $line1,
        public string $city,
        public string $postalCode,
        public string $country,
    ) {
    }
}
