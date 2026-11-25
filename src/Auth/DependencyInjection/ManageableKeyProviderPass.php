<?php

declare(strict_types=1);

namespace Tollbooth\Auth\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tollbooth\Auth\ApiKey\ManageableApiKeyProviderInterface;

/**
 * @internal
 */
final class ManageableKeyProviderPass implements CompilerPassInterface
{
    public const string PROVIDER_PARAMETER = '.tollbooth.api_key_provider';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PROVIDER_PARAMETER) || $container->hasAlias(ManageableApiKeyProviderInterface::class)) {
            return;
        }

        $id = $container->getParameter(self::PROVIDER_PARAMETER);
        if (!\is_string($id) || !$container->has($id)) {
            return;
        }

        $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass() ?? $id);
        if (\is_string($class) && is_a($class, ManageableApiKeyProviderInterface::class, true)) {
            $container->setAlias(ManageableApiKeyProviderInterface::class, $id);
        }
    }
}
