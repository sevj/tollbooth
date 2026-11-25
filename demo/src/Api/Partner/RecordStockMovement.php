<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\StockMovementInput;
use Tollbooth\Demo\Model\StockMovementView;

#[Endpoint('POST', '/stock-movements', status: 201)]
final readonly class RecordStockMovement
{
    public function __invoke(#[Body] StockMovementInput $input): StockMovementView
    {
        return new StockMovementView('mv_'.bin2hex(random_bytes(3)), $input->warehouseId, $input->productId, $input->delta, new \DateTimeImmutable());
    }
}
