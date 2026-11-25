<?php

declare(strict_types=1);

namespace Tollbooth\Auth\Health;

use Symfony\Contracts\Cache\CacheInterface;
use Tollbooth\Auth\Doctrine\DoctrineApiKeyStore;
use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\HealthCheckInterface;
use Tollbooth\Core\Health\SchemaDrift;

/**
 * @internal
 */
final readonly class ApiKeyStoreCheck implements HealthCheckInterface
{
    public function __construct(
        private DoctrineApiKeyStore $store,
        private ?CacheInterface $cache = null,
    ) {
    }

    public function name(): string
    {
        return 'api_key_storage';
    }

    public function check(): CheckResult
    {
        try {
            $count = \count([...$this->store->all()]);
        } catch (\Throwable) {
            return CheckResult::critical('The table of API keys cannot be read: calls with a key not declared in the configuration fail. Run tollbooth:api-key:setup, or check the connection.');
        }

        if (SchemaDrift::pending($this->name(), $this->store->setupSql(...), $this->cache)) {
            return CheckResult::warning('The table of API keys is out of date. Run tollbooth:api-key:setup.');
        }

        return CheckResult::ok(\sprintf('%d stored key%s.', $count, 1 === $count ? '' : 's'));
    }
}
