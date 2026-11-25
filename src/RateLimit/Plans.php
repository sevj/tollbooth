<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\RateLimit\Doctrine\DoctrinePlanStore;

/**
 * @internal
 *
 * @phpstan-import-type LimitArray from Limit
 */
final class Plans implements ResetInterface
{
    public const string NAME_PATTERN = '~^[a-z0-9][a-z0-9_-]{0,63}$~';
    private const string CACHE_KEY = 'tollbooth.rate_limit.plans';
    private const int CACHE_SECONDS = 60;

    /**
     * @var array<string, list<LimitArray>>|null
     */
    private ?array $stored = null;

    /**
     * @var array<string, list<Limit>>|null
     */
    private ?array $limits = null;

    /**
     * @param array<string, list<LimitArray>> $configured
     */
    public function __construct(
        private readonly array $configured = [],
        public readonly ?string $defaultPlan = null,
        public readonly ?string $anonymousPlan = null,
        private readonly ?DoctrinePlanStore $store = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, list<LimitArray>>
     */
    public function all(): array
    {
        return $this->configured + $this->stored();
    }

    /**
     * @return array<string, list<Limit>>
     */
    public function limits(): array
    {
        return $this->limits ??= array_map(
            static fn (array $limits): array => array_map(Limit::fromArray(...), $limits),
            $this->all(),
        );
    }

    public function editable(): bool
    {
        return null !== $this->store;
    }

    public function configured(string $name): bool
    {
        return isset($this->configured[$name]);
    }

    /**
     * @param list<Limit> $limits
     */
    public function save(string $name, array $limits): void
    {
        $this->store($name)->save($name, $limits);
        $this->forget();
    }

    public function remove(string $name): void
    {
        $this->store($name)->remove($name);
        $this->forget();
    }

    public function reset(): void
    {
        $this->stored = null;
        $this->limits = null;
    }

    /**
     * @return array<string, list<LimitArray>>
     */
    private function stored(): array
    {
        if (null !== $this->stored || null === $this->store) {
            return $this->stored ?? [];
        }

        $item = $this->cache?->getItem(self::CACHE_KEY);
        if (null !== $item && $item->isHit() && \is_array($cached = $item->get())) {
            /** @var array<string, list<LimitArray>> $cached */
            return $this->stored = $cached;
        }

        try {
            $this->stored = $this->store->all();
        } catch (\Throwable $exception) {
            $this->logger?->error('Tollbooth cannot read the stored rate limit plans; only the configured plans apply.', ['exception' => $exception]);

            return $this->stored = [];
        }

        if (null !== $item) {
            $this->cache->save($item->set($this->stored)->expiresAfter(self::CACHE_SECONDS));
        }

        return $this->stored;
    }

    private function store(string $name): DoctrinePlanStore
    {
        if (null === $this->store) {
            throw new \LogicException('Rate limit plans are stored only when tollbooth.rate_limit.doctrine_plans is enabled.');
        }
        if ($this->configured($name)) {
            throw new \LogicException(\sprintf('The plan "%s" is declared in the configuration and cannot be changed from here.', $name));
        }

        return $this->store;
    }

    private function forget(): void
    {
        $this->cache?->deleteItem(self::CACHE_KEY);
        $this->reset();
    }
}
