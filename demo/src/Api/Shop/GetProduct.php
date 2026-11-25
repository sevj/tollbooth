<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/products/{id}')]
#[PublicEndpoint]
final readonly class GetProduct
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id): ProductView
    {
        return $this->store->findProduct($id);
    }
}
