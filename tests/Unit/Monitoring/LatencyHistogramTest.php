<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Monitoring;

use PHPUnit\Framework\TestCase;
use Tollbooth\Monitoring\LatencyHistogram;

final class LatencyHistogramTest extends TestCase
{
    public function testDurationsFallInTheirBucket(): void
    {
        self::assertSame(0, LatencyHistogram::bucket(3.0));
        self::assertSame(4, LatencyHistogram::bucket(100.0));
        self::assertSame(5, LatencyHistogram::bucket(100.1));
        self::assertSame(10, LatencyHistogram::bucket(9000.0));
    }

    public function testPercentileIsInterpolatedInsideItsBucket(): void
    {
        $counts = array_values(array_replace(array_fill(0, LatencyHistogram::buckets(), 0), [4 => 90, 5 => 10]));

        self::assertSame(100.0, LatencyHistogram::percentile($counts, 0.9, 240.0));
        self::assertSame(170.0, LatencyHistogram::percentile($counts, 0.95, 240.0));
        self::assertSame(240.0, LatencyHistogram::percentile($counts, 1.0, 240.0));
    }

    public function testNoCallsMeansNoPercentile(): void
    {
        self::assertNull(LatencyHistogram::percentile(array_fill(0, LatencyHistogram::buckets(), 0), 0.95, 0.0));
    }
}
