<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Traffic;

/**
 * @internal
 */
enum Range: string
{
    case Hour = '1h';
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public function seconds(): int
    {
        return match ($this) {
            self::Hour => 3600,
            self::Day => 86400,
            self::Week => 7 * 86400,
            self::Month => 30 * 86400,
        };
    }

    public function buckets(): int
    {
        return match ($this) {
            self::Hour => 12,
            self::Day => 24,
            self::Week => 14,
            self::Month => 30,
        };
    }

    public static function fromQuery(mixed $value): self
    {
        return \is_string($value) ? self::tryFrom($value) ?? self::Day : self::Day;
    }
}
