<?php

declare(strict_types=1);

namespace Tollbooth\Demo;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Tollbooth\Demo\Controller\PagesController;
use Tollbooth\Demo\Security\DemoTokenHandler;
use Tollbooth\Demo\Security\ShopUserProvider;
use Tollbooth\TollboothBundle;

final class DemoKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new TollboothBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tollbooth-demo/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tollbooth-demo/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'tollbooth-demo',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['handler_id' => null, 'cookie_secure' => 'auto', 'cookie_samesite' => 'lax'],
            'serializer' => ['enabled' => true],
            'validation' => ['enabled' => true],
            'property_info' => ['enabled' => true],
            'router' => ['utf8' => true],
            'lock' => 'flock',
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'connections' => [
                    'default' => ['url' => '%env(DEMO_DATABASE_URL)%'],
                    'monitoring' => ['url' => '%env(DEMO_MONITORING_URL)%'],
                ],
            ],
        ]);

        $container->extension('security', [
            'password_hashers' => [InMemoryUser::class => 'auto'],
            'providers' => ['shop' => ['id' => ShopUserProvider::class]],
            'firewalls' => [
                'probes' => ['pattern' => '^/_tollbooth/(health/(live|ready)|docs|openapi)', 'security' => false],
                'api_session' => ['pattern' => '^/api/(admin|mobile)', 'context' => 'shop', 'http_basic' => null, 'provider' => 'shop'],
                'api' => ['pattern' => '^/api', 'stateless' => true, 'http_basic' => null, 'access_token' => ['token_handler' => DemoTokenHandler::class], 'provider' => 'shop'],
                'main' => [
                    'context' => 'shop',
                    'lazy' => true,
                    'provider' => 'shop',
                    'form_login' => ['login_path' => '/login', 'check_path' => '/login', 'default_target_path' => '/', 'enable_csrf' => false],
                    'logout' => ['path' => '/logout', 'target' => '/'],
                ],
            ],
            'access_control' => [
                ['path' => '^/_tollbooth', 'roles' => 'ROLE_ADMIN'],
            ],
        ]);

        $container->extension('tollbooth', [
            'path_prefix' => '/api',
            'auth' => ['default' => ['api_key'], 'api_keys' => DemoKeys::config(), 'doctrine_keys' => ['enabled' => true, 'connection' => 'monitoring']],
            'groups' => [
                'shop' => [
                    'namespace' => 'Tollbooth\\Demo\\Api\\Shop',
                    'prefix' => '/v2',
                    'output' => ['envelope' => 'json_api', 'case' => 'snake_case'],
                    'scopes' => 'auto',
                ],
                'partner' => [
                    'namespace' => 'Tollbooth\\Demo\\Api\\Partner',
                    'prefix' => '/partner',
                    'output' => ['case' => 'snake_case'],
                    'scopes' => 'auto',
                    'rate_limits' => ['10000/hour'],
                ],
                'mobile' => [
                    'namespace' => 'Tollbooth\\Demo\\Api\\Mobile',
                    'prefix' => '/mobile',
                    'output' => ['envelope' => 'data', 'case' => 'camel_case'],
                    'auth' => ['api_key', 'user'],
                ],
                'admin' => [
                    'namespace' => 'Tollbooth\\Demo\\Api\\Admin',
                    'prefix' => '/admin',
                    'output' => ['case' => 'camel_case'],
                    'auth' => ['user'],
                ],
                'legacy' => [
                    'namespace' => 'Tollbooth\\Demo\\Api\\Legacy',
                    'prefix' => '/v1',
                    'scopes' => 'auto',
                ],
                'backoffice' => [
                    'path' => '/backoffice',
                    'output' => ['case' => 'camel_case'],
                    'auth' => ['jwt'],
                    'scopes' => 'auto',
                ],
            ],
            'rate_limit' => [
                'plans' => [
                    'free' => ['5/10 seconds', '60/minute', '1000/day'],
                    'pro' => ['burst' => '100/10 seconds', 'minute' => '600/minute', 'day' => '200000/day'],
                    'partner' => ['burst' => '500/10 seconds', 'minute' => '3000/minute'],
                ],
                'default_plan' => 'free',
                'anonymous_plan' => 'free',
                'doctrine_plans' => ['enabled' => true, 'connection' => 'monitoring'],
            ],
            'monitoring' => ['enabled' => true, 'connection' => 'monitoring'],
            'docs' => ['title' => 'Shop API', 'version' => '2.5.0'],
            'health' => ['token' => 'demo'],
            'changes' => ['directory' => '%kernel.project_dir%/contract'],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('Tollbooth\\Demo\\', __DIR__.'/')->exclude([__DIR__.'/Model/', __DIR__.'/DemoKernel.php', __DIR__.'/DemoKeys.php', __DIR__.'/PaymentGatewayTimeout.php']);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'tollbooth');
        $routes->add('demo_home', '/')->controller([PagesController::class, 'home']);
        $routes->add('demo_login', '/login')->controller([PagesController::class, 'login'])->methods(['GET', 'POST']);
        $routes->add('demo_logout', '/logout')->methods(['GET']);
    }
}
