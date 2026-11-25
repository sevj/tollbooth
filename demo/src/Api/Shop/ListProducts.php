<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\Category;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/products')]
#[PublicEndpoint]
final readonly class ListProducts
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<ProductView>
     */
    public function __invoke(#[Query] ?Category $category = null, #[Query] int $page = 1): array
    {
        return $this->store->products($category, 20, (max(1, $page) - 1) * 20);
    }
}
