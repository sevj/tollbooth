<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures;

use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
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
        if ('auth' === $this->environment) {
            yield new SecurityBundle();
        }
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

        $open = ['default' => ['anonymous']];
        $container->extension('tollbooth', match ($this->environment) {
            'json_api' => ['path_prefix' => '/api', 'auth' => $open, 'output' => ['envelope' => 'json_api', 'case' => 'snake_case']],
            'groups' => [
                'path_prefix' => '/api',
                'auth' => $open,
                'groups' => [
                    'partner' => [
                        'namespace' => 'Tollbooth\\Tests\\Fixtures\\Partner',
                        'prefix' => '/partner',
                        'output' => ['envelope' => 'json_api', 'case' => 'snake_case'],
                    ],
                ],
            ],
            'auth' => [
                'path_prefix' => '/api',
                'auth' => ['default' => ['anonymous'], 'api_keys' => TestKeys::config()],
                'groups' => [
                    'secured' => [
                        'namespace' => 'Tollbooth\\Tests\\Fixtures\\Secured',
                        'prefix' => '/secure',
                        'auth' => ['api_key'],
                        'scopes' => 'auto',
                    ],
                ],
            ],
            default => ['path_prefix' => '/api', 'auth' => $open],
        });

        if ('auth' === $this->environment) {
            $container->extension('security', [
                'password_hashers' => ['Symfony\\Component\\Security\\Core\\User\\InMemoryUser' => 'plaintext'],
                'providers' => ['memory' => ['memory' => ['users' => ['alice' => ['password' => 'secret', 'roles' => ['ROLE_USER']]]]]],
                'firewalls' => ['api' => ['pattern' => '^/api', 'stateless' => true, 'http_basic' => null, 'provider' => 'memory']],
            ]);
        }

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services = $services->defaults()->autowire()->autoconfigure();
        $services->load('Tollbooth\\Tests\\Fixtures\\Endpoint\\', __DIR__.'/Endpoint/');
        if ('groups' === $this->environment) {
            $services->load('Tollbooth\\Tests\\Fixtures\\Partner\\', __DIR__.'/Partner/');
        }
        if ('auth' === $this->environment) {
            $services->load('Tollbooth\\Tests\\Fixtures\\Secured\\', __DIR__.'/Secured/');
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'tollbooth');
    }
}
