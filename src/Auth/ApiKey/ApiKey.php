<?php

declare(strict_types=1);

namespace Tollbooth\Auth\ApiKey;

final readonly class ApiKey
{
    /**
     * @param list<string> $scopes
     * @param list<string> $allowedIps
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $hash,
        public array $scopes = [],
        public ?string $plan = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public array $allowedIps = [],
    ) {
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }
}
