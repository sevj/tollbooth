<?php

declare(strict_types=1);

namespace Tollbooth;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\KernelEvents;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\DependencyInjection\EndpointPass;
use Tollbooth\Core\Http\BodyValueResolver;
use Tollbooth\Core\Http\EndpointListener;
use Tollbooth\Core\Http\QueryValueResolver;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Core\Metadata\Group;
use Tollbooth\Core\Metadata\GroupResolver;
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Core\Output\OutputRenderer;
use Tollbooth\Core\Output\ResourceTypeResolver;
use Tollbooth\Core\Output\SerializerLocator;
use Tollbooth\Core\Problem\ProblemFactory;
use Tollbooth\Core\Routing\EndpointRouteLoader;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

/**
 * @phpstan-import-type GroupConfig from GroupResolver
 */
final class TollboothBundle extends AbstractBundle
{
    protected string $extensionAlias = 'tollbooth';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('path_prefix')
                    ->defaultValue('')
                    ->info('Prefix added to the path of every endpoint, for instance "/api". Errors under it answer problem details.')
                ->end()
                ->arrayNode('output')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('envelope')
                            ->values(array_column(Envelope::cases(), 'value'))
                            ->defaultValue(Envelope::Plain->value)
                            ->info('How responses are wrapped: "plain" (as is), "data" ({"data": …}) or "json_api".')
                        ->end()
                        ->enumNode('case')
                            ->values(array_column(NameCase::cases(), 'value'))
                            ->defaultValue(NameCase::Preserve->value)
                            ->info('Case of field names in request and response bodies.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('groups')
                    ->info('Sets of endpoints sharing a mount prefix and conventions, matched by namespace or by path.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('namespace')
                                ->defaultNull()
                                ->info('Endpoints whose class lives in this namespace belong to the group.')
                            ->end()
                            ->scalarNode('path')
                                ->defaultNull()
                                ->info('Endpoints whose declared path starts with this path belong to the group.')
                            ->end()
                            ->scalarNode('prefix')
                                ->defaultValue('')
                                ->info('Prefix added to the paths of the group, after "path_prefix".')
                            ->end()
                            ->arrayNode('output')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->enumNode('envelope')
                                        ->values([null, ...array_column(Envelope::cases(), 'value')])
                                        ->defaultNull()
                                    ->end()
                                    ->enumNode('case')
                                        ->values([null, ...array_column(NameCase::cases(), 'value')])
                                        ->defaultNull()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $groups): bool => \array_key_exists(Group::DEFAULT, $groups))
                        ->thenInvalid('"default" is the group of endpoints that match no other group and cannot be declared.')
                    ->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $namedSerializers = [];
        foreach (NameCase::cases() as $case) {
            $namedSerializers[self::serializerName($case)] = array_filter(['name_converter' => $case->nameConverterId()]);
        }

        $builder->prependExtensionConfig('framework', ['serializer' => ['named_serializers' => $namedSerializers]]);
    }

    /**
     * @param array{path_prefix: string, output: array{envelope: string, case: string}, groups: array<string, GroupConfig>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $serializers = [];
        foreach (NameCase::cases() as $case) {
            $serializers[$case->value] = service('serializer.'.self::serializerName($case));
        }

        $container->parameters()
            ->set(EndpointPass::HASH_PARAMETER, '')
            ->set(EndpointPass::OUTPUT_PARAMETER, $config['output'])
            ->set(EndpointPass::GROUPS_PARAMETER, $config['groups']);

        $services = $container->services();
        $services->set(EndpointRegistry::class);

        $services->set(EndpointRouteLoader::class)
            ->args([
                service(EndpointRegistry::class),
                $config['path_prefix'],
                param(EndpointPass::HASH_PARAMETER),
                param('kernel.environment'),
            ])
            ->tag('routing.loader');

        $services->set(QueryValueResolver::class)
            ->tag('controller.argument_value_resolver', ['priority' => 110]);

        $services->set(SerializerLocator::class)
            ->args([service_locator($serializers)]);

        $services->set(BodyValueResolver::class)
            ->args([service(SerializerLocator::class), service('validator')])
            ->tag('controller.argument_value_resolver', ['priority' => 110]);

        $services->set(ResourceTypeResolver::class);

        $services->set(OutputRenderer::class)
            ->args([service(SerializerLocator::class), service(ResourceTypeResolver::class)]);

        $services->set(ProblemFactory::class)
            ->args([param('kernel.debug')]);

        $services->set(EndpointListener::class)
            ->args([service(OutputRenderer::class), service(ProblemFactory::class), self::apiPrefixes($config)])
            ->tag('kernel.event_listener', ['event' => KernelEvents::VIEW, 'method' => 'onView', 'priority' => 10])
            ->tag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'method' => 'onException', 'priority' => 10]);

        $builder->registerAttributeForAutoconfiguration(
            Endpoint::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag(EndpointPass::TAG);
                $definition->addTag('controller.service_arguments');
            },
        );
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new EndpointPass());
    }

    /**
     * @param array{path_prefix: string, groups: array<string, GroupConfig>} $config
     *
     * @return list<string>
     */
    private static function apiPrefixes(array $config): array
    {
        $global = rtrim($config['path_prefix'], '/');
        if ('' !== $global) {
            return [$global];
        }

        return array_values(array_filter(array_map(
            static fn (array $group): string => rtrim('/'.trim($group['prefix'], '/'), '/'),
            $config['groups'],
        )));
    }

    private static function serializerName(NameCase $case): string
    {
        return 'tollbooth_'.$case->value;
    }
}
