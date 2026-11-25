<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

/**
 * @internal
 *
 * @phpstan-type Box array{x: int, y: int, width: int, height: int}
 * @phpstan-type Edge array{from: string, to: string, points: list<array{int, int}>, path: string, label: string, labelX: int, labelY: int, labelWidth: int, labelAlign: 'left'|'center'}
 */
final readonly class Diagram
{
    /**
     * @param array<string, Box> $boxes
     * @param list<Edge>         $edges
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $boxes,
        public array $edges,
    ) {
    }
}
