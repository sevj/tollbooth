<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Admin;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Model\TopProductView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/reports/top-products')]
final readonly class TopProducts
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<TopProductView>
     */
    public function __invoke(#[Query] int $limit = 5): array
    {
        return array_map(static fn (ProductView $product): TopProductView => new TopProductView($product->id, $product->name, crc32($product->id) % 900), \array_slice($this->store->products(), 0, $limit));
    }
}
