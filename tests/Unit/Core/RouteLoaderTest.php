<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Metadata\EndpointMetadataFactory;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Core\Routing\EndpointRouteLoader;
use Tollbooth\Dashboard\DashboardRouteProvider;

final class RouteLoaderTest extends TestCase
{
    public function testAnEndpointCannotTakeTheRouteNameOfATollboothPage(): void
    {
        $metadata = (new EndpointMetadataFactory())->create('app.endpoint', new \ReflectionClass(DashboardEndpoint::class));
        $loader = new EndpointRouteLoader(new EndpointRegistry([$metadata->toArray()]), '/api', 'hash', null, [new DashboardRouteProvider()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The route name "tollbooth_dashboard" of the endpoint "dashboard" is taken by one of Tollbooth\'s own pages.');

        $loader->load('.');
    }
}

#[Endpoint('GET', '/overview', name: 'dashboard')]
final class DashboardEndpoint
{
    public function __invoke(): void
    {
    }
}
