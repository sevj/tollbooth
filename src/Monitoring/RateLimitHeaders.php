<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

/**
 * @internal
 */
final class RateLimitHeaders
{
    /**
     * @return list<array{name: string, limit: int, window: int, remaining: int, reset: int}>|null
     */
    public static function parse(?string $policy, ?string $state): ?array
    {
        if (null === $policy || null === $state || '' === trim($policy)) {
            return null;
        }

        $states = self::items($state);
        $limiters = [];
        foreach (self::items($policy) as $name => $parameters) {
            $limiters[] = [
                'name' => $name,
                'limit' => $parameters['q'] ?? 0,
                'window' => $parameters['w'] ?? 0,
                'remaining' => $states[$name]['r'] ?? 0,
                'reset' => $states[$name]['t'] ?? 0,
            ];
        }

        return [] === $limiters ? null : $limiters;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private static function items(string $header): array
    {
        $items = [];
        foreach (explode(',', $header) as $member) {
            $parts = array_map('trim', explode(';', $member));
            $name = trim(array_shift($parts), '"');
            if ('' === $name) {
                continue;
            }
            $parameters = [];
            foreach ($parts as $part) {
                [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
                if (is_numeric($value)) {
                    $parameters[trim($key)] = (int) $value;
                }
            }
            $items[$name] = $parameters;
        }

        return $items;
    }
}
