<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Mobile;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/favorites/{productId}', status: 201)]
final readonly class AddFavorite
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $productId, Consumer $consumer): ProductView
    {
        return $this->store->addFavorite($consumer->id, $productId);
    }
}
