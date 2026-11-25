<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class OrderView
{
    /**
     * @param list<OrderLineView> $lines
     */
    public function __construct(
        public string $id,
        public string $number,
        public OrderStatus $status,
        public string $customerId,
        public array $lines,
        public Money $total,
        public \DateTimeImmutable $createdAt,
        public ?string $trackingUrl = null,
    ) {
    }
}
