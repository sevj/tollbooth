<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Auth\Doctrine\DoctrineApiKeyStore;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\Demo\Database\ShopSchema;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;
use Tollbooth\RateLimit\Doctrine\DoctrinePlanStore;
use Tollbooth\RateLimit\Plans;

#[AsCommand('demo:setup', 'Recreate the shop tables with their fixtures and make sure the monitoring, API key and plan tables exist, with the demo plans')]
final readonly class SetupCommand
{
    public function __construct(
        private ShopSchema $shop,
        private DoctrineCallStorage $monitoring,
        private DoctrineApiKeyStore $keys,
        private DoctrinePlanStore $planTable,
        private Plans $plans,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->shop->reset();
        $this->monitoring->setup();
        $this->keys->setup();
        $this->planTable->setup();
        $plans = [
            'free' => ['5/10 seconds', '60/minute', '1000/day'],
            'pro' => ['burst' => '100/10 seconds', 'minute' => '600/minute', 'day' => '200000/day'],
            'partner' => ['burst' => '500/10 seconds', 'minute' => '3000/minute'],
        ];
        foreach ($plans as $name => $limits) {
            if (!isset($this->plans->all()[$name])) {
                $this->plans->save($name, Limit::parseAll($limits, 'plan'), 'free' === $name, 'free' === $name);
            }
        }
        $io->success('Shop database reset with its fixtures, monitoring, API key and plan tables ready. Next: demo:seed for a day of traffic.');

        return Command::SUCCESS;
    }
}
