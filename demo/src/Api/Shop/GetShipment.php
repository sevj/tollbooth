<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\ShipmentView;

#[Endpoint('GET', '/shipments/{id}')]
final readonly class GetShipment
{
    public function __invoke(string $id): ShipmentView
    {
        return new ShipmentView($id, 'ord_'.substr($id, 4), 'colissimo', '6A'.crc32($id), new \DateTimeImmutable('2026-11-23 15:20:00 UTC'));
    }
}
