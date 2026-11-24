<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\OidcUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Tollbooth\Auth\ApiKey\ApiKeyFormat;
use Tollbooth\Auth\Consumer;
use Tollbooth\Auth\TokenConsumerResolver;
use Tollbooth\Core\Security\AuthMethod;

final class AuthTest extends TestCase
{
    public function testGeneratedKeyParsesAndVerifies(): void
    {
        $generated = ApiKeyFormat::generate();
        $parsed = ApiKeyFormat::parse($generated['key']);

        self::assertStringStartsWith('tb_live_', $generated['key']);
        self::assertNotNull($parsed);
        self::assertSame($generated['id'], $parsed['id']);
        self::assertTrue(ApiKeyFormat::verify($parsed['secret'], $generated['hash']));
        self::assertFalse(ApiKeyFormat::verify(str_repeat('x', 32), $generated['hash']));
    }

    public function testMalformedKeysDoNotParse(): void
    {
        self::assertNull(ApiKeyFormat::parse('tb_prod_abcdefghijkl_'.str_repeat('a', 32)));
        self::assertNull(ApiKeyFormat::parse('tb_live_abc_'.str_repeat('a', 32)));
        self::assertNull(ApiKeyFormat::parse(''));
    }

    public function testOnlyScopeCarryingMethodsCanMissScopes(): void
    {
        $key = new Consumer('k', 'k', AuthMethod::ApiKey, ['orders:read']);
        $user = new Consumer('alice', 'alice', AuthMethod::User);

        self::assertSame(['orders:write'], $key->missingScopes(['orders:read', 'orders:write']));
        self::assertSame([], $user->missingScopes(['orders:write']));
    }

    public function testOidcUserBecomesAJwtConsumerWithItsScopes(): void
    {
        $user = new OidcUser(userIdentifier: 'user-42', sub: 'user-42', name: 'Acme ERP', client_id: 'acme-erp', scope: 'orders:read orders:write');

        $consumer = $this->resolver($user)->resolve(new Request());

        self::assertNotNull($consumer);
        self::assertSame('acme-erp', $consumer->id);
        self::assertSame('Acme ERP', $consumer->name);
        self::assertSame(AuthMethod::Jwt, $consumer->method);
        self::assertSame(['orders:read', 'orders:write'], $consumer->scopes);
    }

    public function testOtherUsersAreJwtWithABearerAndUsersOtherwise(): void
    {
        $resolver = $this->resolver(new InMemoryUser('alice', null));

        self::assertSame(AuthMethod::User, $resolver->resolve(new Request())?->method);
        self::assertSame(AuthMethod::Jwt, $resolver->resolve(new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer abc']))?->method);
    }

    public function testNoTokenStorageMeansNoConsumer(): void
    {
        self::assertNull((new TokenConsumerResolver())->resolve(new Request()));
    }

    private function resolver(UserInterface $user): TokenConsumerResolver
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'api'));

        return new TokenConsumerResolver($tokens);
    }
}
