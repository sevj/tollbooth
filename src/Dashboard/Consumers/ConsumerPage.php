<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Consumers;

use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Monitoring\Stats;

/**
 * @internal
 */
final readonly class ConsumerPage
{
    /**
     * @param list<array{endpoint: ?EndpointMetadata, stats: Stats}>                                    $endpoints
     * @param list<int>                                                                                 $cumulative
     * @param list<array{calls: int, throttled: int}>                                                   $minutes
     * @param list<array{kind: string, element: string, message: ?string, sunset: ?string, calls: int}> $upcoming
     */
    public function __construct(
        public ConsumerRow $consumer,
        public array $endpoints,
        public array $cumulative,
        public ?int $exhaustedAt,
        public array $minutes,
        public array $upcoming,
        public int $now,
    ) {
    }

    /**
     * @return array{max: int, line: string, cap: ?float, projection: ?string, exhaustedX: ?float}
     */
    public function quotaChart(int $width = 300, int $height = 150): array
    {
        $cap = $this->consumer->dayLimit;
        $last = [] === $this->cumulative ? 0 : $this->cumulative[\count($this->cumulative) - 1];
        $max = max(1, $cap ?? 0, $last);
        $max = (int) ceil($max * 1.08);
        $step = $width / 96;
        $y = static fn (float $value): float => round($height - $value / $max * $height, 1);

        $points = ['0,'.$height];
        foreach ($this->cumulative as $index => $value) {
            $points[] = round(($index + 1) * $step, 1).','.$y($value);
        }

        $projection = null;
        $exhaustedX = null;
        if (null !== $this->exhaustedAt && null !== $cap) {
            $dayStart = ConsumerDirectory::startOfDay($this->now);
            $exhaustedX = round(($this->exhaustedAt - $dayStart) / 86400 * $width, 1);
            $projection = \sprintf('M%s %s L%s %s', round(\count($this->cumulative) * $step, 1), $y($last), $exhaustedX, $y($cap));
        }

        return ['max' => $max, 'line' => implode(' ', $points), 'cap' => null === $cap ? null : $y($cap), 'projection' => $projection, 'exhaustedX' => $exhaustedX];
    }

    /**
     * @return array{bars: list<array{x: float, y: float, height: float, over: bool}>, limit: ?float, max: int, width: float}
     */
    public function minuteChart(int $width = 300, int $height = 120): array
    {
        $limit = $this->consumer->minuteLimit;
        $max = max(1, (int) ceil(max([0, $limit ?? 0, ...array_map(static fn (array $minute): int => $minute['calls'], $this->minutes)]) * 1.1));
        $count = max(1, \count($this->minutes));
        $slot = $width / $count;
        $bars = [];
        foreach ($this->minutes as $index => $minute) {
            $barHeight = round($minute['calls'] / $max * $height, 1);
            $bars[] = ['x' => round($index * $slot, 1), 'y' => round($height - $barHeight, 1), 'height' => $barHeight, 'over' => $minute['throttled'] > 0];
        }

        return ['bars' => $bars, 'limit' => null === $limit ? null : round($height - $limit / $max * $height, 1), 'max' => $max, 'width' => round(max(1.0, $slot - 2), 1)];
    }

    public function throttledMinutes(): int
    {
        return \count(array_filter($this->minutes, static fn (array $minute): bool => $minute['throttled'] > 0));
    }
}
