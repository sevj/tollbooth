<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Symfony\Component\Routing\RouterInterface;

final class GroupsTest extends FunctionalTestCase
{
    public function testGroupEndpointsAreMountedAndUseTheGroupConventions(): void
    {
        $client = self::createClient(['environment' => 'groups']);
        $client->request('GET', '/api/partner/things/7');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.api+json');
        self::assertSame([
            'data' => [
                'type' => 'things',
                'id' => '7',
                'attributes' => ['name' => 'Partner thing 7', 'color' => 'amber', 'note' => null, 'unit_count' => 4],
            ],
        ], $this->json($client));
    }

    public function testEndpointsOutsideTheGroupKeepTheGlobalConventions(): void
    {
        $client = self::createClient(['environment' => 'groups']);
        $client->request('GET', '/api/things/7');

        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame('Thing 7', $this->json($client)['name']);
    }

    public function testExplicitGroupWinsOverTheNamespace(): void
    {
        $client = self::createClient(['environment' => 'groups']);
        $client->request('GET', '/api/ping');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['pong' => true], $this->json($client));
    }

    public function testSameEndpointNameCanBeReusedInAnotherGroup(): void
    {
        self::bootKernel(['environment' => 'groups']);
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertSame('/api/things/{id}', $router->getRouteCollection()->get('tollbooth_get_thing')?->getPath());
        self::assertSame('/api/partner/things/{id}', $router->getRouteCollection()->get('tollbooth_partner_get_thing')?->getPath());
    }
}
