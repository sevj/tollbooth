<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Store;

#[Endpoint('DELETE', '/reviews/{id}')]
final readonly class DeleteReview
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id): void
    {
        $this->store->deleteReview($id);
    }
}
