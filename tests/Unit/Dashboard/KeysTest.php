<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tollbooth\Auth\ApiKey\ApiKey;
use Tollbooth\Auth\ApiKey\ChainApiKeyProvider;
use Tollbooth\Auth\ApiKey\InMemoryApiKeyProvider;
use Tollbooth\Auth\ApiKey\ManageableApiKeyProviderInterface;
use Tollbooth\Dashboard\Keys\FormGuard;
use Tollbooth\Dashboard\Keys\KeyForm;

final class KeysTest extends TestCase
{
    public function testAValidFormIsReadIntoAKey(): void
    {
        $form = KeyForm::submit(self::post(['name' => 'acme-erp', 'plan' => 'pro', 'scopes' => 'orders:read,orders:write orders:read', 'expires' => '2027-03-01', 'allowed_ips' => '203.0.113.0/24 2001:db8::1', 'test' => '1']), ['free', 'pro'], ['other'], new \DateTimeImmutable('2026-11-25'));

        self::assertTrue($form->valid());
        self::assertSame(['acme-erp', 'pro', ['orders:read', 'orders:write'], ['203.0.113.0/24', '2001:db8::1'], true], [$form->name, $form->plan, $form->scopes, $form->allowedIps, $form->test]);
        self::assertSame('2027-03-01T00:00:00+00:00', $form->expiresAt?->format(\DATE_ATOM));
    }

    public function testInvalidFieldsAreReported(): void
    {
        $form = KeyForm::submit(self::post(['name' => 'Acme ERP', 'plan' => 'gold', 'scopes' => 'orders read!', 'expires' => 'soon', 'allowed_ips' => '10.0.0.0/33']), ['free'], [], new \DateTimeImmutable('2026-11-25'));

        self::assertSame(['name' => 'name_format', 'plan' => 'plan_unknown', 'scopes' => 'scope_format', 'expires' => 'expires_format', 'allowed_ips' => 'ip_format'], $form->errors);
        self::assertSame('name_taken', KeyForm::submit(self::post(['name' => 'ACME']), [], ['acme'], new \DateTimeImmutable())->errors['name'] ?? null);
        self::assertSame('expires_past', KeyForm::submit(self::post(['name' => 'a', 'expires' => '2026-11-25']), [], [], new \DateTimeImmutable('2026-11-25 10:00'))->errors['expires'] ?? null);
    }

    public function testTheGuardChecksTheTokenAndWhereTheFormCameFrom(): void
    {
        $guard = new FormGuard('secret');
        $token = $guard->token('revoke', 'k1');
        self::assertNotSame($token, $guard->token('revoke', 'k2'));
        self::assertNotSame($token, (new FormGuard('other'))->token('revoke', 'k1'));

        $guard->check(self::post(['_token' => $token], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin']), 'revoke', 'k1');

        foreach ([[['_token' => 'nope'], []], [['_token' => $token], ['HTTP_ORIGIN' => 'https://evil.example']], [['_token' => $token], ['HTTP_SEC_FETCH_SITE' => 'cross-site']]] as [$body, $server]) {
            try {
                $guard->check(self::post($body, $server), 'revoke', 'k1');
                self::fail('The guard let a forged form through.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testConfiguredKeysStayReadOnlyNextToStoredOnes(): void
    {
        $configured = new InMemoryApiKeyProvider(['legacy' => ['id' => 'conf00000001', 'hash' => 'h', 'scopes' => [], 'plan' => null, 'expires_at' => null, 'allowed_ips' => []]]);
        $store = new class implements ManageableApiKeyProviderInterface {
            /** @var array<string, ApiKey> */
            public array $keys = [];

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
        };
        $chain = new ChainApiKeyProvider($configured, $store);
        $chain->save(new ApiKey('stored000001', 'acme', 'h'));

        self::assertSame(['conf00000001', 'stored000001'], array_map(static fn (ApiKey $key): string => $key->id, [...$chain->all()]));
        self::assertTrue($chain->manages('stored000001'));
        self::assertFalse($chain->manages('conf00000001'));
        self::assertSame('legacy', $chain->find('conf00000001')?->name);

        $this->expectException(\LogicException::class);
        $chain->remove('conf00000001');
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $server
     */
    private static function post(array $body, array $server = []): Request
    {
        return Request::create('http://localhost/_tollbooth/keys', 'POST', $body, server: $server);
    }
}
