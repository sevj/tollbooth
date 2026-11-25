<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;
use Tollbooth\Demo\Model\Category;
use Tollbooth\Demo\Model\CategoryView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/categories')]
#[PublicEndpoint]
final readonly class ListCategories
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<CategoryView>
     */
    public function __invoke(): array
    {
        return array_map(
            fn (Category $category): CategoryView => new CategoryView('cat_'.$category->value, $category, ucfirst($category->value), $this->store->countProducts($category)),
            Category::cases(),
        );
    }
}
