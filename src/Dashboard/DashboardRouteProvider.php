<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tollbooth\Core\Routing\RouteProviderInterface;
use Tollbooth\Dashboard\Controller\AssetController;
use Tollbooth\Dashboard\Controller\ChangesController;
use Tollbooth\Dashboard\Controller\ConfigurationController;
use Tollbooth\Dashboard\Controller\ConsumersController;
use Tollbooth\Dashboard\Controller\EndpointController;
use Tollbooth\Dashboard\Controller\EndpointListController;
use Tollbooth\Dashboard\Controller\HealthController;
use Tollbooth\Dashboard\Controller\HomeController;
use Tollbooth\Dashboard\Controller\KeysController;
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
    public const string ENDPOINTS = 'tollbooth_dashboard_endpoints';
    public const string ENDPOINT = 'tollbooth_dashboard_endpoint';
    public const string MODELS = 'tollbooth_dashboard_models';
    public const string MODEL = 'tollbooth_dashboard_model';
    public const string MODEL_SVG = 'tollbooth_dashboard_model_svg';
    public const string REQUESTS = 'tollbooth_dashboard_requests';
    public const string REQUESTS_FEED = 'tollbooth_dashboard_requests_feed';
    public const string REQUESTS_EXPORT = 'tollbooth_dashboard_requests_export';
    public const string CONSUMERS = 'tollbooth_dashboard_consumers';
    public const string CONSUMER = 'tollbooth_dashboard_consumer';
    public const string KEY_NEW = 'tollbooth_dashboard_key_new';
    public const string KEY_ROTATE = 'tollbooth_dashboard_key_rotate';
    public const string KEY_PLAN = 'tollbooth_dashboard_key_plan';
    public const string KEY_REVOKE = 'tollbooth_dashboard_key_revoke';
    public const string CHANGES = 'tollbooth_dashboard_changes';
    public const string HEALTH = 'tollbooth_dashboard_health';
    public const string CONFIGURATION = 'tollbooth_dashboard_configuration';
    public const string PLAN_NEW = 'tollbooth_dashboard_plan_new';
    public const string PLAN_EDIT = 'tollbooth_dashboard_plan_edit';
    public const string PLAN_DELETE = 'tollbooth_dashboard_plan_delete';
    public const string ASSET = 'tollbooth_dashboard_asset';

    public function __construct(
        private string $path = '/_tollbooth',
        private bool $monitoring = true,
        private bool $models = true,
        private bool $changes = true,
        private bool $health = true,
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
        $routes->add(self::ENDPOINTS, new Route($base.'/endpoints/{group}', ['_controller' => EndpointListController::class], $group, methods: ['GET']));
        $routes->add(self::ENDPOINT, new Route($base.'/endpoints/{group}/{name}', ['_controller' => EndpointController::class], [...$group, 'name' => '[A-Za-z0-9_.-]+'], methods: ['GET']));
        $routes->add(self::MODELS, new Route($base.'/models/{group}', ['_controller' => ModelsController::class.'::index'], $group, methods: ['GET']));
        $routes->add(self::MODEL_SVG, new Route($base.'/models/{group}/{model}.svg', ['_controller' => ModelsController::class.'::svg'], [...$group, 'model' => '[A-Za-z0-9_]+'], methods: ['GET']));
        $routes->add(self::MODEL, new Route($base.'/models/{group}/{model}', ['_controller' => ModelsController::class.'::page'], [...$group, 'model' => '[A-Za-z0-9_]+'], methods: ['GET']));
        $routes->add(self::REQUESTS_EXPORT, new Route($base.'/requests/{group}.ndjson', ['_controller' => RequestsController::class.'::export'], $group, methods: ['GET']));
        $routes->add(self::REQUESTS_FEED, new Route($base.'/requests/{group}/feed', ['_controller' => RequestsController::class.'::feed'], $group, methods: ['GET']));
        $routes->add(self::REQUESTS, new Route($base.'/requests/{group}', ['_controller' => RequestsController::class.'::page'], $group, methods: ['GET']));
        $routes->add(self::CONSUMERS, new Route($base.'/consumers/{group}', ['_controller' => ConsumersController::class.'::list'], $group, methods: ['GET']));
        $routes->add(self::KEY_NEW, new Route($base.'/consumers/{group}/keys/new', ['_controller' => KeysController::class.'::create'], $group, methods: ['GET', 'POST']));
        foreach (['rotate' => self::KEY_ROTATE, 'plan' => self::KEY_PLAN, 'revoke' => self::KEY_REVOKE] as $action => $name) {
            $routes->add($name, new Route($base.'/consumers/{group}/{consumer}/'.$action, ['_controller' => KeysController::class.'::'.$action], [...$group, 'consumer' => '[^/]+'], methods: ['POST']));
        }
        $routes->add(self::CONSUMER, new Route($base.'/consumers/{group}/{consumer}', ['_controller' => ConsumersController::class.'::page'], [...$group, 'consumer' => '[^/]+'], methods: ['GET']));
        $routes->add(self::CHANGES, new Route($base.'/changes/{group}', ['_controller' => ChangesController::class], $group, methods: ['GET']));
        $routes->add(self::HEALTH, new Route($base.'/status/{group}', ['_controller' => HealthController::class], $group, methods: ['GET']));
        $routes->add(self::CONFIGURATION, new Route($base.'/configuration/{group}', ['_controller' => ConfigurationController::class.'::page'], $group, methods: ['GET']));
        $routes->add(self::PLAN_NEW, new Route($base.'/configuration/{group}/plans/new', ['_controller' => ConfigurationController::class.'::create'], $group, methods: ['GET', 'POST']));
        $routes->add(self::PLAN_DELETE, new Route($base.'/configuration/{group}/plans/{plan}/delete', ['_controller' => ConfigurationController::class.'::delete'], [...$group, 'plan' => '[a-z0-9][a-z0-9_-]*'], methods: ['POST']));
        $routes->add(self::PLAN_EDIT, new Route($base.'/configuration/{group}/plans/{plan}', ['_controller' => ConfigurationController::class.'::edit'], [...$group, 'plan' => '[a-z0-9][a-z0-9_-]*'], methods: ['GET', 'POST']));
        $routes->add(self::ASSET, new Route($base.'/assets/{file}', ['_controller' => AssetController::class], ['file' => '[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)?\.(css|js|woff2|svg)'], methods: ['GET']));

        $routes->remove(array_merge(
            $this->monitoring ? [] : [self::REQUESTS, self::REQUESTS_FEED, self::REQUESTS_EXPORT],
            $this->models ? [] : [self::MODELS, self::MODEL, self::MODEL_SVG],
            $this->changes ? [] : [self::CHANGES],
            $this->health ? [] : [self::HEALTH],
        ));

        return $routes;
    }
}
