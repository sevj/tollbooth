<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Store;

#[Endpoint('DELETE', '/carts/{id}/items/{productId}')]
final readonly class RemoveCartItem
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id, string $productId): void
    {
        $this->store->removeFromCart($id, $productId);
    }
}
