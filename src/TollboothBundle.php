<?php

declare(strict_types=1);

namespace Tollbooth;

use Doctrine\DBAL\Connection;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\KernelEvents;
use Tollbooth\Auth\AccessListener;
use Tollbooth\Auth\ApiKey\ApiKeyAuthenticator;
use Tollbooth\Auth\ApiKey\ApiKeyProviderInterface;
use Tollbooth\Auth\ApiKey\InMemoryApiKeyProvider;
use Tollbooth\Auth\Command\GenerateApiKeyCommand;
use Tollbooth\Auth\ConsumerValueResolver;
use Tollbooth\Auth\TokenConsumerResolver;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\DependencyInjection\EndpointPass;
use Tollbooth\Core\Http\BodyValueResolver;
use Tollbooth\Core\Http\EndpointListener;
use Tollbooth\Core\Http\QueryValueResolver;
use Tollbooth\Core\Http\TimingListener;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Core\Metadata\Group;
use Tollbooth\Core\Metadata\GroupResolver;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Core\Output\OutputRenderer;
use Tollbooth\Core\Output\ResourceTypeResolver;
use Tollbooth\Core\Output\SerializerLocator;
use Tollbooth\Core\Problem\ProblemFactory;
use Tollbooth\Core\Routing\EndpointRouteLoader;
use Tollbooth\Core\Routing\RouteProviderInterface;
use Tollbooth\Core\Security\AuthMethod;
use Tollbooth\Docs\Command\ExportOpenApiCommand;
use Tollbooth\Docs\DocsController;
use Tollbooth\Docs\DocsRouteProvider;
use Tollbooth\Docs\OpenApiGenerator;
use Tollbooth\Monitoring\AutoRollup;
use Tollbooth\Monitoring\CallReaderInterface;
use Tollbooth\Monitoring\CallRecorderInterface;
use Tollbooth\Monitoring\CollectorListener;
use Tollbooth\Monitoring\Command\MaintenanceCommand;
use Tollbooth\Monitoring\Command\SetupCommand;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;
use Tollbooth\Monitoring\RollupInterface;
use Tollbooth\RateLimit\PlanRegistry;
use Tollbooth\RateLimit\RateLimitListener;
use Tollbooth\RateLimit\WindowCounter;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * @phpstan-import-type GroupConfig from GroupResolver
 * @phpstan-import-type ApiKeyConfig from InMemoryApiKeyProvider
 *
 * @phpstan-type AuthConfig array{default: list<string>, api_key_header: string, api_key_provider: ?string, api_keys: array<string, ApiKeyConfig>}
 * @phpstan-type MonitoringConfig array{enabled: bool, connection: string, table_prefix: string, calls_retention_days: int, aggregates_retention_days: int, auto_maintenance: bool, cache_pool: string}
 * @phpstan-type DocsConfig array{enabled: bool, path: string, title: string, version: string, ui: bool}
 * @phpstan-type RateLimitConfig array{enabled: bool, plans: array<string, list<string>|array<string, string>>, default_plan: ?string, anonymous_plan: ?string, cache_pool: string, legacy_headers: bool}
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
                            ->variableNode('auth')
                                ->defaultNull()
                                ->info('Authentication methods accepted by the group, instead of "auth.default".')
                                ->validate()
                                    ->ifTrue(static fn (mixed $auth): bool => null !== $auth && !self::isStringList($auth))
                                    ->thenInvalid('"auth" must be a list of authentication methods.')
                                ->end()
                            ->end()
                            ->variableNode('scopes')
                                ->defaultNull()
                                ->info('Scopes every endpoint of the group requires, or "auto" for "<resource>:read" and "<resource>:write".')
                                ->validate()
                                    ->ifTrue(static fn (mixed $scopes): bool => null !== $scopes && 'auto' !== $scopes && !self::isStringList($scopes))
                                    ->thenInvalid('"scopes" must be a list of scopes or "auto".')
                                ->end()
                            ->end()
                            ->variableNode('rate_limits')
                                ->defaultValue([])
                                ->info('Limits shared by all the calls a consumer makes to the group, on top of its plan: ["1000/hour"] or {burst: "50/10 seconds"}.')
                                ->validate()
                                    ->ifTrue(static fn (mixed $limits): bool => !self::isLimitList($limits))
                                    ->thenInvalid('"rate_limits" must be a list of limits such as "600/minute", or a map of names to limits.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $groups): bool => \array_key_exists(Group::DEFAULT, $groups))
                        ->thenInvalid('"default" is the group of endpoints that match no other group and cannot be declared.')
                    ->end()
                ->end()
                ->arrayNode('auth')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('default')
                            ->info('Authentication methods of endpoints that set none: api_key, jwt, user, anonymous.')
                            ->scalarPrototype()->end()
                            ->defaultValue([AuthMethod::ApiKey->value])
                        ->end()
                        ->scalarNode('api_key_header')
                            ->defaultValue('X-Api-Key')
                        ->end()
                        ->scalarNode('api_key_provider')
                            ->defaultNull()
                            ->info('Service implementing ApiKeyProviderInterface. Defaults to the keys listed in "api_keys".')
                        ->end()
                        ->arrayNode('api_keys')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('id')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('hash')->isRequired()->cannotBeEmpty()->end()
                                    ->arrayNode('scopes')->scalarPrototype()->end()->end()
                                    ->scalarNode('plan')->defaultNull()->end()
                                    ->scalarNode('expires_at')->defaultNull()->end()
                                    ->arrayNode('allowed_ips')->scalarPrototype()->end()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('monitoring')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('connection')
                            ->defaultValue('default')
                            ->info('Doctrine DBAL connection name ("default", "monitoring"…) or service id. A dedicated database keeps the load off the application one.')
                        ->end()
                        ->scalarNode('table_prefix')->defaultValue('tollbooth_')->end()
                        ->integerNode('calls_retention_days')->defaultValue(7)->min(1)->end()
                        ->integerNode('aggregates_retention_days')->defaultValue(30)->min(1)->end()
                        ->booleanNode('auto_maintenance')
                            ->defaultTrue()
                            ->info('Aggregate finished minutes and purge old data at the end of requests, after the response is sent. Turn off to run tollbooth:monitoring:maintain from a scheduler instead.')
                        ->end()
                        ->scalarNode('cache_pool')
                            ->defaultValue('cache.app')
                            ->info('Cache pool remembering when maintenance last ran.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('docs')
                    ->canBeDisabled()
                    ->children()
                        ->scalarNode('path')
                            ->defaultValue('/_tollbooth')
                            ->info('Base path of the documentation routes: <path>/docs/<group> and <path>/openapi/<group>.json.')
                        ->end()
                        ->scalarNode('title')->defaultValue('API')->end()
                        ->scalarNode('version')->defaultValue('1.0.0')->end()
                        ->booleanNode('ui')
                            ->defaultTrue()
                            ->info('Serve the documentation page. The OpenAPI documents stay available when false.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('rate_limit')
                    ->canBeDisabled()
                    ->children()
                        ->arrayNode('plans')
                            ->info('Limits of each plan, counted per consumer across every endpoint: {free: ["10/10 seconds", "60/minute", "1000/day"]}.')
                            ->useAttributeAsKey('name')
                            ->variablePrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $limits): bool => !self::isLimitList($limits))
                                    ->thenInvalid('A plan must be a list of limits such as "600/minute", or a map of names to limits.')
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('default_plan')
                            ->defaultNull()
                            ->info('Plan of consumers that have none, or an unknown one.')
                        ->end()
                        ->scalarNode('anonymous_plan')
                            ->defaultNull()
                            ->info('Plan of anonymous consumers. Defaults to "default_plan".')
                        ->end()
                        ->scalarNode('cache_pool')
                            ->defaultValue('cache.app')
                            ->info('Cache pool holding the counters. Use a shared one (Redis) when several servers answer.')
                        ->end()
                        ->booleanNode('legacy_headers')
                            ->defaultFalse()
                            ->info('Also send X-RateLimit-Limit, X-RateLimit-Remaining and X-RateLimit-Reset for the tightest limit.')
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $config): bool => null !== self::unknownPlan($config))
                        ->then(static fn (array $config): never => throw new \InvalidArgumentException(\sprintf('The rate limit plan "%s" is not declared under "tollbooth.rate_limit.plans".', self::unknownPlan($config))))
                    ->end()
                ->end()
            ->end();
    }

    private static function isLimitList(mixed $value): bool
    {
        return \is_array($value) && [] === array_filter($value, static fn (mixed $item): bool => !\is_string($item));
    }

    /**
     * @param array<mixed> $config
     */
    private static function unknownPlan(array $config): ?string
    {
        $plans = \is_array($config['plans'] ?? null) ? $config['plans'] : [];
        foreach (['default_plan', 'anonymous_plan'] as $key) {
            $plan = $config[$key] ?? null;
            if (\is_string($plan) && !\array_key_exists($plan, $plans)) {
                return $plan;
            }
        }

        return null;
    }

    private static function isStringList(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value) && [] === array_filter($value, static fn (mixed $item): bool => !\is_string($item));
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
     * @param array{path_prefix: string, output: array{envelope: string, case: string}, groups: array<string, GroupConfig>, auth: AuthConfig, rate_limit: RateLimitConfig, docs: DocsConfig, monitoring: MonitoringConfig} $config
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
            ->set(EndpointPass::GROUPS_PARAMETER, $config['groups'])
            ->set(EndpointPass::AUTH_PARAMETER, $config['auth']['default']);

        $this->loadAuth($config['auth'], $container);
        if ($config['rate_limit']['enabled']) {
            $this->loadRateLimit($config['rate_limit'], $container);
        }
        if ($config['docs']['enabled']) {
            $this->loadDocs($config, $container);
        }
        if ($config['monitoring']['enabled']) {
            $this->loadMonitoring($config['monitoring'], $container);
        }

        $services = $container->services();
        $services->set(EndpointRegistry::class);

        $services->set(EndpointRouteLoader::class)
            ->args([
                service(EndpointRegistry::class),
                $config['path_prefix'],
                param(EndpointPass::HASH_PARAMETER),
                param('kernel.environment'),
                tagged_iterator(RouteProviderInterface::TAG),
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

        $services->set(TimingListener::class)
            ->tag('kernel.event_listener', ['event' => KernelEvents::CONTROLLER, 'method' => 'onController', 'priority' => -1024])
            ->tag('kernel.event_listener', ['event' => KernelEvents::CONTROLLER_ARGUMENTS, 'method' => 'onArgumentsResolved', 'priority' => 1024])
            ->tag('kernel.event_listener', ['event' => KernelEvents::CONTROLLER_ARGUMENTS, 'method' => 'onHandlerStart', 'priority' => -1024])
            ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onResponse', 'priority' => -1024]);

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
     * @param AuthConfig $auth
     */
    private function loadAuth(array $auth, ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set(InMemoryApiKeyProvider::class)
            ->args([$auth['api_keys']]);
        $services->alias(ApiKeyProviderInterface::class, $auth['api_key_provider'] ?? InMemoryApiKeyProvider::class);

        $services->set(ApiKeyAuthenticator::class)
            ->args([service(ApiKeyProviderInterface::class), $auth['api_key_header'], service('clock')->nullOnInvalid()]);

        $services->set(TokenConsumerResolver::class)
            ->args([service('security.token_storage')->nullOnInvalid()]);

        $services->set(AccessListener::class)
            ->args([service(ApiKeyAuthenticator::class), service(TokenConsumerResolver::class)])
            ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'priority' => 7]);

        $services->set(ConsumerValueResolver::class)
            ->tag('controller.argument_value_resolver', ['priority' => 110]);

        if (class_exists(Application::class)) {
            $services->set(GenerateApiKeyCommand::class)
                ->tag('console.command');
        }
    }

    /**
     * @param array{path_prefix: string, groups: array<string, GroupConfig>, auth: AuthConfig, rate_limit: RateLimitConfig, docs: DocsConfig, monitoring: MonitoringConfig} $config
     */
    private function loadDocs(array $config, ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set(OpenApiGenerator::class)
            ->args([
                service(EndpointRegistry::class),
                service(ResourceTypeResolver::class),
                $config['path_prefix'],
                $config['docs']['title'],
                $config['docs']['version'],
                $config['auth']['api_key_header'],
                $config['rate_limit']['enabled'] && [] !== $config['rate_limit']['plans'],
                array_map(static fn (array $group): string => rtrim('/'.trim($group['prefix'], '/'), '/'), $config['groups']),
            ]);

        $services->set(DocsController::class)
            ->args([service(OpenApiGenerator::class), service('router'), $config['docs']['ui']])
            ->public()
            ->tag('controller.service_arguments');

        $services->set(DocsRouteProvider::class)
            ->args([$config['docs']['path']])
            ->tag(RouteProviderInterface::TAG);

        if (class_exists(Application::class)) {
            $services->set(ExportOpenApiCommand::class)
                ->args([service(OpenApiGenerator::class)])
                ->tag('console.command');
        }
    }

    /**
     * @param MonitoringConfig $monitoring
     */
    private function loadMonitoring(array $monitoring, ContainerConfigurator $container): void
    {
        if (!class_exists(Connection::class)) {
            throw new \LogicException('Tollbooth monitoring stores calls with Doctrine DBAL. Run "composer require doctrine/dbal" (or doctrine/doctrine-bundle), or disable tollbooth.monitoring.');
        }

        $connection = str_contains($monitoring['connection'], '.') ? $monitoring['connection'] : 'doctrine.dbal.'.$monitoring['connection'].'_connection';
        $services = $container->services();

        $services->set(DoctrineCallStorage::class)
            ->args([service($connection), $monitoring['table_prefix']]);
        $services->alias(CallRecorderInterface::class, DoctrineCallStorage::class);
        $services->alias(CallReaderInterface::class, DoctrineCallStorage::class);
        $services->alias(RollupInterface::class, DoctrineCallStorage::class);

        $services->set(AutoRollup::class)
            ->args([
                service(RollupInterface::class),
                service($monitoring['cache_pool']),
                $monitoring['calls_retention_days'],
                $monitoring['aggregates_retention_days'],
                service('lock.factory')->nullOnInvalid(),
            ]);

        $services->set(CollectorListener::class)
            ->args([
                service(CallRecorderInterface::class),
                $monitoring['auto_maintenance'] ? service(AutoRollup::class) : null,
                service('clock')->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('kernel.event_listener', ['event' => KernelEvents::TERMINATE]);

        if (class_exists(Application::class)) {
            $services->set(SetupCommand::class)
                ->args([service(DoctrineCallStorage::class)])
                ->tag('console.command');
            $services->set(MaintenanceCommand::class)
                ->args([service(RollupInterface::class), $monitoring['calls_retention_days'], $monitoring['aggregates_retention_days'], service('clock')->nullOnInvalid()])
                ->tag('console.command');
        }
    }

    /**
     * @param RateLimitConfig $rateLimit
     */
    private function loadRateLimit(array $rateLimit, ContainerConfigurator $container): void
    {
        $plans = [];
        foreach ($rateLimit['plans'] as $name => $limits) {
            $plans[$name] = array_map(static fn (Limit $limit): array => $limit->toArray(), Limit::parseAll($limits, 'plan'));
        }

        $services = $container->services();

        $services->set(WindowCounter::class)
            ->args([service($rateLimit['cache_pool']), service('lock.factory')->nullOnInvalid(), service('clock')->nullOnInvalid()]);

        $services->set(PlanRegistry::class)
            ->args([$plans, $rateLimit['default_plan'], $rateLimit['anonymous_plan']]);

        $services->set(RateLimitListener::class)
            ->args([service(PlanRegistry::class), service(WindowCounter::class), $rateLimit['legacy_headers']])
            ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => 6])
            ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onResponse']);
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
