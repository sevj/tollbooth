<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;

/**
 * @internal
 */
#[AsCommand('tollbooth:monitoring:setup', 'Create or update the monitoring tables')]
final readonly class SetupCommand
{
    public function __construct(
        private DoctrineCallStorage $storage,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Print the SQL instead of running it, to put it in a migration')] bool $dumpSql = false,
    ): int {
        $sql = $dumpSql ? $this->storage->setupSql() : $this->storage->setup();
        if ([] === $sql) {
            $io->success('The monitoring tables are up to date.');

            return Command::SUCCESS;
        }

        if ($dumpSql) {
            $io->writeln(array_map(static fn (string $statement): string => $statement.';', $sql));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d statement%s run.', \count($sql), 1 === \count($sql) ? '' : 's'));

        return Command::SUCCESS;
    }
}
