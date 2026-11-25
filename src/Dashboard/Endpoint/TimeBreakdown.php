<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

use Tollbooth\Monitoring\CallRecord;

/**
 * @internal
 */
final readonly class TimeBreakdown
{
    public const array STEPS = ['kernel', 'auth', 'rate_limit', 'hydrate', 'handler', 'serialize'];

    /**
     * @param array<string, float> $segments
     */
    public function __construct(
        public array $segments,
        public float $total,
        public int $sample,
    ) {
    }

    /**
     * @param list<CallRecord> $calls
     */
    public static function aroundP95(array $calls): ?self
    {
        $calls = array_values(array_filter($calls, static fn (CallRecord $call): bool => $call->status < 400 && [] !== $call->steps));
        $count = \count($calls);
        if ($count < 5) {
            return null;
        }

        usort($calls, static fn (CallRecord $a, CallRecord $b): int => $a->duration <=> $b->duration);
        $from = min($count - 1, (int) floor($count * 0.9));
        $to = max($from, min($count - 1, (int) ceil($count * 0.99) - 1));
        $around = \array_slice($calls, $from, $to - $from + 1);

        $segments = array_fill_keys(self::STEPS, 0.0);
        $total = 0.0;
        foreach ($around as $call) {
            $total += $call->duration;
            $measured = 0.0;
            foreach ($call->steps as $step => $duration) {
                if (isset($segments[$step])) {
                    $segments[$step] += $duration;
                    $measured += $duration;
                }
            }
            $segments['kernel'] += max(0.0, $call->duration - $measured);
        }

        $size = \count($around);

        return new self(
            array_map(static fn (float $sum): float => round($sum / $size, 1), $segments),
            round($total / $size, 1),
            $count,
        );
    }
}
