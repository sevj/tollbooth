<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class ShipmentView
{
    public function __construct(
        public string $id,
        public string $orderId,
        public string $carrier,
        public string $trackingNumber,
        public \DateTimeImmutable $shippedAt,
    ) {
    }
}
