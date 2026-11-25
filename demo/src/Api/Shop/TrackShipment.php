<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\RateLimit;
use Tollbooth\Demo\Model\TrackingEventView;

#[Endpoint('GET', '/shipments/{id}/tracking')]
#[RateLimit('10/minute')]
final readonly class TrackShipment
{
    /**
     * @return list<TrackingEventView>
     */
    public function __invoke(string $id): array
    {
        usleep(random_int(80, 200) * 1000);

        return [
            new TrackingEventView('picked_up', 'Lyon', new \DateTimeImmutable('2026-11-23 15:20:00 UTC')),
            new TrackingEventView('in_transit', 'Paris', new \DateTimeImmutable('2026-11-24 06:10:00 UTC')),
            new TrackingEventView('out_for_delivery', 'Lille', new \DateTimeImmutable('2026-11-25 08:45:00 UTC')),
        ];
    }
}
