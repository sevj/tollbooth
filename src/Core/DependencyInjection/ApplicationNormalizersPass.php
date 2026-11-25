<?php

declare(strict_types=1);

namespace Tollbooth\Core\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final readonly class ApplicationNormalizersPass implements CompilerPassInterface
{
    /**
     * @param list<string> $serializers
     */
    public function __construct(
        private array $serializers,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds('serializer.normalizer')) as $id) {
            $definition = $container->getDefinition($id);
            $tags = $definition->getTag('serializer.normalizer');
            foreach ($tags as $tag) {
                if (!\is_array($tag) || isset($tag['serializer']) || ($tag['built_in'] ?? false)) {
                    continue 2;
                }
            }

            $definition->clearTag('serializer.normalizer');
            foreach ($tags as $tag) {
                $definition->addTag('serializer.normalizer', [...(array) $tag, 'serializer' => ['default', ...$this->serializers]]);
            }
        }
    }
}
