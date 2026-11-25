<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Legacy;

use Tollbooth\Core\Attribute\DeprecatedEndpoint;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/products')]
#[DeprecatedEndpoint(since: '2026-03-01', sunset: '2026-12-15', link: 'https://docs.example.com/migrate-to-v2')]
final readonly class ListProductsV1
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<ProductView>
     */
    public function __invoke(): array
    {
        return $this->store->products();
    }
}
