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
use Tollbooth\Core\Http\EndpointListener;
use Tollbooth\Core\Http\QueryValueResolver;
use Tollbooth\Core\Metadata\EndpointRegistry;
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
                    ->info('Prefix added to the path of every endpoint, for instance "/api".')
                ->end()
            ->end();
    }

    /**
     * @param array{path_prefix: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
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

        $services->set(ProblemFactory::class)
            ->args([param('kernel.debug')]);

        $services->set(EndpointListener::class)
            ->args([service('serializer'), service(ProblemFactory::class)])
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
}
