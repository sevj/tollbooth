<?php

declare(strict_types=1);

namespace Tollbooth\Core\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Core\Metadata\EndpointMetadataFactory;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Core\Metadata\InvalidEndpointException;

/**
 * @internal
 */
final class EndpointPass implements CompilerPassInterface
{
    public const string TAG = 'tollbooth.endpoint';
    public const string HASH_PARAMETER = 'tollbooth.endpoints_hash';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(EndpointRegistry::class)) {
            return;
        }

        $factory = new EndpointMetadataFactory();
        $endpoints = [];
        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);
            $reflection = \is_string($class) ? $container->getReflectionClass($class) : null;
            if (null === $reflection) {
                throw new InvalidEndpointException(\sprintf('The class of endpoint service "%s" cannot be found.', $id));
            }

            $endpoints[] = $factory->create($id, $reflection);
        }

        usort($endpoints, static fn (EndpointMetadata $a, EndpointMetadata $b): int => [$a->path, $a->method] <=> [$b->path, $b->method]);
        $this->assertUnique($endpoints);

        $compiled = array_map(static fn (EndpointMetadata $endpoint): array => $endpoint->toArray(), $endpoints);
        $container->getDefinition(EndpointRegistry::class)->setArgument('$compiled', $compiled);
        $container->setParameter(self::HASH_PARAMETER, hash('xxh128', serialize($compiled)));
    }

    /**
     * @param list<EndpointMetadata> $endpoints
     */
    private function assertUnique(array $endpoints): void
    {
        $names = [];
        $routes = [];
        foreach ($endpoints as $endpoint) {
            if (isset($names[$endpoint->name])) {
                throw InvalidEndpointException::duplicateName($endpoint->name, $names[$endpoint->name], $endpoint->class);
            }
            $names[$endpoint->name] = $endpoint->class;

            $route = $endpoint->method.' '.$endpoint->path;
            if (isset($routes[$route])) {
                throw InvalidEndpointException::duplicateRoute($route, $routes[$route], $endpoint->class);
            }
            $routes[$route] = $endpoint->class;
        }
    }
}
