<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Mobile;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/favorites')]
final readonly class ListFavorites
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<ProductView>
     */
    public function __invoke(Consumer $consumer): array
    {
        return $this->store->favorites($consumer->id);
    }
}
