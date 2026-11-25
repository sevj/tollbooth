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
use Tollbooth\Dashboard\Controller\ModelsController;
use Tollbooth\Dashboard\Controller\RequestsController;

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
    public const string MODELS = 'tollbooth_dashboard_models';
    public const string MODEL = 'tollbooth_dashboard_model';
    public const string MODEL_SVG = 'tollbooth_dashboard_model_svg';
    public const string REQUESTS = 'tollbooth_dashboard_requests';
    public const string REQUESTS_FEED = 'tollbooth_dashboard_requests_feed';
    public const string REQUESTS_EXPORT = 'tollbooth_dashboard_requests_export';
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
        $routes->add(self::MODELS, new Route($base.'/models/{group}', ['_controller' => ModelsController::class.'::index'], $group, methods: ['GET']));
        $routes->add(self::MODEL_SVG, new Route($base.'/models/{group}/{model}.svg', ['_controller' => ModelsController::class.'::svg'], [...$group, 'model' => '[A-Za-z0-9_]+'], methods: ['GET']));
        $routes->add(self::MODEL, new Route($base.'/models/{group}/{model}', ['_controller' => ModelsController::class.'::page'], [...$group, 'model' => '[A-Za-z0-9_]+'], methods: ['GET']));
        $routes->add(self::REQUESTS_EXPORT, new Route($base.'/requests/{group}.ndjson', ['_controller' => RequestsController::class.'::export'], $group, methods: ['GET']));
        $routes->add(self::REQUESTS_FEED, new Route($base.'/requests/{group}/feed', ['_controller' => RequestsController::class.'::feed'], $group, methods: ['GET']));
        $routes->add(self::REQUESTS, new Route($base.'/requests/{group}', ['_controller' => RequestsController::class.'::page'], $group, methods: ['GET']));
        $routes->add(self::ASSET, new Route($base.'/assets/{file}', ['_controller' => AssetController::class], ['file' => '[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)?\.(css|js|woff2|svg)'], methods: ['GET']));

        return $routes;
    }
}
