<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Mobile;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Core\Attribute\Scope;
use Tollbooth\Demo\Model\FeedItemView;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/feed')]
#[Scope('profile')]
final readonly class Feed
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<FeedItemView>
     */
    public function __invoke(#[Query] int $limit = 5): array
    {
        return array_map(
            static fn (ProductView $product): FeedItemView => new FeedItemView($product->id, $product->name, 'Popular in '.$product->category->value),
            \array_slice($this->store->products(), 0, max(1, min($limit, 12))),
        );
    }
}
