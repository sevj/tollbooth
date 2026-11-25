<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class CouponView
{
    public function __construct(
        public string $id,
        public string $code,
        public int $percent,
        public bool $valid,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
