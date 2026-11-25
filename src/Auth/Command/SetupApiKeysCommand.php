<?php

declare(strict_types=1);

namespace Tollbooth\Auth\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Auth\Doctrine\DoctrineApiKeyStore;

/**
 * @internal
 */
#[AsCommand('tollbooth:api-key:setup', 'Create or update the table of API keys')]
final readonly class SetupApiKeysCommand
{
    public function __construct(
        private DoctrineApiKeyStore $store,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Print the SQL instead of running it, to put it in a migration')] bool $dumpSql = false,
    ): int {
        $sql = $dumpSql ? $this->store->setupSql() : $this->store->setup();
        if ([] === $sql) {
            $io->success('The table of API keys is up to date.');

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
