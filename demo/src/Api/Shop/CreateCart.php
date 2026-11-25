<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CartView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/carts', status: 201)]
final readonly class CreateCart
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(): CartView
    {
        return $this->store->createCart();
    }
}
