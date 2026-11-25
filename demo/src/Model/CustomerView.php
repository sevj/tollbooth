<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class CustomerView
{
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public bool $banned = false,
    ) {
    }
}
