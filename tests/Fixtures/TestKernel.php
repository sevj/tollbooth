<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Tollbooth\TollboothBundle;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string NOW = '2026-11-25 14:02:05';

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
            'cache' => ['pools' => ['tollbooth.test_limits' => ['adapter' => 'cache.adapter.filesystem']]],
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
            'rate_limit' => [
                'path_prefix' => '/api',
                'auth' => ['default' => ['api_key'], 'api_keys' => TestKeys::config()],
                'rate_limit' => [
                    'plans' => [
                        'pro' => ['burst' => '3/10 seconds', 'minute' => '5/minute'],
                        'open' => ['2/minute'],
                    ],
                    'default_plan' => 'pro',
                    'anonymous_plan' => 'open',
                    'cache_pool' => 'tollbooth.test_limits',
                    'legacy_headers' => true,
                ],
            ],
            'monitoring' => [
                'path_prefix' => '/api',
                'auth' => $open,
                'monitoring' => ['enabled' => true, 'connection' => 'tollbooth.test_connection', 'cache_pool' => 'tollbooth.test_limits'],
                'health' => ['token' => 'probe-secret'],
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
        if ('rate_limit' === $this->environment) {
            $services->load('Tollbooth\\Tests\\Fixtures\\Limited\\', __DIR__.'/Limited/');
            $services->set('clock', MockClock::class)->args([self::NOW, 'UTC'])->public();
        }
        if ('monitoring' === $this->environment) {
            $services->load('Tollbooth\\Tests\\Fixtures\\Health\\', __DIR__.'/Health/');
            $services->set('clock', MockClock::class)->args([self::NOW, 'UTC'])->public();
            $services->set('tollbooth.test_connection', Connection::class)
                ->factory([DriverManager::class, 'getConnection'])
                ->args([['driver' => 'pdo_sqlite', 'path' => self::monitoringDatabase()]])
                ->public();
        }
    }

    public static function monitoringDatabase(): string
    {
        return sys_get_temp_dir().'/tollbooth-tests/monitoring.sqlite';
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'tollbooth');
    }
}
