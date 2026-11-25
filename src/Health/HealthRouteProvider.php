<?php

declare(strict_types=1);

namespace Tollbooth\Health;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tollbooth\Core\Routing\RouteProviderInterface;

/**
 * @internal
 */
final readonly class HealthRouteProvider implements RouteProviderInterface
{
    public function __construct(
        private string $path = '/_tollbooth',
        private string $probePath = '/_tollbooth/health',
    ) {
    }

    public function routes(): RouteCollection
    {
        $probes = rtrim($this->probePath, '/');
        $routes = new RouteCollection();
        $routes->add('tollbooth_health_live', new Route($probes.'/live', ['_controller' => HealthController::class.'::live'], methods: ['GET', 'HEAD']));
        $routes->add('tollbooth_health_ready', new Route($probes.'/ready', ['_controller' => HealthController::class.'::ready'], methods: ['GET', 'HEAD']));
        $routes->add('tollbooth_health', new Route(rtrim($this->path, '/').'/health.json', ['_controller' => HealthController::class.'::report'], methods: ['GET']));

        return $routes;
    }
}
