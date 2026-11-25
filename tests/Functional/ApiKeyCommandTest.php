<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Tollbooth\Auth\Doctrine\DoctrineApiKeyStore;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\RateLimit\Doctrine\DoctrinePlanStore;
use Tollbooth\RateLimit\Plans;
use Tollbooth\Tests\Fixtures\TestKernel;

final class ApiKeyCommandTest extends FunctionalTestCase
{
    public function testStoringAKeyChecksItsNameAndPlan(): void
    {
        (new Filesystem())->remove(TestKernel::monitoringDatabase());
        (new Filesystem())->mkdir(\dirname(TestKernel::monitoringDatabase()));
        $kernel = self::bootKernel(['environment' => 'monitoring']);
        foreach ([DoctrineApiKeyStore::class, DoctrinePlanStore::class] as $store) {
            $service = self::getContainer()->get($store);
            self::assertTrue($service instanceof DoctrineApiKeyStore || $service instanceof DoctrinePlanStore);
            $service->setup();
        }
        $plans = self::getContainer()->get(Plans::class);
        self::assertInstanceOf(Plans::class, $plans);
        $plans->save('pro', Limit::parseAll(['600/minute'], 'plan'));
        $command = new CommandTester((new Application($kernel))->find('tollbooth:api-key:generate'));

        self::assertSame(Command::FAILURE, $command->execute(['name' => 'acme-erp', '--plan' => 'gold', '--store' => true]));
        self::assertStringContainsString('There is no plan "gold". Known plans: pro.', $command->getDisplay());

        self::assertSame(Command::SUCCESS, $command->execute(['name' => 'acme-erp', '--plan' => 'pro', '--store' => true]));
        self::assertSame(Command::FAILURE, $command->execute(['name' => 'ACME-ERP', '--store' => true]));
        self::assertStringContainsString('A key is already named "acme-erp".', $command->getDisplay());

        self::assertSame(Command::SUCCESS, $command->execute(['name' => 'sandbox', '--test' => true]));
        self::assertStringContainsString('test: true', $command->getDisplay());
    }
}
