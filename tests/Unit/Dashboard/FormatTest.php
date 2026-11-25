<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tollbooth\Dashboard\Twig\Format;

final class FormatTest extends TestCase
{
    /**
     * @return iterable<array{int|float, string}>
     */
    public static function compactNumbers(): iterable
    {
        yield [820, '820'];
        yield [999, '999'];
        yield [1000, '1k'];
        yield [6240, '6.2k'];
        yield [9_960, '10k'];
        yield [38_400, '38k'];
        yield [121_000, '121k'];
        yield [999_700, '1M'];
        yield [1_284_000, '1.28M'];
        yield [12_400_000, '12.4M'];
    }

    #[DataProvider('compactNumbers')]
    public function testCountsAreShortened(int|float $value, string $expected): void
    {
        self::assertSame($expected, Format::compact($value));
    }

    public function testThousandsAreSeparatedByANarrowSpace(): void
    {
        self::assertSame("3\u{202F}214", Format::number(3214));
    }

    public function testRatiosAreShownAsPercentages(): void
    {
        self::assertSame('0.42%', Format::percent(0.0042));
        self::assertSame('2.1%', Format::percent(0.021, 1));
        self::assertSame('0%', Format::percent(0.0));
    }

    public function testChangesCarryTheirSign(): void
    {
        self::assertSame('+6.2%', Format::change(0.062));
        self::assertSame('−12.5%', Format::change(-0.125));
        self::assertSame('×806', Format::change(805.0));
        self::assertSame('±0', Format::signed(0.04));
        self::assertSame('+0.09', Format::signed(0.091, 2));
    }

    public function testSparklinesSpanTheBoxWithTheHighestValueOnTop(): void
    {
        self::assertSame('0,25 60,3 120,14', Format::sparkline([0.0, 10.0, 5.0]));
        self::assertSame('0,14 120,14', Format::sparkline([4.0, 4.0]));
        self::assertSame('', Format::sparkline([]));
    }
}
