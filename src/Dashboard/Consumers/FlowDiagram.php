<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Consumers;

/**
 * @internal
 *
 * @phpstan-type Node array{label: string, id: ?string, share: float, y: float, height: float, labelY: float, color: string}
 * @phpstan-type Flow array{path: string, color: string, title: string}
 */
final readonly class FlowDiagram
{
    public const int HEIGHT = 320;
    public const int GAP = 10;
    public const int LEFT = 12;
    public const int RIGHT = 628;
    private const array COLORS = ['#F0B429', '#C3A8FF', '#8FD69C', '#FF8A7A', '#8A8F96'];

    /**
     * @param list<Node> $sources
     * @param list<Node> $targets
     * @param list<Flow> $flows
     */
    public function __construct(
        public array $sources,
        public array $targets,
        public array $flows,
        public int $total,
    ) {
    }

    /**
     * @param array<string, array<string, int>> $calls
     * @param array<string, string>             $ids   consumer id of each source label
     */
    public static function build(array $calls, array $ids = []): self
    {
        $sourceTotals = array_map('array_sum', $calls);
        $targetTotals = [];
        foreach ($calls as $targets) {
            foreach ($targets as $target => $count) {
                $targetTotals[$target] = ($targetTotals[$target] ?? 0) + $count;
            }
        }
        arsort($targetTotals);
        $total = array_sum($sourceTotals);
        if (0 === $total) {
            return new self([], [], [], 0);
        }

        $scale = (self::HEIGHT - self::GAP * (max(\count($sourceTotals), \count($targetTotals)) - 1)) / $total;
        $sources = self::nodes($sourceTotals, $total, $scale, true, $ids);
        $targets = self::nodes($targetTotals, $total, $scale, false);

        $targetOffsets = [];
        foreach ($targets as $label => $node) {
            $targetOffsets[$label] = $node['y'];
        }

        $flows = [];
        $order = array_keys($targetTotals);
        foreach ($calls as $source => $targetCalls) {
            $y0 = $sources[$source]['y'];
            foreach ($order as $target) {
                $count = $targetCalls[$target] ?? 0;
                if (0 === $count) {
                    continue;
                }
                $height = $count * $scale;
                $y1 = $targetOffsets[$target];
                $middle = (self::LEFT + self::RIGHT) / 2;
                $flows[] = [
                    'path' => \sprintf(
                        'M%d,%s C%s,%s %s,%s %d,%s L%d,%s C%s,%s %s,%s %d,%sZ',
                        self::LEFT, self::n($y0), self::n($middle), self::n($y0), self::n($middle), self::n($y1), self::RIGHT, self::n($y1),
                        self::RIGHT, self::n($y1 + $height), self::n($middle), self::n($y1 + $height), self::n($middle), self::n($y0 + $height), self::LEFT, self::n($y0 + $height),
                    ),
                    'color' => $sources[$source]['color'],
                    'title' => $source.' → '.$target.' · '.$count,
                ];
                $y0 += $height;
                $targetOffsets[$target] += $height;
            }
        }

        return new self(array_values($sources), array_values($targets), $flows, $total);
    }

    /**
     * @param array<string, int>    $totals
     * @param array<string, string> $ids
     *
     * @return array<string, Node>
     */
    private static function nodes(array $totals, int $total, float $scale, bool $colored, array $ids = []): array
    {
        $nodes = [];
        $y = 0.0;
        $index = 0;
        $labelY = -\INF;
        foreach ($totals as $label => $value) {
            $height = $value * $scale;
            $labelY = max($y + $height / 2 + 4, $labelY + 15);
            $nodes[(string) $label] = [
                'label' => (string) $label,
                'id' => $ids[(string) $label] ?? null,
                'share' => $value / $total,
                'y' => $y,
                'height' => $height,
                'labelY' => round($labelY, 1),
                'color' => $colored ? self::COLORS[min($index, \count(self::COLORS) - 1)] : '#C9C5BC',
            ];
            $y += $height + self::GAP;
            ++$index;
        }

        return $nodes;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
