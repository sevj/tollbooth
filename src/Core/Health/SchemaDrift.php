<?php

declare(strict_types=1);

namespace Tollbooth\Core\Health;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @internal
 */
final readonly class SchemaDrift
{
    private const int CACHE_SECONDS = 300;

    /**
     * @param \Closure(): list<string> $pendingSql
     */
    public static function pending(string $table, \Closure $pendingSql, ?CacheInterface $cache = null): bool
    {
        $count = static function () use ($pendingSql): int {
            try {
                return \count($pendingSql());
            } catch (\Throwable) {
                return 0;
            }
        };
        if (null === $cache) {
            return $count() > 0;
        }

        return $cache->get('tollbooth_schema_drift_'.$table, static function (ItemInterface $item) use ($count): int {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $count();
        }) > 0;
    }
}
