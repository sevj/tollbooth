<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Tollbooth\Core\Metadata\Limit;

/**
 * @internal
 */
final readonly class WindowCounter
{
    private LockFactory $locks;

    public function __construct(
        private CacheItemPoolInterface $cache,
        ?LockFactory $locks = null,
        private ?ClockInterface $clock = null,
    ) {
        $this->locks = $locks ?? new LockFactory(new FlockStore());
    }

    public function hit(string $consumer, Limit $limit): LimitState
    {
        [$key, $resetIn] = $this->window($consumer, $limit);

        [$hits, $accepted] = $this->update($key, $resetIn, static fn (int $hits): ?int => $hits < $limit->limit ? $hits + 1 : null);

        return new LimitState($limit->name, $limit->limit, $limit->window, max(0, $limit->limit - $hits), $resetIn, $accepted);
    }

    public function refund(string $consumer, Limit $limit): LimitState
    {
        [$key, $resetIn] = $this->window($consumer, $limit);

        [$hits] = $this->update($key, $resetIn, static fn (int $hits): ?int => $hits > 0 ? $hits - 1 : null);

        return new LimitState($limit->name, $limit->limit, $limit->window, max(0, $limit->limit - $hits), $resetIn, true);
    }

    /**
     * @return array{string, int}
     */
    private function window(string $consumer, Limit $limit): array
    {
        $now = (float) ($this->clock?->now() ?? new \DateTimeImmutable())->format('U.u');
        $start = intdiv((int) floor($now), $limit->window) * $limit->window;

        return [
            'tollbooth_rl_'.hash('xxh128', $consumer.'|'.$limit->scope.'|'.$limit->name.'|'.$start),
            max(1, (int) ceil($start + $limit->window - $now)),
        ];
    }

    /**
     * @param \Closure(int): ?int $change
     *
     * @return array{int, bool}
     */
    private function update(string $key, int $resetIn, \Closure $change): array
    {
        $lock = $this->locks->createLock($key, 5.0);
        $lock->acquire(true);
        try {
            $item = $this->cache->getItem($key);
            $stored = $item->isHit() ? $item->get() : 0;
            $hits = \is_int($stored) ? $stored : 0;
            $next = $change($hits);
            if (null === $next) {
                return [$hits, false];
            }

            $item->set($next)->expiresAfter($resetIn + 1);
            $this->cache->save($item);

            return [$next, true];
        } finally {
            $lock->release();
        }
    }
}
