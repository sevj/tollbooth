<?php

declare(strict_types=1);

namespace Tollbooth\Core\Health;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';

    public function worst(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    public function overall(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Warning => 'degraded',
            self::Critical => 'critical',
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }
}
