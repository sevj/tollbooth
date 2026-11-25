<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tollbooth\Auth\ApiKey\InMemoryApiKeyProvider;
use Tollbooth\Auth\ApiKey\ManageableApiKeyProviderInterface;
use Tollbooth\Auth\DependencyInjection\ManageableKeyProviderPass;
use Tollbooth\Tests\Fixtures\Auth\ManagedKeys;
use Tollbooth\TollboothBundle;

final class BundleConfigurationTest extends TestCase
{
    public function testAnUnreadableKeyExpiryIsRefusedAtBuildTime(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"next spring" is not a date.');

        $this->load(['auth' => ['api_keys' => ['acme' => ['id' => 'acme00000001', 'hash' => 'h', 'expires_at' => 'next spring']]]]);
    }

    public function testAnEmptyListOfAuthenticationMethodsIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('lists no method');

        $this->load(['groups' => ['partner' => ['prefix' => '/partner', 'auth' => []]]]);
    }

    public function testACustomKeyProviderCannotBeCombinedWithStoredKeys(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be combined with tollbooth.auth.doctrine_keys');

        $this->load(['auth' => ['api_key_provider' => 'app.keys', 'doctrine_keys' => ['enabled' => true]]]);
    }

    public function testACustomProviderThatCanSaveKeysManagesThem(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(ManageableKeyProviderPass::PROVIDER_PARAMETER, 'app.keys');
        $container->setDefinition('app.keys', new Definition(ManagedKeys::class));
        (new ManageableKeyProviderPass())->process($container);
        self::assertSame('app.keys', (string) $container->getAlias(ManageableApiKeyProviderInterface::class));

        $readOnly = new ContainerBuilder();
        $readOnly->setParameter(ManageableKeyProviderPass::PROVIDER_PARAMETER, 'app.keys');
        $readOnly->setDefinition('app.keys', new Definition(InMemoryApiKeyProvider::class));
        (new ManageableKeyProviderPass())->process($readOnly);
        self::assertFalse($readOnly->hasAlias(ManageableApiKeyProviderInterface::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (['kernel.debug' => false, 'kernel.environment' => 'test', 'kernel.project_dir' => sys_get_temp_dir(), 'kernel.build_dir' => sys_get_temp_dir(), 'kernel.secret' => 's', 'kernel.bundles' => [], 'kernel.bundles_metadata' => []] as $name => $value) {
            $container->setParameter($name, $value);
        }
        $extension = (new TollboothBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
