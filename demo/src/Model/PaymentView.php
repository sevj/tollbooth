<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class PaymentView
{
    public function __construct(
        public string $id,
        public string $orderId,
        public PaymentStatus $status,
        public Money $amount,
        public ?\DateTimeImmutable $paidAt = null,
    ) {
    }
}
