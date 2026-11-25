<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CartView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/carts/{id}')]
final readonly class GetCart
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id): CartView
    {
        return $this->store->cart($id);
    }
}
