<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Core\Attribute\RateLimit;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/products/search')]
#[PublicEndpoint]
#[RateLimit('30/minute')]
final readonly class SearchProducts
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<ProductView>
     */
    public function __invoke(#[Query] string $q): array
    {
        usleep(random_int(120, 260) * 1000);

        return array_values(array_filter($this->store->products(), static fn (ProductView $product): bool => str_contains(strtolower($product->name), strtolower($q))));
    }
}
