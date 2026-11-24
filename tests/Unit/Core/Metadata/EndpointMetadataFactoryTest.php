<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core\Metadata;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Core\Metadata\EndpointMetadataFactory;
use Tollbooth\Core\Metadata\InvalidEndpointException;
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
