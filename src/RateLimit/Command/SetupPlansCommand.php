<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\RateLimit\Doctrine\DoctrinePlanStore;
use Tollbooth\RateLimit\Plans;

/**
 * @internal
 */
#[AsCommand('tollbooth:plan:setup', 'Create or update the table of rate limit plans and copy the declared plans into it')]
final readonly class SetupPlansCommand
{
    public function __construct(
        private DoctrinePlanStore $store,
        private Plans $plans,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Print the SQL instead of running it, to put it in a migration')] bool $dumpSql = false,
        #[Option('Also copy the declared plans the table lacks when it already holds plans')] bool $import = false,
    ): int {
        if ($dumpSql) {
            $io->writeln(array_map(static fn (string $statement): string => $statement.';', $this->store->setupSql()));

            return Command::SUCCESS;
        }

        $sql = $this->store->setup();
        $copied = $this->plans->import($import);

        $messages = [[] === $sql ? 'The table of rate limit plans is up to date.' : \sprintf('%d statement%s run.', \count($sql), 1 === \count($sql) ? '' : 's')];
        if ([] !== $copied) {
            $messages[] = \sprintf('Copied from the configuration: %s. Edit them from the dashboard from now on.', implode(', ', $copied));
        }
        $io->success($messages);

        return Command::SUCCESS;
    }
}
