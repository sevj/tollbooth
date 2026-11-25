<?php

declare(strict_types=1);

namespace Tollbooth\Auth\Health;

use Psr\Clock\ClockInterface;
use Tollbooth\Auth\ApiKey\ApiKeyProviderInterface;
use Tollbooth\Auth\ApiKey\ListableApiKeyProviderInterface;
use Tollbooth\Core\Health\Finding;
use Tollbooth\Core\Health\FindingProviderInterface;

/**
 * @internal
 */
final readonly class ApiKeyFindings implements FindingProviderInterface
{
    public function __construct(
        private ApiKeyProviderInterface $keys,
        private int $expiryWarningDays = 14,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function findings(): iterable
    {
        if (!$this->keys instanceof ListableApiKeyProviderInterface) {
            return;
        }

        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $soon = $now->modify(\sprintf('+%d days', $this->expiryWarningDays));
        foreach ($this->keys->all() as $key) {
            if (null === $key->expiresAt) {
                continue;
            }

            if ($key->isExpired($now)) {
                yield new Finding(\sprintf('The API key of "%s" expired on %s and is refused.', $key->name, $key->expiresAt->format('Y-m-d')));
            } elseif ($key->expiresAt <= $soon) {
                $days = (int) ceil(($key->expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
                yield new Finding(\sprintf('The API key of "%s" expires in %d day%s, on %s.', $key->name, $days, 1 === $days ? '' : 's', $key->expiresAt->format('Y-m-d')), true);
            }
        }
    }
}
