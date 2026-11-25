<?php

declare(strict_types=1);

namespace Tollbooth\Auth\ApiKey;

/**
 * @internal
 */
final readonly class ChainApiKeyProvider implements ManageableApiKeyProviderInterface
{
    public function __construct(
        private ListableApiKeyProviderInterface $configured,
        private ManageableApiKeyProviderInterface $store,
    ) {
    }

    public function find(string $id): ?ApiKey
    {
        return $this->configured->find($id) ?? $this->store->find($id);
    }

    public function all(): iterable
    {
        $keys = [];
        foreach ($this->configured->all() as $key) {
            $keys[$key->id] = $key;
        }
        foreach ($this->store->all() as $key) {
            $keys[$key->id] ??= $key;
        }

        return array_values($keys);
    }

    public function save(ApiKey $key): void
    {
        if (null !== $this->configured->find($key->id)) {
            throw new \LogicException(\sprintf('The API key "%s" is declared in the configuration and cannot be changed from here.', $key->name));
        }

        $this->store->save($key);
    }

    public function remove(string $id): void
    {
        if (null !== $this->configured->find($id)) {
            throw new \LogicException(\sprintf('The API key "%s" is declared in the configuration and cannot be removed from here.', $id));
        }

        $this->store->remove($id);
    }

    public function manages(string $id): bool
    {
        return null === $this->configured->find($id) && $this->store->manages($id);
    }
}
