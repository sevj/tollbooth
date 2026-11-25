<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;
use Tollbooth\Demo\Model\Category;
use Tollbooth\Demo\Model\CategoryView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/categories/{slug}')]
#[PublicEndpoint]
final readonly class GetCategory
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(Category $slug): CategoryView
    {
        return new CategoryView('cat_'.$slug->value, $slug, ucfirst($slug->value), $this->store->countProducts($slug));
    }
}
