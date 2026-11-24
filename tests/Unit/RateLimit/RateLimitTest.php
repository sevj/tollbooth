<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\RateLimit\WindowCounter;

final class RateLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int, int}>
     */
    public static function specs(): iterable
    {
        yield 'per minute' => ['600/minute', 'minute', 600, 60];
        yield 'per ten seconds' => ['100/10 seconds', '10s', 100, 10];
        yield 'abbreviated' => ['50/5m', '5m', 50, 300];
        yield 'per day' => ['200000/day', 'day', 200000, 86400];
        yield 'plural and spaces' => [' 3 / 2 hours ', '2h', 3, 7200];
    }

    #[DataProvider('specs')]
    public function testLimitSpecsAreParsed(string $spec, string $name, int $limit, int $window): void
    {
        $parsed = Limit::parse($spec, 'plan');

        self::assertSame([$name, $limit, $window], [$parsed->name, $parsed->limit, $parsed->window]);
    }

    public function testInvalidSpecIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"600 per minute" is not a rate limit');

        Limit::parse('600 per minute', 'plan');
    }

    public function testTwoLimitsWithTheSameNameAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('named "minute"');

        Limit::parseAll(['600/minute', '100/1 minute'], 'plan');
    }

    public function testWindowsAreAlignedOnTheClock(): void
    {
        $clock = new MockClock('2026-11-25 23:59:30', 'UTC');
        $counter = new WindowCounter(new ArrayAdapter(), null, $clock);
        $day = Limit::parse('2/day', 'plan');

        $first = $counter->hit('acme', $day);
        $second = $counter->hit('acme', $day);
        $third = $counter->hit('acme', $day);

        self::assertSame([true, 1, 30], [$first->accepted, $first->remaining, $first->resetIn]);
        self::assertSame([true, 0], [$second->accepted, $second->remaining]);
        self::assertFalse($third->accepted);

        $clock->sleep(31);
        $nextDay = $counter->hit('acme', $day);
        self::assertSame([true, 1, 86399], [$nextDay->accepted, $nextDay->remaining, $nextDay->resetIn]);
    }

    public function testConsumersAndScopesAreCountedApart(): void
    {
        $counter = new WindowCounter(new ArrayAdapter(), null, new MockClock('2026-11-25 10:00:00', 'UTC'));
        $plan = Limit::parse('1/minute', 'plan');
        $endpoint = Limit::parse('1/minute', 'endpoint:default.search');

        self::assertTrue($counter->hit('acme', $plan)->accepted);
        self::assertTrue($counter->hit('globex', $plan)->accepted);
        self::assertTrue($counter->hit('acme', $endpoint)->accepted);
        self::assertFalse($counter->hit('acme', $plan)->accepted);
    }
}
