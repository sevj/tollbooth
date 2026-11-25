<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Mobile;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Store;

#[Endpoint('DELETE', '/favorites/{productId}')]
final readonly class RemoveFavorite
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $productId, Consumer $consumer): void
    {
        $this->store->removeFavorite($consumer->id, $productId);
    }
}
