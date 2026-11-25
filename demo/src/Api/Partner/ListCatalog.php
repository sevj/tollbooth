<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/products')]
final readonly class ListCatalog
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<ProductView>
     */
    public function __invoke(#[Query('updated_since')] ?string $updatedSince = null): array
    {
        $since = null === $updatedSince ? null : new \DateTimeImmutable($updatedSince);

        return array_values(array_filter($this->store->products(), static fn (ProductView $product): bool => null === $since || $product->updatedAt >= $since));
    }
}
