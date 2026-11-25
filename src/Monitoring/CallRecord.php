<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

final readonly class CallRecord
{
    /**
     * @param array<string, float>                                                                $steps
     * @param list<array{name: string, limit: int, window: int, remaining: int, reset: int}>|null $limiters
     */
    public function __construct(
        public string $id,
        public int $occurredAt,
        public string $endpoint,
        public string $group,
        public string $method,
        public string $path,
        public int $status,
        public float $duration,
        public string $consumerId,
        public string $consumerName,
        public string $authMethod,
        public ?string $ip = null,
        public ?int $responseSize = null,
        public ?string $error = null,
        public array $steps = [],
        public ?array $limiters = null,
    ) {
    }

    public static function newId(int $occurredAt): string
    {
        return \sprintf('%012x%s', $occurredAt, bin2hex(random_bytes(7)));
    }

    public function minute(): int
    {
        return intdiv(intdiv($this->occurredAt, 1000), 60) * 60;
    }
}
