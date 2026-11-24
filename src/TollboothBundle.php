<?php

declare(strict_types=1);

namespace Tollbooth;

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
use Tollbooth\Core\Security\AuthMethod;
use Tollbooth\RateLimit\PlanRegistry;
use Tollbooth\RateLimit\RateLimitListener;
use Tollbooth\RateLimit\WindowCounter;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

/**
 * @phpstan-import-type GroupConfig from GroupResolver
 * @phpstan-import-type ApiKeyConfig from InMemoryApiKeyProvider
 *
 * @phpstan-type AuthConfig array{default: list<string>, api_key_header: string, api_key_provider: ?string, api_keys: array<string, ApiKeyConfig>}
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
     * @param array{path_prefix: string, output: array{envelope: string, case: string}, groups: array<string, GroupConfig>, auth: AuthConfig, rate_limit: RateLimitConfig} $config
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
