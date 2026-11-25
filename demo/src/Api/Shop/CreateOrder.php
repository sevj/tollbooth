<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CreateOrderInput;
use Tollbooth\Demo\Model\OrderView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/orders', status: 201)]
final readonly class CreateOrder
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(#[Body] CreateOrderInput $input): OrderView
    {
        return $this->store->createOrder($input);
    }
}
