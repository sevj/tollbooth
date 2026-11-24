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
    public const string GROUP_ATTRIBUTE = '_tollbooth_group';
    public const string ENVELOPE_ATTRIBUTE = '_tollbooth_envelope';
    public const string CASE_ATTRIBUTE = '_tollbooth_case';
    public const string AUTH_ATTRIBUTE = '_tollbooth_auth';
    public const string SCOPES_ATTRIBUTE = '_tollbooth_scopes';

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
            $collection->add($endpoint->routeName(), new Route(
                path: $prefix.$endpoint->path,
                defaults: [
                    '_controller' => $endpoint->serviceId,
                    self::ENDPOINT_ATTRIBUTE => $endpoint->name,
                    self::STATUS_ATTRIBUTE => $endpoint->status,
                    self::GROUP_ATTRIBUTE => $endpoint->group,
                    self::ENVELOPE_ATTRIBUTE => $endpoint->envelope,
                    self::CASE_ATTRIBUTE => $endpoint->case,
                    self::AUTH_ATTRIBUTE => $endpoint->auth,
                    self::SCOPES_ATTRIBUTE => $endpoint->scopes,
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
