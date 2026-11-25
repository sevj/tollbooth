<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Model\StockView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/warehouses/{id}/stock')]
final readonly class GetWarehouseStock
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<StockView>
     */
    public function __invoke(string $id, #[Query('low_stock_only')] bool $lowStockOnly = false): array
    {
        $share = ['wh_lyon' => 0.5, 'wh_lille' => 0.3, 'wh_nantes' => 0.2][$id] ?? 0.0;
        $stock = array_map(
            fn (ProductView $product): StockView => new StockView($product->id, (int) round($this->store->stock($product->id) * $share), $product->updatedAt),
            $this->store->products(),
        );

        return $lowStockOnly ? array_values(array_filter($stock, static fn (StockView $line): bool => $line->stock < 20)) : $stock;
    }
}
