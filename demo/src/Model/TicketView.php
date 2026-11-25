<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class TicketView
{
    public function __construct(
        public string $id,
        public string $subject,
        public string $customerId,
        public string $status,
        public int $messages,
    ) {
    }
}
