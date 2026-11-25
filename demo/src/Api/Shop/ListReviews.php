<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\Rating;
use Tollbooth\Demo\Model\ReviewView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/products/{id}/reviews')]
#[PublicEndpoint]
final readonly class ListReviews
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<ReviewView>
     */
    public function __invoke(string $id, #[Query('min_rating')] ?Rating $minRating = null): array
    {
        return $this->store->reviews($id, $minRating);
    }
}
