<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Twig;

/**
 * @internal
 */
final class Format
{
    private const string THIN_SPACE = "\u{202F}";

    public static function compact(int|float $value): string
    {
        $absolute = abs($value);
        $sign = $value < 0 ? '-' : '';

        return $sign.match (true) {
            $absolute >= 10_000_000 => self::trim(round($absolute / 1_000_000, 1)).'M',
            $absolute >= 999_500 => self::trim(round($absolute / 1_000_000, 2)).'M',
            $absolute >= 9_950 => round($absolute / 1000).'k',
            $absolute >= 1000 => self::trim(round($absolute / 1000, 1)).'k',
            default => (string) round($absolute),
        };
    }

    public static function number(int|float $value): string
    {
        return number_format($value, 0, '.', self::THIN_SPACE);
    }

    public static function percent(float $ratio, int $decimals = 2): string
    {
        return self::trim(round($ratio * 100, $decimals), $decimals).'%';
    }

    public static function signed(float $value, int $decimals = 0, string $suffix = ''): string
    {
        $rounded = round($value, $decimals);
        if (0.0 === $rounded) {
            return '±0'.$suffix;
        }

        return ($rounded > 0 ? '+' : '−').number_format(abs($rounded), $decimals, '.', self::THIN_SPACE).$suffix;
    }

    /**
     * @return array{unit: 'now'|'minutes'|'hours'|'days', count: int}
     */
    public static function ago(int $milliseconds, ?int $now = null): array
    {
        $seconds = max(0, ($now ?? time()) - intdiv($milliseconds, 1000));

        return match (true) {
            $seconds < 60 => ['unit' => 'now', 'count' => 0],
            $seconds < 3600 => ['unit' => 'minutes', 'count' => intdiv($seconds, 60)],
            $seconds < 86400 => ['unit' => 'hours', 'count' => intdiv($seconds, 3600)],
            default => ['unit' => 'days', 'count' => intdiv($seconds, 86400)],
        };
    }

    public static function json(string $json): string
    {
        $escaped = htmlspecialchars($json, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace_callback(
            '/("(?:[^"\\\\]|\\\\.)*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/',
            static function (array $match): string {
                $string = $match[1] ?? '';
                $colon = $match[2] ?? '';
                $literal = $match[3] ?? '';

                return match (true) {
                    '' !== $colon => '<span class="at">'.$string.'</span>'.$colon,
                    '' !== $string => '<span class="s">'.$string.'</span>',
                    '' !== $literal => '<span class="kw">'.$literal.'</span>',
                    default => '<span class="nu">'.$match[0].'</span>',
                };
            },
            $escaped,
        );
    }

    public static function change(float $ratio): string
    {
        if ($ratio >= 9.5) {
            return '×'.self::number(round($ratio + 1));
        }

        return self::signed($ratio * 100, 1, '%');
    }

    /**
     * @param list<float> $values
     */
    public static function sparkline(array $values, int $width = 120, int $height = 28, int $padding = 3): string
    {
        $count = \count($values);
        if (0 === $count) {
            return '';
        }

        $max = max($values);
        $min = min($values);
        $span = $max - $min;
        $points = [];
        foreach ($values as $index => $value) {
            $x = 1 === $count ? $width : $index * $width / ($count - 1);
            $y = 0.0 === $span ? $height / 2 : $height - $padding - ($value - $min) / $span * ($height - 2 * $padding);
            $points[] = self::trim(round($x, 1)).','.self::trim(round($y, 1));
        }

        return implode(' ', $points);
    }

    private static function trim(float $value, int $decimals = 2): string
    {
        $formatted = number_format($value, $decimals, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
