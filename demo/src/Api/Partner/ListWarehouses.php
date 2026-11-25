<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\WarehouseView;

#[Endpoint('GET', '/warehouses')]
final readonly class ListWarehouses
{
    /**
     * @return list<WarehouseView>
     */
    public function __invoke(): array
    {
        return [new WarehouseView('wh_lyon', 'Lyon', 12000), new WarehouseView('wh_lille', 'Lille', 8000), new WarehouseView('wh_nantes', 'Nantes', 5000)];
    }
}
