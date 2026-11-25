<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tollbooth\Dashboard\DashboardRouteProvider;

final class RouteProviderTest extends TestCase
{
    public function testScreensOfDisabledModulesHaveNoRoute(): void
    {
        $all = (new DashboardRouteProvider())->routes();
        $bare = (new DashboardRouteProvider('/_tollbooth', monitoring: false, models: false, changes: false, health: false))->routes();

        self::assertNotNull($all->get(DashboardRouteProvider::REQUESTS));
        foreach ([DashboardRouteProvider::REQUESTS, DashboardRouteProvider::CONSUMER, DashboardRouteProvider::KEY_NEW, DashboardRouteProvider::MODEL, DashboardRouteProvider::CHANGES, DashboardRouteProvider::HEALTH] as $name) {
            self::assertNull($bare->get($name), $name);
        }
        self::assertNotNull($bare->get(DashboardRouteProvider::MAP));
        self::assertNotNull($bare->get(DashboardRouteProvider::CONFIGURATION));
    }
}
