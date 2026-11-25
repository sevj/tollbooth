<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit\Health;

use Psr\Cache\CacheItemPoolInterface;
use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\HealthCheckInterface;

/**
 * @internal
 */
final readonly class CounterStorageCheck implements HealthCheckInterface
{
    private const string KEY = 'tollbooth_health_probe';

    public function __construct(
        private CacheItemPoolInterface $cache,
        private string $poolName,
    ) {
    }

    public function name(): string
    {
        return 'rate_limit_storage';
    }

    public function check(): CheckResult
    {
        $token = bin2hex(random_bytes(8));
        $start = microtime(true);
        $this->cache->save($this->cache->getItem(self::KEY)->set($token)->expiresAfter(60));
        $read = $this->cache->getItem(self::KEY)->get();
        $elapsed = (microtime(true) - $start) * 1000;

        if ($read !== $token) {
            return CheckResult::critical(\sprintf('The cache pool "%s" does not keep what is written to it: rate limits cannot be counted.', $this->poolName));
        }

        return CheckResult::ok(\sprintf('Cache pool "%s" answers in %.1f ms.', $this->poolName, $elapsed));
    }
}
