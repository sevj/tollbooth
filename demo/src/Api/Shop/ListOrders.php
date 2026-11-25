<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\OrderStatus;
use Tollbooth\Demo\Model\OrderView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/orders')]
final readonly class ListOrders
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<OrderView>
     */
    public function __invoke(#[Query] ?OrderStatus $status = null, #[Query] int $page = 1): array
    {
        return $this->store->orders($status, max(1, $page));
    }
}
