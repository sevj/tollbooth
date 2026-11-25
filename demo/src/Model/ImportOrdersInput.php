<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ImportOrdersInput
{
    /**
     * @param list<CreateOrderInput> $orders
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 500)]
        #[Assert\Valid]
        public array $orders = [],
    ) {
    }
}
