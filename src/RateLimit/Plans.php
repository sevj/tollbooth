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
 * @phpstan-import-type PlanSet from DoctrinePlanStore
 */
final class Plans implements ResetInterface
{
    public const string NAME_PATTERN = '~^[a-z0-9][a-z0-9_-]{0,63}$~';
    private const string CACHE_KEY = 'tollbooth.rate_limit.plans';
    private const int CACHE_SECONDS = 60;

    /**
     * @var PlanSet|null
     */
    private ?array $set = null;

    /**
     * @var array<string, list<Limit>>|null
     */
    private ?array $limits = null;

    /**
     * @param array<string, list<LimitArray>> $declared plans of the configuration: the only ones without a table, the seed and the fallback with one
     */
    public function __construct(
        private readonly array $declared = [],
        private readonly ?string $declaredDefault = null,
        private readonly ?string $declaredAnonymous = null,
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
        return $this->set()['plans'];
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

    public function defaultPlan(): ?string
    {
        return $this->set()['default'];
    }

    public function anonymousPlan(): ?string
    {
        return $this->set()['anonymous'] ?? $this->defaultPlan();
    }

    public function editable(): bool
    {
        return null !== $this->store;
    }

    /**
     * @param list<Limit> $limits
     */
    public function save(string $name, array $limits, bool $default = false, bool $anonymous = false): void
    {
        $this->store()->save($name, $limits, $default, $anonymous);
        $this->forget();
    }

    public function remove(string $name): void
    {
        $this->store()->remove($name);
        $this->forget();
    }

    /**
     * @return list<string> the declared plans missing from the table
     */
    public function missing(): array
    {
        return array_values(array_diff(array_keys($this->declared), array_keys($this->store()->read()['plans'])));
    }

    /**
     * Copies the declared plans into the table: all of them into an empty table, or with $missing those it lacks.
     *
     * @return list<string> the plans copied
     */
    public function import(bool $missing = false): array
    {
        $stored = $this->store()->read();
        if ([] !== $stored['plans'] && !$missing) {
            return [];
        }

        $copied = [];
        foreach ($this->declared as $name => $limits) {
            if (isset($stored['plans'][$name])) {
                continue;
            }
            $this->store()->save(
                $name,
                array_map(Limit::fromArray(...), $limits),
                $name === $this->declaredDefault && null === $stored['default'],
                $name === $this->declaredAnonymous && null === $stored['anonymous'],
            );
            $copied[] = $name;
        }
        $this->forget();

        return $copied;
    }

    public function reset(): void
    {
        $this->set = null;
        $this->limits = null;
    }

    /**
     * @return PlanSet
     */
    private function set(): array
    {
        if (null !== $this->set) {
            return $this->set;
        }
        $declared = ['plans' => $this->declared, 'default' => $this->declaredDefault, 'anonymous' => $this->declaredAnonymous];
        if (null === $this->store) {
            return $this->set = $declared;
        }

        $item = $this->cache?->getItem(self::CACHE_KEY);
        if (null !== $item && $item->isHit() && \is_array($cached = $item->get())) {
            /** @var PlanSet $cached */
            return $this->set = $cached;
        }

        try {
            $this->set = $this->store->read();
        } catch (\Throwable $exception) {
            $this->logger?->error('Tollbooth cannot read the rate limit plans from their table; the plans declared in the configuration apply meanwhile.', ['exception' => $exception]);

            return $this->set = $declared;
        }

        if (null !== $item) {
            $this->cache->save($item->set($this->set)->expiresAfter(self::CACHE_SECONDS));
        }

        return $this->set;
    }

    private function store(): DoctrinePlanStore
    {
        return $this->store ?? throw new \LogicException('Rate limit plans are stored only when tollbooth.rate_limit.doctrine_plans is enabled.');
    }

    private function forget(): void
    {
        $this->cache?->deleteItem(self::CACHE_KEY);
        $this->reset();
    }
}
