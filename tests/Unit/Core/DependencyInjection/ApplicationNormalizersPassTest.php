<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tollbooth\Core\DependencyInjection\ApplicationNormalizersPass;

final class ApplicationNormalizersPassTest extends TestCase
{
    public function testApplicationNormalizersAlsoServeTollboothSerializers(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.money', (new Definition(\stdClass::class))->addTag('serializer.normalizer', ['priority' => 10]));
        $container->setDefinition('app.scoped', (new Definition(\stdClass::class))->addTag('serializer.normalizer', ['serializer' => 'api']));
        $container->setDefinition('built.in', (new Definition(\stdClass::class))->addTag('serializer.normalizer', ['built_in' => true]));

        (new ApplicationNormalizersPass(['tollbooth_preserve', 'tollbooth_snake_case']))->process($container);

        self::assertSame([['priority' => 10, 'serializer' => ['default', 'tollbooth_preserve', 'tollbooth_snake_case']]], $container->getDefinition('app.money')->getTag('serializer.normalizer'));
        self::assertSame([['serializer' => 'api']], $container->getDefinition('app.scoped')->getTag('serializer.normalizer'));
        self::assertSame([['built_in' => true]], $container->getDefinition('built.in')->getTag('serializer.normalizer'));
    }
}
