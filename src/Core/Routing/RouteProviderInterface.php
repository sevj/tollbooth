<?php

declare(strict_types=1);

namespace Tollbooth\Core\Routing;

use Symfony\Component\Routing\RouteCollection;

/**
 * @internal
 */
interface RouteProviderInterface
{
    public const string TAG = 'tollbooth.route_provider';

    public function routes(): RouteCollection;
}
