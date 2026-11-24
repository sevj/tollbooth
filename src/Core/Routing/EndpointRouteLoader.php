<?php

declare(strict_types=1);

namespace Tollbooth\Core\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Config\ContainerParametersResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tollbooth\Core\DependencyInjection\EndpointPass;
use Tollbooth\Core\Metadata\EndpointRegistry;

/**
 * @internal
 */
final class EndpointRouteLoader extends Loader
{
    public const string TYPE = 'tollbooth';
    public const string ENDPOINT_ATTRIBUTE = '_tollbooth_endpoint';
    public const string STATUS_ATTRIBUTE = '_tollbooth_status';

    private bool $loaded = false;

    public function __construct(
        private readonly EndpointRegistry $registry,
        private readonly string $pathPrefix,
        private readonly string $endpointsHash,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \RuntimeException('The "tollbooth" routes are already imported. Import them only once.');
        }
        $this->loaded = true;

        $prefix = '/' === $this->pathPrefix ? '' : rtrim($this->pathPrefix, '/');
        $collection = new RouteCollection();
        foreach ($this->registry->all() as $endpoint) {
            $collection->add('tollbooth_'.$endpoint->name, new Route(
                path: $prefix.$endpoint->path,
                defaults: [
                    '_controller' => $endpoint->serviceId,
                    self::ENDPOINT_ATTRIBUTE => $endpoint->name,
                    self::STATUS_ATTRIBUTE => $endpoint->status,
                ],
                requirements: $endpoint->requirements,
                methods: [$endpoint->method],
            ));
            $collection->addResource(new FileResource($endpoint->file));
        }
        $collection->addResource(new ContainerParametersResource([EndpointPass::HASH_PARAMETER => $this->endpointsHash]));

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }
}
