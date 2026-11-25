<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\AddItemInput;
use Tollbooth\Demo\Model\CartView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/carts/{id}/items', status: 201)]
final readonly class AddCartItem
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id, #[Body] AddItemInput $input): CartView
    {
        return $this->store->addToCart($id, $input->productId, $input->quantity);
    }
}
