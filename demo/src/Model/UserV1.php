<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class UserV1
{
    public function __construct(
        public int $id,
        public string $email,
        public string $full_name,
    ) {
    }
}
