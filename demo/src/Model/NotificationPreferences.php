<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class NotificationPreferences
{
    public function __construct(
        public bool $orderUpdates = true,
        public bool $promotions = false,
        public bool $priceDrops = true,
        public ?string $quietHoursStart = null,
    ) {
    }
}
