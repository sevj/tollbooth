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
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Core\Output\OutputRenderer;
use Tollbooth\Core\Output\ResourceTypeResolver;
use Tollbooth\Core\Problem\ProblemFactory;
use Tollbooth\Core\Routing\EndpointRouteLoader;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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
     * @param array{path_prefix: string, output: array{envelope: string, case: string}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $case = NameCase::from($config['output']['case']);
        $serializer = service('serializer.'.self::serializerName($case));

        $container->parameters()->set(EndpointPass::HASH_PARAMETER, '');

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

        $services->set(BodyValueResolver::class)
            ->args([$serializer, service('validator')])
            ->tag('controller.argument_value_resolver', ['priority' => 110]);

        $services->set(ResourceTypeResolver::class)
            ->args([$case]);

        $services->set(OutputRenderer::class)
            ->args([$serializer, Envelope::from($config['output']['envelope']), service(ResourceTypeResolver::class)]);

        $services->set(ProblemFactory::class)
            ->args([param('kernel.debug'), $case]);

        $services->set(EndpointListener::class)
            ->args([service(OutputRenderer::class), service(ProblemFactory::class), $config['path_prefix']])
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

    private static function serializerName(NameCase $case): string
    {
        return 'tollbooth_'.$case->value;
    }
}
