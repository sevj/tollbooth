<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

final class LatencyHistogram
{
    public const array BOUNDS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    public static function bucket(float $duration): int
    {
        foreach (self::BOUNDS as $index => $bound) {
            if ($duration <= $bound) {
                return $index;
            }
        }

        return \count(self::BOUNDS);
    }

    public static function buckets(): int
    {
        return \count(self::BOUNDS) + 1;
    }

    /**
     * @param list<int> $counts
     */
    public static function percentile(array $counts, float $percentile, float $max): ?float
    {
        $total = array_sum($counts);
        if (0 === $total) {
            return null;
        }

        $target = $percentile * $total;
        $cumulative = 0;
        foreach ($counts as $index => $count) {
            if ($count > 0 && $cumulative + $count >= $target) {
                $lower = 0 === $index ? 0.0 : (float) self::BOUNDS[$index - 1];
                $upper = min((float) (self::BOUNDS[$index] ?? $max), max($max, $lower));

                return round($lower + ($upper - $lower) * (($target - $cumulative) / $count), 1);
            }
            $cumulative += $count;
        }

        return round($max, 1);
    }
}
