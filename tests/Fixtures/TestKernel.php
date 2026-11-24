<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures;

use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Tollbooth\TollboothBundle;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TollboothBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tollbooth-tests/cache/'.$this->environment.($this->debug ? '_debug' : '');
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tollbooth-tests/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'serializer' => ['enabled' => true],
            'validation' => ['enabled' => true],
            'property_info' => ['enabled' => true],
            'router' => ['utf8' => true],
        ]);

        $container->extension('tollbooth', match ($this->environment) {
            'json_api' => ['path_prefix' => '/api', 'output' => ['envelope' => 'json_api', 'case' => 'snake_case']],
            'groups' => [
                'path_prefix' => '/api',
                'groups' => [
                    'partner' => [
                        'namespace' => 'Tollbooth\\Tests\\Fixtures\\Partner',
                        'prefix' => '/partner',
                        'output' => ['envelope' => 'json_api', 'case' => 'snake_case'],
                    ],
                ],
            ],
            default => ['path_prefix' => '/api'],
        });

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services = $services->defaults()->autowire()->autoconfigure();
        $services->load('Tollbooth\\Tests\\Fixtures\\Endpoint\\', __DIR__.'/Endpoint/');
        if ('groups' === $this->environment) {
            $services->load('Tollbooth\\Tests\\Fixtures\\Partner\\', __DIR__.'/Partner/');
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'tollbooth');
    }
}
