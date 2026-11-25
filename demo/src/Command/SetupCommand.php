<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Auth\Doctrine\DoctrineApiKeyStore;
use Tollbooth\Demo\Database\ShopSchema;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;

#[AsCommand('demo:setup', 'Recreate the shop tables with their fixtures and make sure the monitoring and API key tables exist')]
final readonly class SetupCommand
{
    public function __construct(
        private ShopSchema $shop,
        private DoctrineCallStorage $monitoring,
        private DoctrineApiKeyStore $keys,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->shop->reset();
        $this->monitoring->setup();
        $this->keys->setup();
        $io->success('Shop database reset with its fixtures, monitoring and API key tables ready. Next: demo:seed for a day of traffic.');

        return Command::SUCCESS;
    }
}
