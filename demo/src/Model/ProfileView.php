<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class ProfileView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $authenticatedWith,
        public ?string $plan,
    ) {
    }
}
