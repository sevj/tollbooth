<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard;

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tollbooth\Core\Health\HealthCheckInterface;
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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function context(string $group, string $screen): array
    {
        $collector = $this->collector?->check();

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
                'models' => $this->models?->catalog($group)?->count(),
                'requests' => $this->requests,
                'environment' => $this->environment,
                'version' => $this->version,
                'symfony' => Kernel::VERSION,
                'collector' => null === $collector ? null : ['status' => $collector->status->value, 'summary' => $collector->summary],
                'docs' => null === $this->docsRoute ? null : $this->urls->generate($this->docsRoute, ['group' => $group]),
            ],
        ];
    }
}
