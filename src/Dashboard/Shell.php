<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard;

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Tollbooth\Core\Health\HealthCheckInterface;
use Tollbooth\Dashboard\Changes\ContractChanges;
use Tollbooth\Dashboard\Models\ModelCatalogFactory;

/**
 * @internal
 */
final readonly class Shell
{
    public function __construct(
        private GroupCatalog $groups,
        private UrlGeneratorInterface $urls,
        private string $environment,
        private string $version,
        private ?HealthCheckInterface $collector = null,
        private ?string $docsRoute = null,
        private ?ModelCatalogFactory $models = null,
        private bool $requests = false,
        private ?ContractChanges $changes = null,
        private bool $health = false,
        private ?CacheInterface $cache = null,
        private string $contractHash = '',
    ) {
    }

    private function modelCount(string $group): ?int
    {
        $models = $this->models;
        if (null === $models) {
            return null;
        }
        $count = static fn (): ?int => $models->catalog($group)?->count();
        if (null === $this->cache) {
            return $count();
        }

        $value = $this->cache->get('tollbooth_dashboard_models_'.hash('xxh128', $group.'|'.$this->contractHash), $count);

        return \is_int($value) ? $value : null;
    }

    /**
     * @return array{status: string, summary: string}|null
     */
    private function collector(): ?array
    {
        $collector = $this->collector;
        if (null === $collector) {
            return null;
        }
        $check = static function (?ItemInterface $item = null) use ($collector): array {
            $item?->expiresAfter(15);
            $result = $collector->check();

            return ['status' => $result->status->value, 'summary' => $result->summary];
        };
        if (null === $this->cache) {
            return $check();
        }

        return $this->cache->get('tollbooth_dashboard_collector', $check);
    }

    private function breaking(string $group): ?int
    {
        return $this->changes?->breaking($group);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(string $group, string $screen): array
    {
        return [
            'shell' => [
                'screen' => $screen,
                'group' => $group,
                'mount' => $this->groups->mount($group),
                'groups' => array_map(fn (string $name): array => [
                    'name' => $name,
                    'mount' => $this->groups->mount($name),
                    'url' => $this->urls->generate(DashboardRouteProvider::MAP, ['group' => $name]),
                    'endpoints' => \count($this->groups->endpoints($name)),
                ], $this->groups->names()),
                'endpoints' => \count($this->groups->endpoints($group)),
                'models' => $this->modelCount($group),
                'requests' => $this->requests,
                'changes' => $this->breaking($group),
                'health' => $this->health,
                'environment' => $this->environment,
                'version' => $this->version,
                'symfony' => Kernel::VERSION,
                'collector' => $this->collector(),
                'docs' => null === $this->docsRoute ? null : $this->urls->generate($this->docsRoute, ['group' => $group]),
            ],
        ];
    }
}
