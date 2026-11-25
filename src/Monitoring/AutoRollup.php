<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * @internal
 */
final readonly class AutoRollup
{
    private const string ROLLUP_KEY = 'tollbooth_monitoring_rollup';
    private const string PURGE_KEY = 'tollbooth_monitoring_purge';

    public function __construct(
        private RollupInterface $storage,
        private CacheItemPoolInterface $cache,
        private int $callsRetentionDays = 7,
        private int $aggregatesRetentionDays = 30,
        private ?LockFactory $locks = null,
    ) {
    }

    public function maybeRun(int $now): void
    {
        $this->whenDue(self::ROLLUP_KEY, 60, $now, fn () => $this->storage->rollup($now));
        $this->whenDue(self::PURGE_KEY, 3600, $now, fn () => $this->storage->purge(
            $now - $this->callsRetentionDays * 86400,
            $now - $this->aggregatesRetentionDays * 86400,
        ));
    }

    private function whenDue(string $key, int $interval, int $now, \Closure $task): void
    {
        $item = $this->cache->getItem($key);
        $last = $item->isHit() ? $item->get() : null;
        if (\is_int($last) && $now - $last < $interval) {
            return;
        }

        $lock = $this->locks?->createLock($key, 300.0);
        if (null !== $lock && !$lock->acquire()) {
            return;
        }

        try {
            $task();
            $this->cache->save($item->set($now)->expiresAfter($interval * 10));
        } finally {
            $lock?->release();
        }
    }
}
