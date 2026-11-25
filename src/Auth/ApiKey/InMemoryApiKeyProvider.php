<?php

declare(strict_types=1);

namespace Tollbooth\Auth\ApiKey;

/**
 * @phpstan-type ApiKeyConfig array{id: string, hash: string, scopes: list<string>, plan: ?string, expires_at: ?string, allowed_ips: list<string>, test?: bool}
 */
final class InMemoryApiKeyProvider implements ListableApiKeyProviderInterface
{
    /**
     * @var array<string, ApiKey>|null
     */
    private ?array $keys = null;

    /**
     * @param array<string, ApiKeyConfig> $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {
    }

    public function find(string $id): ?ApiKey
    {
        return ($this->keys ??= $this->load())[$id] ?? null;
    }

    public function all(): iterable
    {
        return array_values($this->keys ??= $this->load());
    }

    /**
     * @return array<string, ApiKey>
     */
    private function load(): array
    {
        $keys = [];
        foreach ($this->config as $name => $key) {
            $keys[$key['id']] = new ApiKey(
                id: $key['id'],
                name: $name,
                hash: $key['hash'],
                scopes: $key['scopes'],
                plan: $key['plan'],
                expiresAt: null === $key['expires_at'] ? null : new \DateTimeImmutable($key['expires_at']),
                allowedIps: $key['allowed_ips'],
                test: $key['test'] ?? false,
            );
        }

        return $keys;
    }
}
