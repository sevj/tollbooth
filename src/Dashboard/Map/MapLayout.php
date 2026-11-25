<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

/**
 * @internal
 *
 * @phpstan-import-type Box from Diagram
 * @phpstan-import-type Edge from Diagram
 */
final class MapLayout
{
    public const int BLOCK_WIDTH = 296;
    public const int GAP_X = 88;
    public const int GAP_Y = 100;
    public const int HEADER = 76;
    public const int ROW = 30;
    private const int LINK_Y = 100;
    private const int LANE = 48;
    private const int SPREAD = 8;
    private const int ROW_CHROME = 252;
    private const float CHARACTER = 7.2;

    public static function blockWidth(int $characters): int
    {
        return max(self::BLOCK_WIDTH, self::ROW_CHROME + (int) ceil($characters * self::CHARACTER));
    }

    /**
     * @param array<string, int> $rows       endpoint rows of each resource, most important first
     * @param list<Reference>    $references
     *
     * @return list<Diagram> the widest layout first, then narrower ones down to two columns
     */
    public static function layouts(array $rows, array $references, int $blockWidth = self::BLOCK_WIDTH): array
    {
        $layouts = [];
        for ($columns = min(3, \count($rows)); $columns >= min(2, \count($rows)); --$columns) {
            $layouts[] = self::arrange($rows, $references, $columns, $blockWidth);
        }

        return $layouts;
    }

    /**
     * @param array<string, int> $rows       endpoint rows of each resource, most important first
     * @param list<Reference>    $references
     */
    public static function arrange(array $rows, array $references, int $columns = 3, int $blockWidth = self::BLOCK_WIDTH): Diagram
    {
        $names = array_keys($rows);
        if ([] === $names) {
            return new Diagram(0, 0, [], []);
        }

        $columns = max(1, min($columns, \count($names)));
        $references = array_values(array_filter(
            $references,
            static fn (Reference $reference): bool => $reference->from !== $reference->to && isset($rows[$reference->from], $rows[$reference->to]),
        ));
        $order = self::place($names, $references, $columns);

        $cells = [];
        foreach ($order as $index => $name) {
            $cells[$name] = [intdiv($index, $columns), $index % $columns];
        }

        $rowHeights = [];
        foreach ($cells as $name => [$row]) {
            $rowHeights[$row] = max($rowHeights[$row] ?? 0, self::height($rows[$name]));
        }
        $rowTops = [];
        $top = 0;
        foreach ($rowHeights as $row => $height) {
            $rowTops[$row] = $top;
            $top += $height + self::GAP_Y;
        }

        $boxes = [];
        foreach ($cells as $name => [$row, $column]) {
            $boxes[$name] = ['x' => $column * ($blockWidth + self::GAP_X), 'y' => $rowTops[$row], 'width' => $blockWidth, 'height' => self::height($rows[$name])];
        }

        $width = $columns * $blockWidth + ($columns - 1) * self::GAP_X;
        $height = $top - self::GAP_Y;
        $edges = [];
        $routed = 0;
        foreach ($references as $reference) {
            [$from, $to] = [$reference->from, $reference->to];
            if ($cells[$to] < $cells[$from]) {
                [$from, $to] = [$to, $from];
            }
            $edge = self::edge($reference, $from, $to, $boxes, $cells, $rowTops, $rowHeights, $routed, $width);
            $edges[] = $edge;
            foreach ($edge['points'] as [$x, $y]) {
                $width = max($width, $x + self::LANE / 2);
                $height = max($height, $y + self::LANE / 2);
            }
        }

        return new Diagram($width, $height, $boxes, $edges);
    }

    public static function height(int $rows): int
    {
        return self::HEADER + self::ROW * $rows;
    }

    /**
     * @param list<string>    $names
     * @param list<Reference> $references
     *
     * @return list<string>
     */
    private static function place(array $names, array $references, int $columns): array
    {
        $order = $names;
        $cost = self::cost($order, $references, $columns);
        for ($pass = 0; $pass < 20 && $cost > 0; ++$pass) {
            $improved = false;
            $count = \count($order);
            for ($i = 0; $i < $count - 1; ++$i) {
                for ($j = $i + 1; $j < $count; ++$j) {
                    $candidate = $order;
                    [$candidate[$i], $candidate[$j]] = [$candidate[$j], $candidate[$i]];
                    $candidateCost = self::cost($candidate, $references, $columns);
                    if ($candidateCost < $cost) {
                        [$order, $cost, $improved] = [$candidate, $candidateCost, true];
                    }
                }
            }
            if (!$improved) {
                break;
            }
        }

        return array_values($order);
    }

    /**
     * @param array<int, string> $order
     * @param list<Reference>    $references
     */
    private static function cost(array $order, array $references, int $columns): int
    {
        $positions = array_flip($order);
        $cost = 0;
        foreach ($references as $reference) {
            $a = $positions[$reference->from];
            $b = $positions[$reference->to];
            $rows = abs(intdiv($a, $columns) - intdiv($b, $columns));
            $cols = abs($a % $columns - $b % $columns);
            $distance = $rows + $cols;
            $cost += 1 === $distance ? 0 : 2 * $distance + (0 === $rows ? 1 : 0);
        }

        return $cost;
    }

    /**
     * @param array<string, Box>             $boxes
     * @param array<string, array{int, int}> $cells
     * @param array<int, int>                $rowTops
     * @param array<int, int>                $rowHeights
     *
     * @return Edge
     */
    private static function edge(Reference $reference, string $from, string $to, array $boxes, array $cells, array $rowTops, array $rowHeights, int &$routed, int $gridWidth): array
    {
        $a = $boxes[$from];
        $b = $boxes[$to];
        [$rowA, $colA] = $cells[$from];
        [$rowB, $colB] = $cells[$to];

        if ($rowA === $rowB && $colB === $colA + 1) {
            $y = $a['y'] + min(self::LINK_Y, min($a['height'], $b['height']) - 16);
            $points = [[$a['x'] + $a['width'], $y], [$b['x'], $y]];

            return self::make($reference, $points, $a['x'] + $a['width'] + 12, $y - 24, self::GAP_X - 24, 'center');
        }

        if ($colA === $colB && $rowB === $rowA + 1) {
            $x = $a['x'] + intdiv($a['width'], 2);
            $points = [[$x, $a['y'] + $a['height']], [$x, $b['y']]];

            return self::make($reference, $points, $x + 12, intdiv($a['y'] + $a['height'] + $b['y'], 2) - 8, self::GAP_X + 40, 'left');
        }

        $offset = ($routed++ % 4) * self::SPREAD;
        $yA = $a['y'] + min(self::LINK_Y, $a['height'] - 16) + $offset;
        $yB = $b['y'] + min(self::LINK_Y, $b['height'] - 16) + $offset;

        if ($colB > $colA) {
            [$exitA, $gutterA] = [$a['x'] + $a['width'], $a['x'] + $a['width'] + intdiv(self::GAP_X, 2) - 12 + $offset];
            [$enterB, $gutterB] = [$b['x'], $b['x'] - intdiv(self::GAP_X, 2) - 12 + $offset];
        } elseif ($colB < $colA) {
            [$exitA, $gutterA] = [$a['x'], $a['x'] - intdiv(self::GAP_X, 2) + 12 - $offset];
            [$enterB, $gutterB] = [$b['x'] + $b['width'], $b['x'] + $b['width'] + intdiv(self::GAP_X, 2) + 12 - $offset];
        } else {
            $lane = $a['x'] + $a['width'] >= $gridWidth ? intdiv(self::LANE, 2) : intdiv(self::GAP_X, 2) - 12;
            [$exitA, $gutterA] = [$a['x'] + $a['width'], $a['x'] + $a['width'] + $lane + $offset];
            [$enterB, $gutterB] = [$b['x'] + $b['width'], $gutterA];
        }

        if ($gutterA === $gutterB) {
            $points = [[$exitA, $yA], [$gutterA, $yA], [$gutterA, $yB], [$enterB, $yB]];

            return self::make($reference, $points, $gutterA + 8, intdiv($yA + $yB, 2) - 8, self::GAP_X + 40, 'left');
        }

        $laneY = $rowA === $rowB
            ? $rowTops[$rowA] + $rowHeights[$rowA] + intdiv(self::GAP_Y, 2) + $offset
            : $rowTops[$rowB] - intdiv(self::GAP_Y, 2) + $offset;
        $points = [[$exitA, $yA], [$gutterA, $yA], [$gutterA, $laneY], [$gutterB, $laneY], [$gutterB, $yB], [$enterB, $yB]];
        $left = min($gutterA, $gutterB);

        return self::make($reference, $points, $left + 12, $laneY - 20, max(72, abs($gutterB - $gutterA) - 24), 'center');
    }

    /**
     * @param list<array{int, int}> $points
     * @param 'left'|'center'       $align
     *
     * @return Edge
     */
    private static function make(Reference $reference, array $points, int $labelX, int $labelY, int $labelWidth, string $align): array
    {
        return [
            'from' => $reference->from,
            'to' => $reference->to,
            'points' => $points,
            'path' => 'M'.implode(' L', array_map(static fn (array $point): string => $point[0].' '.$point[1], $points)),
            'label' => $reference->label,
            'labelX' => $labelX,
            'labelY' => $labelY,
            'labelWidth' => $labelWidth,
            'labelAlign' => $align,
        ];
    }
}
