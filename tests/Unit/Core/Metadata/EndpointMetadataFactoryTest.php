<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core\Metadata;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Attribute\Auth;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Core\Attribute\Scope;
use Tollbooth\Core\Metadata\EndpointMetadataFactory;
use Tollbooth\Core\Metadata\Group;
use Tollbooth\Core\Metadata\GroupResolver;
use Tollbooth\Core\Metadata\InvalidEndpointException;
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Tests\Fixtures\Model\Color;

final class EndpointMetadataFactoryTest extends TestCase
{
    public function testNameIsDerivedFromTheClassAndMethodNormalized(): void
    {
        $metadata = (new EndpointMetadataFactory())->create('app.endpoint', new \ReflectionClass(GetOrderLines::class));

        self::assertSame('get_order_lines', $metadata->name);
        self::assertSame('GET', $metadata->method);
        self::assertSame('/orders/{id}/lines/{color}', $metadata->path);
        self::assertSame('app.endpoint', $metadata->serviceId);
    }

    public function testRouteRequirementsFollowParameterTypes(): void
    {
        $metadata = (new EndpointMetadataFactory())->create('app.endpoint', new \ReflectionClass(GetOrderLines::class));

        self::assertSame(['id' => '\d+', 'color' => 'red|amber'], $metadata->requirements);
    }

    public function testExplicitNameWins(): void
    {
        $metadata = (new EndpointMetadataFactory())->create('app.endpoint', new \ReflectionClass(NamedEndpoint::class));

        self::assertSame('custom', $metadata->name);
        self::assertSame([], $metadata->requirements);
    }

    public function testUnknownMethodIsRejected(): void
    {
        $this->expectException(InvalidEndpointException::class);
        $this->expectExceptionMessage('unsupported HTTP method "FETCH"');

        (new EndpointMetadataFactory())->create('app.endpoint', new \ReflectionClass(WrongMethod::class));
    }

    public function testEndpointMustBeInvokable(): void
    {
        $this->expectException(InvalidEndpointException::class);
        $this->expectExceptionMessage('public __invoke()');

        (new EndpointMetadataFactory())->create('app.endpoint', new \ReflectionClass(NotInvokable::class));
    }

    public function testAuthComesFromTheAttributeThenTheGroup(): void
    {
        $factory = $this->factoryWithGroup(['auth' => ['jwt'], 'scopes' => ['partner']]);

        $declared = $factory->create('a', new \ReflectionClass(KeyOrJwt::class));
        $inherited = $factory->create('b', new \ReflectionClass(GetOrderLines::class));

        self::assertSame(['api_key', 'jwt'], $declared->auth);
        self::assertSame(['jwt'], $inherited->auth);
        self::assertSame(['partner'], $inherited->scopes);
    }

    public function testPublicEndpointRequiresNothing(): void
    {
        $metadata = $this->factoryWithGroup(['auth' => ['jwt'], 'scopes' => ['partner']])->create('a', new \ReflectionClass(OpenEndpoint::class));

        self::assertSame(['anonymous'], $metadata->auth);
        self::assertSame([], $metadata->scopes);
    }

    public function testAutoScopesFollowTheResourceAndTheMethod(): void
    {
        $factory = $this->factoryWithGroup(['scopes' => 'auto']);

        self::assertSame(['orders:read'], $factory->create('a', new \ReflectionClass(GetOrderLines::class))->scopes);
        self::assertSame(['things:write'], $factory->create('b', new \ReflectionClass(NamedEndpoint::class))->scopes);
    }

    public function testDeclaredScopesReplaceTheGroupOnes(): void
    {
        $metadata = $this->factoryWithGroup(['scopes' => 'auto'])->create('a', new \ReflectionClass(KeyOrJwt::class));

        self::assertSame(['billing:read', 'billing:export'], $metadata->scopes);
    }

    public function testUnknownAuthMethodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown authentication method "oauth"');

        (new EndpointMetadataFactory())->create('a', new \ReflectionClass(UnknownAuth::class));
    }

    /**
     * @param array{auth?: list<string>, scopes?: list<string>|string} $settings
     */
    private function factoryWithGroup(array $settings): EndpointMetadataFactory
    {
        return new EndpointMetadataFactory(new GroupResolver(
            new Group(Group::DEFAULT, Envelope::Plain, NameCase::Preserve),
            ['api' => ['namespace' => __NAMESPACE__, 'path' => null, 'prefix' => '', 'output' => ['envelope' => null, 'case' => null], ...$settings]],
        ));
    }
}

#[Endpoint('GET', '/billing')]
#[Auth('api_key', 'jwt')]
#[Scope('billing:read')]
#[Scope('billing:export')]
final class KeyOrJwt
{
    public function __invoke(): void
    {
    }
}

#[Endpoint('GET', '/health')]
#[PublicEndpoint]
final class OpenEndpoint
{
    public function __invoke(): void
    {
    }
}

#[Endpoint('GET', '/things')]
#[Auth('oauth')]
final class UnknownAuth
{
    public function __invoke(): void
    {
    }
}

#[Endpoint('get', '/orders/{id}/lines/{color}/')]
final class GetOrderLines
{
    public function __invoke(int $id, Color $color, #[Query] int $page = 1): void
    {
    }
}

#[Endpoint('POST', '/things/{id}', name: 'custom')]
final class NamedEndpoint
{
    public function __invoke(#[Query] int $id): void
    {
    }
}

#[Endpoint('FETCH', '/things')]
final class WrongMethod
{
    public function __invoke(): void
    {
    }
}

#[Endpoint('GET', '/things')]
final class NotInvokable
{
}
