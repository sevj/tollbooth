<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateOrderInput
{
    /**
     * @param list<OrderItemInput> $items
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $customerId = '',
        #[Assert\Count(min: 1, max: 50)]
        #[Assert\Valid]
        public array $items = [],
        #[Assert\Valid]
        public AddressInput $shippingAddress = new AddressInput(),
        #[Assert\Regex('/^[A-Z0-9]{6,12}$/')]
        public ?string $coupon = null,
    ) {
    }
}
