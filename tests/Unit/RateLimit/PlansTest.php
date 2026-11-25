<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\RateLimit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\Core\Security\AuthMethod;
use Tollbooth\RateLimit\Doctrine\DoctrinePlanStore;
use Tollbooth\RateLimit\PlanRegistry;
use Tollbooth\RateLimit\Plans;

final class PlansTest extends TestCase
{
    private DoctrinePlanStore $store;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->store = new DoctrinePlanStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->store->setup();
        $this->cache = new ArrayAdapter();
    }

    public function testStoredPlansJoinTheConfiguredOnesAndApplyToKeys(): void
    {
        $plans = $this->plans();
        $plans->save('partner', Limit::parseAll(['burst' => '10/10 seconds', '600/minute'], 'plan'));

        $registry = new PlanRegistry($this->plans());
        self::assertSame(['burst', 'minute'], array_map(static fn (Limit $limit): string => $limit->name, $registry->limitsFor(new Consumer('k1', 'acme', AuthMethod::ApiKey, [], 'partner'))));
        self::assertSame(['minute'], array_map(static fn (Limit $limit): string => $limit->name, $registry->limitsFor(new Consumer('k2', 'other', AuthMethod::ApiKey, [], 'gone'))));
        self::assertSame(['free', 'partner'], array_keys($this->plans()->all()));
    }

    public function testASavedPlanReplacesTheCachedList(): void
    {
        $plans = $this->plans();
        self::assertSame(['free'], array_keys($plans->all()));
        $this->store->save('partner', Limit::parseAll(['1/second'], 'plan'));
        self::assertSame(['free'], array_keys($this->plans()->all()), 'The list is cached between requests.');

        $plans->save('gold', Limit::parseAll(['5/second'], 'plan'));
        self::assertSame(['free', 'gold', 'partner'], array_keys($this->plans()->all()));

        $plans->remove('gold');
        self::assertSame(['free', 'partner'], array_keys($this->plans()->all()));
    }

    public function testConfiguredPlansCannotBeChanged(): void
    {
        $this->expectException(\LogicException::class);
        $this->plans()->save('free', Limit::parseAll(['1/second'], 'plan'));
    }

    public function testAnUnreadableTableLeavesTheConfiguredPlans(): void
    {
        $broken = new DoctrinePlanStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $plans = new Plans(['free' => [Limit::parse('60/minute', 'plan')->toArray()]], 'free', null, $broken, $this->cache);

        self::assertSame(['free'], array_keys($plans->all()));
        self::assertFalse($this->cache->hasItem('tollbooth.rate_limit.plans'));
    }

    private function plans(): Plans
    {
        return new Plans(['free' => [Limit::parse('60/minute', 'plan')->toArray()]], 'free', null, $this->store, $this->cache);
    }
}
