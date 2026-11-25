<?php

declare(strict_types=1);

namespace Tollbooth\Core\Health;

final readonly class CheckResult
{
    public function __construct(
        public HealthStatus $status,
        public string $summary,
    ) {
    }

    public static function ok(string $summary): self
    {
        return new self(HealthStatus::Ok, $summary);
    }

    public static function warning(string $summary): self
    {
        return new self(HealthStatus::Warning, $summary);
    }

    public static function critical(string $summary): self
    {
        return new self(HealthStatus::Critical, $summary);
    }
}
