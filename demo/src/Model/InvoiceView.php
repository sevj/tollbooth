<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class InvoiceView
{
    public function __construct(
        public string $id,
        public string $month,
        public Money $amount,
        public bool $paid,
    ) {
    }
}
