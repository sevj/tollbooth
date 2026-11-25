<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Monitoring\ErrorGroup;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class EndpointPage
{
    /**
     * @param list<PipelineStep> $steps
     * @param array<int, int>    $statuses
     * @param list<Stats>        $buckets
     * @param list<Stats>        $consumers
     * @param list<ErrorGroup>   $errors
     */
    public function __construct(
        public EndpointMetadata $endpoint,
        public string $resource,
        public ?Operation $operation,
        public ?Declaration $declaration,
        public array $steps,
        public ?Stats $stats = null,
        public array $statuses = [],
        public array $buckets = [],
        public array $consumers = [],
        public array $errors = [],
        public ?TimeBreakdown $time = null,
    ) {
    }

    /**
     * @return list<array{label: string, codes: list<int>, calls: int, share: float, tone: string}>
     */
    public function statusSlices(int $shown = 3): array
    {
        $total = array_sum($this->statuses);
        if (0 === $total) {
            return [];
        }

        $slices = [];
        $other = [];
        foreach ($this->statuses as $status => $calls) {
            if (\count($slices) < $shown) {
                $slices[] = ['label' => (string) $status, 'codes' => [$status], 'calls' => $calls, 'share' => $calls / $total, 'tone' => self::tone($status)];
            } else {
                $other[$status] = $calls;
            }
        }
        if ([] !== $other) {
            ksort($other);
            $calls = array_sum($other);
            $slices[] = ['label' => implode(' · ', array_keys($other)), 'codes' => array_keys($other), 'calls' => $calls, 'share' => $calls / $total, 'tone' => 'other'];
        }

        return $slices;
    }

    public static function tone(int $status): string
    {
        return match (true) {
            429 === $status => 'warn',
            $status >= 400 => 'bad',
            default => 'ok',
        };
    }

    /**
     * @return array{max: int, p50: string, p95: string}
     */
    public function latencyChart(int $width = 300, int $height = 150): array
    {
        $p50 = array_map(static fn (Stats $bucket): ?float => $bucket->percentile(0.5), $this->buckets);
        $p95 = array_map(static fn (Stats $bucket): ?float => $bucket->percentile(0.95), $this->buckets);
        $peak = max([1.0, ...array_filter($p95, static fn (?float $value): bool => null !== $value)]);
        $max = self::niceCeiling($peak * 1.1);

        return ['max' => $max, 'p50' => self::points($p50, $width, $height, $max), 'p95' => self::points($p95, $width, $height, $max)];
    }

    /**
     * @param list<?float> $values
     */
    private static function points(array $values, int $width, int $height, int $max): string
    {
        $count = \count($values);
        $points = [];
        foreach ($values as $index => $value) {
            if (null === $value) {
                continue;
            }
            $x = $count <= 1 ? $width : $index * $width / ($count - 1);
            $points[] = round($x, 1).','.round($height - $value / $max * $height, 1);
        }

        return implode(' ', $points);
    }

    private static function niceCeiling(float $value): int
    {
        $magnitude = 10 ** max(0, (int) floor(log10($value)));
        foreach ([1, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
            if ($step * $magnitude >= $value) {
                return (int) ($step * $magnitude);
            }
        }

        return (int) (10 * $magnitude);
    }
}
