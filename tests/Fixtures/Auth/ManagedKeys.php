<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Auth;

use Tollbooth\Auth\ApiKey\ApiKey;
use Tollbooth\Auth\ApiKey\ManageableApiKeyProviderInterface;

final class ManagedKeys implements ManageableApiKeyProviderInterface
{
    /**
     * @var array<string, ApiKey>
     */
    private array $keys = [];

    public function find(string $id): ?ApiKey
    {
        return $this->keys[$id] ?? null;
    }

    public function all(): iterable
    {
        return array_values($this->keys);
    }

    public function save(ApiKey $key): void
    {
        $this->keys[$key->id] = $key;
    }

    public function remove(string $id): void
    {
        unset($this->keys[$id]);
    }

    public function manages(string $id): bool
    {
        return isset($this->keys[$id]);
    }
}
