<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Models;

/**
 * @internal
 *
 * @phpstan-type Card array{model: Model, x: int, y: int, width: int, height: int, rows: list<array{name: string, type: string, ref: bool, nullable: bool}>, more: int, selected: bool}
 * @phpstan-type Link array{path: string, label: string, labelX: int, labelY: int, direct: bool}
 */
final readonly class ModelDiagram
{
    public const int CARD_WIDTH = 220;
    public const int SELECTED_WIDTH = 240;
    public const int GAP_X = 90;
    public const int GAP_Y = 40;
    public const int HEADER = 40;
    public const int ROW = 26;
    public const int MAX_ROWS = 12;
    private const int PADDING = 8;

    /**
     * @param array<string, Card> $cards
     * @param list<Link>          $links
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $cards,
        public array $links,
    ) {
    }

    public static function build(ModelCatalog $catalog, string $root, ?int $depth): self
    {
        $graph = $catalog->neighbourhood($root, $depth);
        $columns = [$root => 0];
        $order = [$root];
        $side = 1;
        foreach ($graph as $name => $references) {
            foreach ($references as $reference) {
                if (isset($columns[$reference])) {
                    continue;
                }
                if ($name === $root) {
                    $columns[$reference] = $side;
                    $side = -$side;
                } else {
                    $parent = $columns[$name];
                    $columns[$reference] = $parent + ($parent >= 0 ? 1 : -1);
                }
                $order[] = $reference;
            }
        }

        $min = min($columns);
        $max = max($columns);
        $lefts = [];
        $x = 0;
        for ($column = $min; $column <= $max; ++$column) {
            $lefts[$column] = $x;
            $x += (0 === $column ? self::SELECTED_WIDTH : self::CARD_WIDTH) + self::GAP_X;
        }
        $width = $x - self::GAP_X;

        $tops = [];
        $cards = [];
        foreach ($order as $name) {
            $model = $catalog->get($name);
            if (null === $model) {
                continue;
            }
            $column = $columns[$name];
            [$rows, $more] = self::rows($model);
            $height = self::HEADER + self::ROW * (\count($rows) + ($more > 0 ? 1 : 0)) + self::PADDING;
            $y = $tops[$column] ?? 0;
            $tops[$column] = $y + $height + self::GAP_Y;
            $cards[$name] = [
                'model' => $model,
                'x' => $lefts[$column] ?? 0,
                'y' => $y,
                'width' => 0 === $column ? self::SELECTED_WIDTH : self::CARD_WIDTH,
                'height' => $height,
                'rows' => $rows,
                'more' => $more,
                'selected' => $name === $root,
            ];
        }

        $links = [];
        foreach ($graph as $name => $references) {
            if (!isset($cards[$name])) {
                continue;
            }
            foreach ($cards[$name]['model']->fields as $index => $field) {
                if (null === $field->ref || !\in_array($field->ref, $references, true) || !isset($cards[$field->ref]) || $field->ref === $name) {
                    continue;
                }
                $link = self::link($cards[$name], $cards[$field->ref], min($index, self::MAX_ROWS - 1), $field->label(), $name === $root, $columns[$name], $columns[$field->ref]);
                if (null !== $link) {
                    $links[] = $link;
                }
            }
        }

        $height = 0;
        foreach ($cards as $card) {
            $height = max($height, $card['y'] + $card['height']);
        }

        return new self($width, $height, $cards, $links);
    }

    /**
     * @return array{list<array{name: string, type: string, ref: bool, nullable: bool}>, int}
     */
    private static function rows(Model $model): array
    {
        if (Model::ENUM === $model->kind) {
            $values = array_map(static fn (int|string|null $value): array => ['name' => \is_string($value) ? '"'.$value.'"' : (string) $value, 'type' => '', 'ref' => false, 'nullable' => false], $model->values);
        } else {
            $values = array_map(static fn (ModelField $field): array => ['name' => $field->name, 'type' => ltrim($field->type, '?'), 'ref' => null !== $field->ref, 'nullable' => $field->nullable], $model->fields);
        }

        $shown = \array_slice($values, 0, self::MAX_ROWS);

        return [$shown, \count($values) - \count($shown)];
    }

    /**
     * @param Card $from
     * @param Card $to
     *
     * @return Link|null
     */
    private static function link(array $from, array $to, int $row, string $label, bool $direct, int $fromColumn, int $toColumn): ?array
    {
        if (1 !== abs($fromColumn - $toColumn)) {
            return null;
        }

        $rightward = $toColumn > $fromColumn;
        $startX = $rightward ? $from['x'] + $from['width'] : $from['x'];
        $endX = $rightward ? $to['x'] : $to['x'] + $to['width'];
        $startY = $from['y'] + self::HEADER + self::ROW * $row + intdiv(self::ROW, 2);
        $endY = $to['y'] + intdiv(self::HEADER, 2);
        $middle = intdiv($startX + $endX, 2);
        $path = $startY === $endY
            ? \sprintf('M%d %d H%d', $startX, $startY, $endX)
            : \sprintf('M%d %d H%d V%d H%d', $startX, $startY, $middle, $endY, $endX);

        return [
            'path' => $path,
            'label' => $label,
            'labelX' => $middle,
            'labelY' => $endY - 6,
            'direct' => $direct,
        ];
    }
}
