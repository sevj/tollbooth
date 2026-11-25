<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\OrderView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/orders/{id}/cancel')]
final readonly class CancelOrder
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id): OrderView
    {
        return $this->store->cancelOrder($id);
    }
}
