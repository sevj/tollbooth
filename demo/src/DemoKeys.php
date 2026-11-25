<?php

declare(strict_types=1);

namespace Tollbooth\Demo;

use Tollbooth\Auth\ApiKey\ApiKeyFormat;

final class DemoKeys
{
    private const array SHOP = [
        'products:read', 'products:write', 'carts:read', 'carts:write', 'orders:read', 'orders:write', 'payments:write',
        'shipments:read', 'reviews:write', 'coupons:write',
    ];
    private const array PARTNER = [
        'products:read', 'products:write', 'orders:write', 'webhooks:read', 'webhooks:write',
        'warehouses:read', 'stock-movements:read', 'stock-movements:write', 'invoices:read',
    ];

    /**
     * @return array<string, array{id: string, plan: string, scopes: list<string>, expires_at?: string, allowed_ips?: list<string>, test?: bool}>
     */
    public static function consumers(): array
    {
        return [
            'mobile-app' => ['id' => 'mobileapp001', 'plan' => 'partner', 'scopes' => [...self::SHOP, 'profile']],
            'web-front' => ['id' => 'webfront0001', 'plan' => 'partner', 'scopes' => [...self::SHOP, 'profile']],
            'acme-erp' => ['id' => 'acmeerp00001', 'plan' => 'pro', 'scopes' => self::PARTNER, 'expires_at' => '2027-03-02'],
            'legacy-ios' => ['id' => 'legacyios001', 'plan' => 'pro', 'scopes' => ['users:read', 'products:read', 'orders:read']],
            'shopify-sync' => ['id' => 'shopifysync1', 'plan' => 'pro', 'scopes' => ['products:read', 'products:write']],
            'zapier-hook' => ['id' => 'zapierhook01', 'plan' => 'free', 'scopes' => ['webhooks:read', 'webhooks:write', 'orders:read']],
            'sandbox-test' => ['id' => 'sandboxtest1', 'plan' => 'free', 'scopes' => self::SHOP, 'expires_at' => '2026-12-04', 'test' => true],
            'old-integration' => ['id' => 'oldintegrate', 'plan' => 'free', 'scopes' => ['orders:read'], 'expires_at' => '2026-10-01'],
        ];
    }

    public static function key(string $consumer): string
    {
        $config = self::consumers()[$consumer] ?? throw new \InvalidArgumentException(\sprintf('Unknown demo consumer "%s".', $consumer));

        return \sprintf('tb_%s_%s_%s', ($config['test'] ?? false) ? 'test' : 'live', $config['id'], self::secret($consumer));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function config(): array
    {
        $keys = [];
        foreach (self::consumers() as $name => $consumer) {
            $keys[$name] = array_filter([
                'id' => $consumer['id'],
                'hash' => ApiKeyFormat::hash(self::secret($name)),
                'scopes' => $consumer['scopes'],
                'plan' => $consumer['plan'],
                'expires_at' => $consumer['expires_at'] ?? null,
                'allowed_ips' => $consumer['allowed_ips'] ?? null,
                'test' => ($consumer['test'] ?? false) ?: null,
            ], static fn (mixed $value): bool => null !== $value);
        }

        return $keys;
    }

    private static function secret(string $consumer): string
    {
        return substr(str_repeat(preg_replace('/[^a-z]/', '', $consumer).'Demo2026', 4), 0, 32);
    }
}
