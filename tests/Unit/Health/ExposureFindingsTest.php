<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallContext;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\Security\Http\AccessMap;
use Tollbooth\Core\Health\Finding;
use Tollbooth\Health\ExposureFindings;

final class ExposureFindingsTest extends TestCase
{
    public function testAFirewallWithoutARequiredRoleStillExposesThePage(): void
    {
        $container = new Container();
        $container->set('main', new FirewallContext([], null, null, new FirewallConfig('main', 'security.user_checker')));
        $firewalls = new FirewallMap($container, ['main' => new PathRequestMatcher('^/')]);
        $access = new AccessMap();
        $access->add(new PathRequestMatcher('^/_tollbooth/docs'), ['ROLE_ADMIN']);
        $access->add(new PathRequestMatcher('^/_tollbooth/health'), ['PUBLIC_ACCESS']);

        $findings = new ExposureFindings(['documentation' => '/_tollbooth/docs', 'health report' => '/_tollbooth/health.json'], $firewalls, $access);

        self::assertSame(
            ['The health report at /_tollbooth/health.json is behind the "main" firewall, but no access_control rule requires a role for it: anyone can read it.'],
            array_map(static fn (Finding $finding): string => $finding->message, [...$findings->findings()]),
        );
    }
}
