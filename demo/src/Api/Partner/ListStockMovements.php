<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\StockMovementView;

#[Endpoint('GET', '/stock-movements')]
final readonly class ListStockMovements
{
    /**
     * @return list<StockMovementView>
     */
    public function __invoke(#[Query] ?string $warehouse = null, #[Query] ?string $since = null, #[Query] int $limit = 50): array
    {
        $movements = [];
        for ($i = 0; $i < min($limit, 20); ++$i) {
            $movements[] = new StockMovementView('mv_'.(9000 - $i), $warehouse ?? 'wh_lyon', 'p_'.($i % 12 + 1), 0 === $i % 3 ? -2 : 12, new \DateTimeImmutable($since ?? '2026-11-25 05:00:00 UTC'));
        }

        return $movements;
    }
}
