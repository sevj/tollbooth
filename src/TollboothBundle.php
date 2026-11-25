<?php

declare(strict_types=1);

namespace Tollbooth;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\Translator;
use Tollbooth\Auth\AccessListener;
use Tollbooth\Auth\ApiKey\ApiKeyAuthenticator;
use Tollbooth\Auth\ApiKey\ApiKeyProviderInterface;
use Tollbooth\Auth\ApiKey\ChainApiKeyProvider;
use Tollbooth\Auth\ApiKey\InMemoryApiKeyProvider;
use Tollbooth\Auth\ApiKey\ManageableApiKeyProviderInterface;
use Tollbooth\Auth\Command\GenerateApiKeyCommand;
use Tollbooth\Auth\Command\SetupApiKeysCommand;
use Tollbooth\Auth\ConsumerValueResolver;
use Tollbooth\Auth\Doctrine\DoctrineApiKeyStore;
use Tollbooth\Auth\Health\ApiKeyFindings;
use Tollbooth\Auth\Health\ApiKeyStoreCheck;
use Tollbooth\Auth\TokenConsumerResolver;
use Tollbooth\Changes\Command\DiffCommand;
use Tollbooth\Changes\Command\SnapshotCommand;
use Tollbooth\Changes\CurrentContract;
use Tollbooth\Changes\Impact;
use Tollbooth\Changes\SnapshotStore;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\DependencyInjection\EndpointPass;
use Tollbooth\Core\Health\FindingProviderInterface;
use Tollbooth\Core\Health\HealthCheckInterface;
use Tollbooth\Core\Health\MetadataCheck;
use Tollbooth\Core\Http\BodyValueResolver;
use Tollbooth\Core\Http\DeprecationListener;
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
use Tollbooth\Dashboard\Assets;
use Tollbooth\Dashboard\Changes\ContractChanges;
use Tollbooth\Dashboard\Configuration\Settings;
use Tollbooth\Dashboard\Consumers\ConsumerDirectory;
use Tollbooth\Dashboard\Consumers\ConsumerInsights;
use Tollbooth\Dashboard\Controller\AssetController;
use Tollbooth\Dashboard\Controller\ChangesController;
use Tollbooth\Dashboard\Controller\ConfigurationController;
use Tollbooth\Dashboard\Controller\ConsumersController;
use Tollbooth\Dashboard\Controller\EndpointController;
use Tollbooth\Dashboard\Controller\EndpointListController;
use Tollbooth\Dashboard\Controller\HealthController as DashboardHealthController;
use Tollbooth\Dashboard\Controller\HomeController;
use Tollbooth\Dashboard\Controller\KeysController;
use Tollbooth\Dashboard\Controller\MapController;
use Tollbooth\Dashboard\Controller\ModelsController;
use Tollbooth\Dashboard\Controller\RequestsController;
use Tollbooth\Dashboard\DashboardRouteProvider;
use Tollbooth\Dashboard\Endpoint\EndpointPageBuilder;
use Tollbooth\Dashboard\Endpoints\EndpointList;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Keys\FormGuard;
use Tollbooth\Dashboard\LocaleListener;
use Tollbooth\Dashboard\Map\MapBuilder;
use Tollbooth\Dashboard\Map\Thresholds;
use Tollbooth\Dashboard\Models\ModelCatalogFactory;
use Tollbooth\Dashboard\Shell;
use Tollbooth\Dashboard\Traffic\TrafficReader;
use Tollbooth\Dashboard\Twig\DashboardExtension;
use Tollbooth\Docs\Command\ExportOpenApiCommand;
use Tollbooth\Docs\DocsController;
use Tollbooth\Docs\DocsRouteProvider;
use Tollbooth\Docs\OpenApiGenerator;
use Tollbooth\Health\Command\HealthCommand;
use Tollbooth\Health\ExposureFindings;
use Tollbooth\Health\HealthController;
use Tollbooth\Health\HealthRouteProvider;
use Tollbooth\Health\HealthRunner;
use Tollbooth\Monitoring\AutoRollup;
use Tollbooth\Monitoring\CallBreakdownInterface;
use Tollbooth\Monitoring\CallReaderInterface;
use Tollbooth\Monitoring\CallRecorderInterface;
use Tollbooth\Monitoring\CollectorListener;
use Tollbooth\Monitoring\Command\MaintenanceCommand;
use Tollbooth\Monitoring\Command\SetupCommand;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;
use Tollbooth\Monitoring\Health\DeprecatedUsageFindings;
use Tollbooth\Monitoring\Health\MonitoringCheck;
use Tollbooth\Monitoring\Health\TrafficFindings;
use Tollbooth\Monitoring\RollupInterface;
use Tollbooth\RateLimit\Command\SetupPlansCommand;
use Tollbooth\RateLimit\Doctrine\DoctrinePlanStore;
use Tollbooth\RateLimit\Health\CounterStorageCheck;
use Tollbooth\RateLimit\Health\PlanStoreCheck;
use Tollbooth\RateLimit\PlanRegistry;
use Tollbooth\RateLimit\Plans;
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
 * @phpstan-type AuthConfig array{default: list<string>, api_key_header: string, api_key_provider: ?string, api_keys: array<string, ApiKeyConfig>, doctrine_keys: array{enabled: bool, connection: string, table_prefix: string}}
 * @phpstan-type MonitoringConfig array{enabled: bool, connection: string, table_prefix: string, calls_retention_days: int, aggregates_retention_days: int, auto_maintenance: bool, cache_pool: string}
 * @phpstan-type DocsConfig array{enabled: bool, path: string, title: string, version: string, ui: bool}
 * @phpstan-type ChangesConfig array{enabled: bool, directory: string, impact_days: int, gone_after_sunset: bool}
 * @phpstan-type HealthConfig array{enabled: bool, probe_path: string, token: ?string, error_rate: float, unused_days: int, key_expiry_days: int, max_lag_minutes: int}
 * @phpstan-type DashboardConfig array{enabled: bool, path: string, locale: ?string}
 * @phpstan-type RateLimitConfig array{enabled: bool, plans: array<string, list<string>|array<string, string>>, default_plan: ?string, anonymous_plan: ?string, cache_pool: string, legacy_headers: bool, doctrine_plans: array{enabled: bool, connection: string, table_prefix: string}}
 */
final class TollboothBundle extends AbstractBundle
{
    private const string INTERNAL_CHECK = 'tollbooth.health_check.internal';

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
                    ->normalizeKeys(false)
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
                            ->normalizeKeys(false)
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
                        ->arrayNode('doctrine_keys')
                            ->canBeEnabled()
                            ->info('Also read API keys from a database table, where the dashboard and tollbooth:api-key:generate --store create, rotate and revoke them. Keys listed in "api_keys" keep working and stay read-only.')
                            ->children()
                                ->scalarNode('connection')
                                    ->defaultValue('default')
                                    ->info('Doctrine DBAL connection name or service id.')
                                ->end()
                                ->scalarNode('table_prefix')->defaultValue('tollbooth_')->end()
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
                ->arrayNode('changes')
                    ->canBeDisabled()
                    ->children()
                        ->scalarNode('directory')
                            ->defaultValue('%kernel.project_dir%/tollbooth/contract')
                            ->info('Where contract snapshots are written. Commit this directory.')
                        ->end()
                        ->integerNode('impact_days')
                            ->defaultValue(30)
                            ->min(1)
                            ->info('Days of monitored traffic used to measure the impact of a change.')
                        ->end()
                        ->booleanNode('gone_after_sunset')
                            ->defaultFalse()
                            ->info('Answer 410 Gone once the sunset date of a deprecated endpoint has passed.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('health')
                    ->canBeDisabled()
                    ->children()
                        ->scalarNode('probe_path')
                            ->defaultValue('/_tollbooth/health')
                            ->info('Base path of the /live and /ready probes, meant for uptime tools and orchestrators.')
                        ->end()
                        ->scalarNode('token')
                            ->defaultNull()
                            ->info('Token unlocking the details of /ready, sent as "Authorization: Bearer <token>". Without it, /ready only gives the status.')
                        ->end()
                        ->floatNode('error_rate')->defaultValue(0.05)->min(0)->max(1)->end()
                        ->integerNode('unused_days')->defaultValue(30)->min(1)->end()
                        ->integerNode('key_expiry_days')->defaultValue(14)->min(1)->end()
                        ->integerNode('max_lag_minutes')->defaultValue(10)->min(1)->end()
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
                ->arrayNode('dashboard')
                    ->canBeDisabled()
                    ->info('Operator dashboard. Needs symfony/twig-bundle and symfony/translation; turned off when they are missing. Protect its path with a firewall.')
                    ->children()
                        ->scalarNode('path')
                            ->defaultValue('/_tollbooth')
                            ->info('Base path of the dashboard pages.')
                        ->end()
                        ->enumNode('locale')
                            ->values([null, 'en', 'fr'])
                            ->defaultNull()
                            ->info('Language of the dashboard. Defaults to the language the browser prefers.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('rate_limit')
                    ->canBeDisabled()
                    ->children()
                        ->arrayNode('plans')
                            ->info('Limits of each plan, counted per consumer across every endpoint: {free: ["10/10 seconds", "60/minute", "1000/day"]}.')
                            ->useAttributeAsKey('name')
                            ->normalizeKeys(false)
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
                        ->arrayNode('doctrine_plans')
                            ->canBeEnabled()
                            ->info('Keep the plans in a database table, where the dashboard creates, edits and deletes them. "plans", "default_plan" and "anonymous_plan" then only fill the table when tollbooth:plan:setup creates it, and apply while it cannot be read.')
                            ->children()
                                ->scalarNode('connection')
                                    ->defaultValue('default')
                                    ->info('Doctrine DBAL connection name or service id.')
                                ->end()
                                ->scalarNode('table_prefix')->defaultValue('tollbooth_')->end()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $config): bool => null !== self::unknownPlan($config))
                        ->then(static fn (array $config): never => throw new \InvalidArgumentException(\sprintf('The rate limit plan "%s" is not declared under "tollbooth.rate_limit.plans".', self::unknownPlan($config))))
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param RateLimitConfig $rateLimit
     */
    private static function rateLimited(array $rateLimit): bool
    {
        return $rateLimit['enabled'] && ([] !== $rateLimit['plans'] || $rateLimit['doctrine_plans']['enabled']);
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

        if (!$builder->hasExtension('twig') || !class_exists(Translator::class)) {
            $builder->prependExtensionConfig('tollbooth', ['dashboard' => ['enabled' => false]]);

            return;
        }

        $builder->prependExtensionConfig('twig', ['paths' => [__DIR__.'/Dashboard/Resources/views' => 'TollboothDashboard']]);
        $builder->prependExtensionConfig('framework', ['translator' => ['paths' => [__DIR__.'/Dashboard/Resources/translations']]]);
    }

    /**
     * @param array{path_prefix: string, output: array{envelope: string, case: string}, groups: array<string, GroupConfig>, auth: AuthConfig, rate_limit: RateLimitConfig, docs: DocsConfig, monitoring: MonitoringConfig, health: HealthConfig, changes: ChangesConfig, dashboard: DashboardConfig} $config
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
            $this->loadDocs($config, $container, (bool) $builder->getParameter('kernel.debug'));
        }
        if ($config['monitoring']['enabled']) {
            $this->loadMonitoring($config['monitoring'], $container);
        }
        if ($config['health']['enabled']) {
            $this->loadHealth($config, $container, $builder);
        }
        if ($config['changes']['enabled'] && $config['docs']['enabled']) {
            $this->loadChanges($config, $container);
        }
        if ($config['dashboard']['enabled']) {
            $this->loadDashboard($config, $container);
        }

        $container->services()->set(DeprecationListener::class)
            ->args([$config['changes']['gone_after_sunset'], service('clock')->nullOnInvalid()])
            ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => 30])
            ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onResponse']);

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

        $stored = $auth['doctrine_keys']['enabled'];
        if ($stored) {
            if (!class_exists(Connection::class)) {
                throw new \LogicException('Tollbooth stores API keys with Doctrine DBAL. Run "composer require doctrine/dbal", or disable tollbooth.auth.doctrine_keys.');
            }
            $connection = $auth['doctrine_keys']['connection'];
            $services->set(DoctrineApiKeyStore::class)
                ->args([service(str_contains($connection, '.') ? $connection : 'doctrine.dbal.'.$connection.'_connection'), $auth['doctrine_keys']['table_prefix']]);
            $services->set(ChainApiKeyProvider::class)
                ->args([service(InMemoryApiKeyProvider::class), service(DoctrineApiKeyStore::class)]);
            $services->alias(ManageableApiKeyProviderInterface::class, ChainApiKeyProvider::class);
        }
        $services->alias(ApiKeyProviderInterface::class, $auth['api_key_provider'] ?? ($stored ? ChainApiKeyProvider::class : InMemoryApiKeyProvider::class));

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
                ->args([$stored ? service(ManageableApiKeyProviderInterface::class) : null])
                ->tag('console.command');
            if ($stored) {
                $services->set(SetupApiKeysCommand::class)
                    ->args([service(DoctrineApiKeyStore::class)])
                    ->tag('console.command');
            }
        }
    }

    /**
     * @param array{path_prefix: string, groups: array<string, GroupConfig>, auth: AuthConfig, rate_limit: RateLimitConfig, docs: DocsConfig, monitoring: MonitoringConfig, health: HealthConfig, changes: ChangesConfig} $config
     */
    private function loadDocs(array $config, ContainerConfigurator $container, bool $debug): void
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
                self::rateLimited($config['rate_limit']),
                array_map(static fn (array $group): string => rtrim('/'.trim($group['prefix'], '/'), '/'), $config['groups']),
                $debug ? null : service('cache.system')->nullOnInvalid(),
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
     * @param array{path_prefix: string, output: array{envelope: string, case: string}, groups: array<string, GroupConfig>, auth: AuthConfig, rate_limit: RateLimitConfig, docs: DocsConfig, monitoring: MonitoringConfig, health: HealthConfig, changes: ChangesConfig, dashboard: DashboardConfig} $config
     */
    private function loadHealth(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $health = $config['health'];
        $services = $container->services();

        $services->set(MetadataCheck::class)
            ->args([service(EndpointRegistry::class)])
            ->tag(self::INTERNAL_CHECK, ['priority' => 100]);

        if (self::rateLimited($config['rate_limit'])) {
            $services->set(CounterStorageCheck::class)
                ->args([service($config['rate_limit']['cache_pool']), $config['rate_limit']['cache_pool']])
                ->tag(self::INTERNAL_CHECK, ['priority' => 80]);
        }

        if ($config['rate_limit']['enabled'] && $config['rate_limit']['doctrine_plans']['enabled']) {
            $services->set(PlanStoreCheck::class)
                ->args([service(DoctrinePlanStore::class), [] !== $config['rate_limit']['plans']])
                ->tag(self::INTERNAL_CHECK, ['priority' => 75]);
        }

        if ($config['auth']['doctrine_keys']['enabled']) {
            $services->set(ApiKeyStoreCheck::class)
                ->args([service(DoctrineApiKeyStore::class)])
                ->tag(self::INTERNAL_CHECK, ['priority' => 55]);
        }

        if ($config['monitoring']['enabled']) {
            $services->set(MonitoringCheck::class)
                ->args([service(DoctrineCallStorage::class), $config['monitoring']['calls_retention_days'], $health['max_lag_minutes'], service('clock')->nullOnInvalid()])
                ->tag(self::INTERNAL_CHECK, ['priority' => 60]);
            $services->set(DeprecatedUsageFindings::class)
                ->args([service(CallReaderInterface::class), service(EndpointRegistry::class), 7, service('clock')->nullOnInvalid()])
                ->tag(FindingProviderInterface::TAG);
            $services->set(TrafficFindings::class)
                ->args([service(CallReaderInterface::class), service(EndpointRegistry::class), $health['unused_days'], $health['error_rate'], 20, service('clock')->nullOnInvalid()])
                ->tag(FindingProviderInterface::TAG);
        }

        $services->set(ApiKeyFindings::class)
            ->args([service(ApiKeyProviderInterface::class), $health['key_expiry_days'], service('clock')->nullOnInvalid()])
            ->tag(FindingProviderInterface::TAG);

        $exposed = ['health report' => rtrim($config['docs']['path'], '/').'/health.json'];
        if ($config['docs']['enabled']) {
            $exposed['documentation'] = rtrim($config['docs']['path'], '/').'/docs';
        }
        if ($config['dashboard']['enabled']) {
            $exposed['dashboard'] = rtrim($config['dashboard']['path'], '/').'/map';
        }
        $services->set(ExposureFindings::class)
            ->args([$exposed, service('security.firewall.map')->nullOnInvalid()])
            ->tag(FindingProviderInterface::TAG);

        $services->set(HealthRunner::class)
            ->args([
                tagged_iterator(self::INTERNAL_CHECK),
                tagged_iterator(HealthCheckInterface::TAG),
                tagged_iterator(FindingProviderInterface::TAG),
                self::facts($config),
                service('clock')->nullOnInvalid(),
            ]);

        $services->set(HealthController::class)
            ->args([service(HealthRunner::class), $health['token']])
            ->public()
            ->tag('controller.service_arguments');

        $services->set(HealthRouteProvider::class)
            ->args([$config['docs']['path'], $health['probe_path']])
            ->tag(RouteProviderInterface::TAG);

        if (class_exists(Application::class)) {
            $services->set(HealthCommand::class)
                ->args([service(HealthRunner::class)])
                ->tag('console.command');
        }

        $builder->registerForAutoconfiguration(HealthCheckInterface::class)->addTag(HealthCheckInterface::TAG);
        $builder->registerForAutoconfiguration(FindingProviderInterface::class)->addTag(FindingProviderInterface::TAG);
    }

    /**
     * @param array{docs: DocsConfig, monitoring: MonitoringConfig, changes: ChangesConfig} $config
     */
    private function loadChanges(array $config, ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set(CurrentContract::class)
            ->args([service(OpenApiGenerator::class)]);
        $services->set(SnapshotStore::class)
            ->args([$config['changes']['directory']]);
        $services->set(Impact::class)
            ->args([
                $config['monitoring']['enabled'] ? service(CallReaderInterface::class) : null,
                service('clock')->nullOnInvalid(),
                $config['changes']['impact_days'],
            ]);

        if (class_exists(Application::class)) {
            $services->set(SnapshotCommand::class)
                ->args([service(CurrentContract::class), service(SnapshotStore::class), $config['docs']['version'], service('clock')->nullOnInvalid()])
                ->tag('console.command');
            $services->set(DiffCommand::class)
                ->args([service(CurrentContract::class), service(SnapshotStore::class), service(Impact::class)])
                ->tag('console.command');
        }
    }

    /**
     * @param array{path_prefix: string, groups: array<string, GroupConfig>, auth: AuthConfig, rate_limit: RateLimitConfig, docs: DocsConfig, monitoring: MonitoringConfig, health: HealthConfig, changes: ChangesConfig, dashboard: DashboardConfig} $config
     */
    private function loadDashboard(array $config, ContainerConfigurator $container): void
    {
        $services = $container->services();
        $monitoring = $config['monitoring']['enabled'];

        $groups = [];
        foreach ($config['groups'] as $name => $group) {
            $groups[$name] = [
                'mount' => self::joinPaths($config['path_prefix'], $group['prefix']),
                'prefix' => self::joinPaths($group['prefix']),
                'path' => null === $group['path'] ? null : self::joinPaths($group['path']),
            ];
        }

        $services->set(GroupCatalog::class)
            ->args([service(EndpointRegistry::class), $groups, self::joinPaths($config['path_prefix'])]);
        $services->set(Thresholds::class)
            ->args([$config['health']['error_rate']]);
        if ($monitoring) {
            $services->set(TrafficReader::class)
                ->args([service(CallReaderInterface::class)]);
        }
        $services->set(MapBuilder::class)
            ->args([
                service(GroupCatalog::class),
                service(Thresholds::class),
                $monitoring ? service(TrafficReader::class) : null,
                $config['docs']['enabled'] ? service(OpenApiGenerator::class) : null,
                service(ApiKeyProviderInterface::class),
                service('clock')->nullOnInvalid(),
            ]);
        $services->set(Assets::class);
        $services->set(Shell::class)
            ->args([
                service(GroupCatalog::class),
                service('router'),
                param('kernel.environment'),
                self::version(),
                $monitoring && $config['health']['enabled'] ? service(MonitoringCheck::class) : null,
                $config['docs']['enabled'] && $config['docs']['ui'] ? DocsRouteProvider::PAGE_ROUTE : null,
                $config['docs']['enabled'] ? service(ModelCatalogFactory::class) : null,
                $monitoring,
                $config['changes']['enabled'] && $config['docs']['enabled'] ? service(ContractChanges::class) : null,
                $config['health']['enabled'],
                service('cache.app')->nullOnInvalid(),
                param(EndpointPass::HASH_PARAMETER),
            ]);
        if ($config['changes']['enabled'] && $config['docs']['enabled']) {
            $services->set(ContractChanges::class)
                ->args([service(CurrentContract::class), service(SnapshotStore::class), service('cache.app')->nullOnInvalid(), param(EndpointPass::HASH_PARAMETER)]);
            $services->set(ChangesController::class)
                ->args([service(ContractChanges::class), service(Impact::class), service(GroupCatalog::class), service(Shell::class), service('twig')])
                ->public()
                ->tag('controller.service_arguments');
        }
        if ($config['health']['enabled']) {
            $services->set(DashboardHealthController::class)
                ->args([service(HealthRunner::class), service(GroupCatalog::class), service(Shell::class), service('twig'), service('router')])
                ->public()
                ->tag('controller.service_arguments');
        }
        if ($monitoring) {
            $services->set(ConsumerDirectory::class)
                ->args([
                    service(CallReaderInterface::class),
                    service(ApiKeyProviderInterface::class),
                    $config['rate_limit']['enabled'] ? service(Plans::class) : null,
                    service('clock')->nullOnInvalid(),
                ]);
            $services->set(ConsumerInsights::class)
                ->args([
                    service(ConsumerDirectory::class),
                    service(CallReaderInterface::class),
                    service(GroupCatalog::class),
                    $config['changes']['enabled'] && $config['docs']['enabled'] ? service(ContractChanges::class) : null,
                    service('clock')->nullOnInvalid(),
                ]);
            $services->set(ConsumersController::class)
                ->args([
                    service(ConsumerDirectory::class),
                    service(ConsumerInsights::class),
                    service(GroupCatalog::class),
                    service(Shell::class),
                    service('twig'),
                    service('translator'),
                    $config['auth']['doctrine_keys']['enabled'] ? service(ManageableApiKeyProviderInterface::class) : null,
                ])
                ->public()
                ->tag('controller.service_arguments');
            if ($config['auth']['doctrine_keys']['enabled']) {
                $services->set(KeysController::class)
                    ->args([
                        service(ManageableApiKeyProviderInterface::class),
                        service(ConsumerDirectory::class),
                        service(FormGuard::class),
                        service(GroupCatalog::class),
                        service(Shell::class),
                        service('twig'),
                        service('router'),
                        service('clock')->nullOnInvalid(),
                    ])
                    ->public()
                    ->tag('controller.service_arguments');
            }
            $services->set(RequestsController::class)
                ->args([
                    service(CallReaderInterface::class),
                    service(CallBreakdownInterface::class),
                    service(GroupCatalog::class),
                    service(Shell::class),
                    service('twig'),
                    $config['auth']['api_key_header'],
                    service('clock')->nullOnInvalid(),
                ])
                ->public()
                ->tag('controller.service_arguments');
        }
        if ($config['docs']['enabled']) {
            $services->set(ModelCatalogFactory::class)
                ->args([service(OpenApiGenerator::class), service(GroupCatalog::class)]);
            $services->set(ModelsController::class)
                ->args([service(ModelCatalogFactory::class), service(Shell::class), service('twig'), service('router')])
                ->public()
                ->tag('controller.service_arguments');
        }

        $services->set(HomeController::class)
            ->args([service(GroupCatalog::class), service('router')])
            ->public()
            ->tag('controller.service_arguments');
        $services->set(MapController::class)
            ->args([service(MapBuilder::class), service(Shell::class), service('twig')])
            ->public()
            ->tag('controller.service_arguments');
        $services->set(EndpointList::class)
            ->args([
                service(GroupCatalog::class),
                service(Thresholds::class),
                $monitoring ? service(TrafficReader::class) : null,
                service('clock')->nullOnInvalid(),
            ]);
        $services->set(EndpointListController::class)
            ->args([service(EndpointList::class), service(GroupCatalog::class), service(Shell::class), service('twig')])
            ->public()
            ->tag('controller.service_arguments');
        $services->set(EndpointPageBuilder::class)
            ->args([
                service(GroupCatalog::class),
                self::rateLimited($config['rate_limit']),
                param('kernel.project_dir'),
                $monitoring ? service(TrafficReader::class) : null,
                $monitoring ? service(CallBreakdownInterface::class) : null,
                $config['docs']['enabled'] ? service(OpenApiGenerator::class) : null,
                service('clock')->nullOnInvalid(),
            ]);
        $services->set(EndpointController::class)
            ->args([
                service(EndpointPageBuilder::class),
                service(GroupCatalog::class),
                service(Shell::class),
                service('twig'),
                $config['auth']['api_key_header'],
                $monitoring,
                $config['docs']['enabled'] && $config['docs']['ui'] ? DocsRouteProvider::PAGE_ROUTE : null,
            ])
            ->public()
            ->tag('controller.service_arguments');
        $services->set(AssetController::class)
            ->args([service(Assets::class)])
            ->public()
            ->tag('controller.service_arguments');

        $settings = $config;
        $settings['auth']['api_keys'] = [];
        $settings['health']['token'] = null === $config['health']['token'] ? null : '••••••';
        if ($config['rate_limit']['doctrine_plans']['enabled']) {
            unset($settings['rate_limit']['default_plan'], $settings['rate_limit']['anonymous_plan']);
        }
        $services->set(Settings::class)
            ->args([$settings]);
        $services->set(ConfigurationController::class)
            ->args([
                service(Settings::class),
                service(GroupCatalog::class),
                service(Shell::class),
                service('twig'),
                service('router'),
                service(FormGuard::class),
                $config['rate_limit']['enabled'] ? service(Plans::class) : null,
                service(ApiKeyProviderInterface::class),
            ])
            ->public()
            ->tag('controller.service_arguments');

        $services->set(FormGuard::class)
            ->args([param('kernel.secret')]);
        $services->set(DashboardExtension::class)
            ->args([service(Assets::class), service('router'), service(FormGuard::class)])
            ->tag('twig.extension');
        $services->set(LocaleListener::class)
            ->args([$config['dashboard']['locale']])
            ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'priority' => 20]);
        $services->set(DashboardRouteProvider::class)
            ->args([$config['dashboard']['path']])
            ->tag(RouteProviderInterface::TAG);
    }

    private static function joinPaths(string ...$paths): string
    {
        $joined = implode('/', array_filter(array_map(static fn (string $path): string => trim($path, '/'), $paths), static fn (string $path): bool => '' !== $path));

        return '' === $joined ? '' : '/'.$joined;
    }

    private static function version(): string
    {
        return InstalledVersions::isInstalled('tollbooth/tollbooth-bundle') ? (string) InstalledVersions::getPrettyVersion('tollbooth/tollbooth-bundle') : 'dev';
    }

    /**
     * @param array{output: array{envelope: string, case: string}, rate_limit: RateLimitConfig, monitoring: MonitoringConfig} $config
     *
     * @return array<string, string>
     */
    private static function facts(array $config): array
    {
        return array_filter([
            'tollbooth' => self::version(),
            'symfony' => Kernel::VERSION,
            'php' => \PHP_VERSION.(\function_exists('opcache_get_status') && false !== @opcache_get_status(false) ? ' · opcache on' : ''),
            'environment' => '%kernel.environment%',
            'conventions' => $config['output']['envelope'].' · '.$config['output']['case'].' · problem+json',
            'rate_limit' => self::rateLimited($config['rate_limit']) ? 'cache pool '.$config['rate_limit']['cache_pool'] : 'off',
            'monitoring' => $config['monitoring']['enabled'] ? 'doctrine · '.$config['monitoring']['connection'] : 'off',
        ]);
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
        $services->alias(CallBreakdownInterface::class, DoctrineCallStorage::class);

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

        $stored = $rateLimit['doctrine_plans']['enabled'];
        if ($stored) {
            if (!class_exists(Connection::class)) {
                throw new \LogicException('Tollbooth stores rate limit plans with Doctrine DBAL. Run "composer require doctrine/dbal", or disable tollbooth.rate_limit.doctrine_plans.');
            }
            $connection = $rateLimit['doctrine_plans']['connection'];
            $services->set(DoctrinePlanStore::class)
                ->args([service(str_contains($connection, '.') ? $connection : 'doctrine.dbal.'.$connection.'_connection'), $rateLimit['doctrine_plans']['table_prefix']]);
            if (class_exists(Application::class)) {
                $services->set(SetupPlansCommand::class)
                    ->args([service(DoctrinePlanStore::class), service(Plans::class)])
                    ->tag('console.command');
            }
        }

        $services->set(Plans::class)
            ->args([
                $plans,
                $rateLimit['default_plan'],
                $rateLimit['anonymous_plan'],
                $stored ? service(DoctrinePlanStore::class) : null,
                $stored ? service($rateLimit['cache_pool']) : null,
                service('logger')->nullOnInvalid(),
            ])
            ->tag('kernel.reset', ['method' => 'reset']);

        $services->set(PlanRegistry::class)
            ->args([service(Plans::class)]);

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
