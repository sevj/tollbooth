<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Consumers;

use Tollbooth\Auth\ApiKey\ApiKey;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class ConsumerRow
{
    public const string ANONYMOUS = '~anonymous';

    public function __construct(
        public string $id,
        public string $name,
        public ?ApiKey $key = null,
        public ?string $plan = null,
        public ?Stats $stats = null,
        public int $today = 0,
        public ?int $dayLimit = null,
        public ?int $minuteLimit = null,
        public ?int $peak = null,
        public ?CallRecord $last = null,
        public int $members = 1,
    ) {
    }

    public function calls(): int
    {
        return $this->stats->calls ?? 0;
    }

    public function anonymous(): bool
    {
        return self::ANONYMOUS === $this->id;
    }

    public function quota(): ?float
    {
        return null === $this->dayLimit || 0 === $this->dayLimit ? null : min(1.0, $this->today / $this->dayLimit);
    }

    public function nearLimit(): bool
    {
        return ($this->quota() ?? 0.0) >= 0.8 || (null !== $this->peak && null !== $this->minuteLimit && $this->peak >= 0.9 * $this->minuteLimit);
    }

    public function throttled(): bool
    {
        return ($this->stats->throttled ?? 0) > 0;
    }

    public function idle(): bool
    {
        return 0 === $this->calls();
    }

    public function method(): string
    {
        return null !== $this->key ? 'api_key' : ($this->anonymous() ? 'anonymous' : ($this->last->authMethod ?? 'user'));
    }
}
