<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tollbooth\Core\Routing\RouteProviderInterface;
use Tollbooth\Dashboard\Controller\AssetController;
use Tollbooth\Dashboard\Controller\EndpointController;
use Tollbooth\Dashboard\Controller\HomeController;
use Tollbooth\Dashboard\Controller\MapController;

/**
 * @internal
 */
final readonly class DashboardRouteProvider implements RouteProviderInterface
{
    public const string PREFIX = 'tollbooth_dashboard';
    public const string HOME = 'tollbooth_dashboard';
    public const string MAP = 'tollbooth_dashboard_map';
    public const string MAP_MERMAID = 'tollbooth_dashboard_map_mermaid';
    public const string ENDPOINT = 'tollbooth_dashboard_endpoint';
    public const string ASSET = 'tollbooth_dashboard_asset';

    public function __construct(
        private string $path = '/_tollbooth',
    ) {
    }

    public function routes(): RouteCollection
    {
        $base = rtrim($this->path, '/');
        $group = ['group' => '[A-Za-z0-9_.-]+'];

        $routes = new RouteCollection();
        $routes->add(self::HOME, new Route('' === $base ? '/' : $base, ['_controller' => HomeController::class], methods: ['GET']));
        $routes->add(self::MAP_MERMAID, new Route($base.'/map/{group}.mmd', ['_controller' => MapController::class.'::mermaid'], $group, methods: ['GET']));
        $routes->add(self::MAP, new Route($base.'/map/{group}', ['_controller' => MapController::class.'::page'], $group, methods: ['GET']));
        $routes->add(self::ENDPOINT, new Route($base.'/endpoints/{group}/{name}', ['_controller' => EndpointController::class], [...$group, 'name' => '[A-Za-z0-9_.-]+'], methods: ['GET']));
        $routes->add(self::ASSET, new Route($base.'/assets/{file}', ['_controller' => AssetController::class], ['file' => '[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)?\.(css|js|woff2|svg)'], methods: ['GET']));

        return $routes;
    }
}
