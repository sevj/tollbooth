<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency = 'EUR',
    ) {
    }

    public function times(int $quantity): self
    {
        return new self($this->amount * $quantity, $this->currency);
    }
}
