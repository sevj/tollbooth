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

    public function testTheTableHoldsEveryPlanOnceFilledFromTheConfiguration(): void
    {
        self::assertSame(['free', 'pro'], $this->plans()->import());
        self::assertSame([], $this->plans()->import(), 'A table that holds plans is left alone.');

        $plans = $this->plans();
        self::assertSame(['free', 'pro'], array_keys($plans->all()));
        self::assertSame(['free', 'free'], [$plans->defaultPlan(), $plans->anonymousPlan()]);

        $plans->save('free', Limit::parseAll(['10/minute'], 'plan'), true);
        $plans->remove('pro');
        $plans = $this->plans();
        self::assertSame(['free'], array_keys($plans->all()));
        self::assertSame(['pro'], $plans->missing());
        self::assertSame(['pro'], $plans->import(true));
    }

    public function testKeysGetTheirPlanAndAnonymousCallsTheirOwn(): void
    {
        $plans = $this->plans();
        $plans->save('partner', Limit::parseAll(['burst' => '10/10 seconds', '600/minute'], 'plan'), default: true);
        $plans->save('open', Limit::parseAll(['5/minute'], 'plan'), anonymous: true);

        $registry = new PlanRegistry($this->plans());
        self::assertSame(['burst', 'minute'], self::names($registry->limitsFor(new Consumer('k1', 'acme', AuthMethod::ApiKey, [], 'partner'))));
        self::assertSame(['burst', 'minute'], self::names($registry->limitsFor(new Consumer('k2', 'other', AuthMethod::ApiKey, [], 'gone'))));
        self::assertSame(['minute'], self::names($registry->limitsFor(new Consumer('ip:1', 'ip:1', AuthMethod::Anonymous))));

        $plans->save('open', Limit::parseAll(['5/minute'], 'plan'), default: true);
        $plans = $this->plans();
        self::assertSame(['open', null], [$plans->defaultPlan(), $this->store->read()['anonymous']], 'Saving a plan as default or anonymous takes the role from the others.');
    }

    public function testChangesReplaceTheCachedPlans(): void
    {
        $plans = $this->plans();
        self::assertSame([], $plans->all());
        $this->store->save('partner', Limit::parseAll(['1/second'], 'plan'));
        self::assertSame([], $this->plans()->all(), 'The plans are cached between requests.');

        $plans->save('gold', Limit::parseAll(['5/second'], 'plan'));
        self::assertSame(['gold', 'partner'], array_keys($this->plans()->all()));
    }

    public function testAnUnreadableTableLeavesTheDeclaredPlans(): void
    {
        $broken = new DoctrinePlanStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $plans = new Plans(['free' => [Limit::parse('60/minute', 'plan')->toArray()]], 'free', null, $broken, $this->cache);

        self::assertSame(['free', 'free'], [array_key_first($plans->all()), $plans->defaultPlan()]);
        self::assertFalse($this->cache->hasItem('tollbooth.rate_limit.plans'));
    }

    public function testWithoutATableTheDeclaredPlansAreReadOnly(): void
    {
        $plans = new Plans(['free' => [Limit::parse('60/minute', 'plan')->toArray()]], 'free');
        self::assertFalse($plans->editable());

        $this->expectException(\LogicException::class);
        $plans->save('gold', Limit::parseAll(['1/second'], 'plan'));
    }

    private function plans(): Plans
    {
        return new Plans(
            ['free' => [Limit::parse('60/minute', 'plan')->toArray()], 'pro' => [Limit::parse('600/minute', 'plan')->toArray()]],
            'free',
            null,
            $this->store,
            $this->cache,
        );
    }

    /**
     * @param list<Limit> $limits
     *
     * @return list<string>
     */
    private static function names(array $limits): array
    {
        return array_map(static fn (Limit $limit): string => $limit->name, $limits);
    }
}
