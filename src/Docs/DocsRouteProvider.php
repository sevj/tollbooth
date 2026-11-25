<?php

declare(strict_types=1);

namespace Tollbooth\Docs;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tollbooth\Core\Metadata\Group;
use Tollbooth\Core\Routing\RouteProviderInterface;

/**
 * @internal
 */
final readonly class DocsRouteProvider implements RouteProviderInterface
{
    public const string PAGE_ROUTE = 'tollbooth_docs';
    public const string DOCUMENT_ROUTE = 'tollbooth_docs_openapi';

    public function __construct(
        private string $path = '/_tollbooth',
    ) {
    }

    public function routes(): RouteCollection
    {
        $base = rtrim($this->path, '/');
        $requirements = ['group' => '[A-Za-z0-9_.-]+'];

        $routes = new RouteCollection();
        $routes->add(self::PAGE_ROUTE, new Route(
            $base.'/docs/{group}',
            ['_controller' => DocsController::class.'::page', 'group' => Group::DEFAULT],
            $requirements,
            methods: ['GET'],
        ));
        $routes->add(self::DOCUMENT_ROUTE, new Route(
            $base.'/openapi/{group}.json',
            ['_controller' => DocsController::class.'::document'],
            $requirements,
            methods: ['GET'],
        ));

        return $routes;
    }
}
