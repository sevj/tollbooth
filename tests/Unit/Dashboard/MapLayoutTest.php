<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tollbooth\Dashboard\Map\MapLayout;
use Tollbooth\Dashboard\Map\Reference;

final class MapLayoutTest extends TestCase
{
    public function testBlocksFillRowsOfThreeAndRowsTakeTheirTallestBlock(): void
    {
        $diagram = MapLayout::arrange(['orders' => 6, 'products' => 5, 'carts' => 2, 'payments' => 3], []);

        self::assertSame(['x' => 0, 'y' => 0, 'width' => 296, 'height' => 236], $diagram->boxes['orders']);
        self::assertSame(['x' => 384, 'y' => 0, 'width' => 296, 'height' => 206], $diagram->boxes['products']);
        self::assertSame(['x' => 768, 'y' => 0, 'width' => 296, 'height' => 116], $diagram->boxes['carts']);
        self::assertSame(['x' => 0, 'y' => 336, 'width' => 296, 'height' => 146], $diagram->boxes['payments']);
        self::assertSame([1064, 482], [$diagram->width, $diagram->height]);
    }

    public function testLinkedResourcesAreMovedNextToEachOther(): void
    {
        $diagram = MapLayout::arrange(
            ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1],
            [new Reference('a', 'c', 'owner')],
        );

        $a = $diagram->boxes['a'];
        $d = $diagram->boxes['c'];
        self::assertSame(1, (int) (abs($a['x'] - $d['x']) / 384) + (int) (abs($a['y'] - $d['y']) / 186));
        self::assertCount(2, $diagram->edges[0]['points']);
        self::assertSame('owner', $diagram->edges[0]['label']);
    }

    public function testNeighboursInARowAreJoinedByAStraightLine(): void
    {
        $diagram = MapLayout::arrange(['users' => 6, 'orders' => 6], [new Reference('orders', 'users', 'customer')]);

        self::assertSame([[296, 80], [384, 80]], $diagram->edges[0]['points']);
        self::assertSame('M296 80 L384 80', $diagram->edges[0]['path']);
        self::assertSame([308, 56, 64, 'center'], [$diagram->edges[0]['labelX'], $diagram->edges[0]['labelY'], $diagram->edges[0]['labelWidth'], $diagram->edges[0]['labelAlign']]);
    }

    public function testDistantResourcesAreRoutedThroughTheGutters(): void
    {
        $rows = ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1];
        $references = [
            new Reference('a', 'b', 'x'),
            new Reference('b', 'c', 'x'),
            new Reference('a', 'd', 'x'),
            new Reference('b', 'e', 'x'),
            new Reference('c', 'f', 'x'),
            new Reference('d', 'e', 'x'),
            new Reference('e', 'f', 'x'),
            new Reference('a', 'f', 'far'),
        ];
        $diagram = MapLayout::arrange($rows, $references);

        $far = array_values(array_filter($diagram->edges, static fn (array $edge): bool => 'far' === $edge['label']))[0];
        self::assertCount(6, $far['points']);
        foreach ($far['points'] as [$x, $y]) {
            foreach ($diagram->boxes as $box) {
                $inside = $x > $box['x'] && $x < $box['x'] + $box['width'] && $y > $box['y'] && $y < $box['y'] + $box['height'];
                self::assertFalse($inside, 'A bend of the link lies inside a block.');
            }
        }
    }

    public function testAnEmptyMapHasNoSize(): void
    {
        $diagram = MapLayout::arrange([], []);

        self::assertSame([0, 0, [], []], [$diagram->width, $diagram->height, $diagram->boxes, $diagram->edges]);
    }
}
