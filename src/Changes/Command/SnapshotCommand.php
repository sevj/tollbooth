<?php

declare(strict_types=1);

namespace Tollbooth\Changes\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Changes\CurrentContract;
use Tollbooth\Changes\SnapshotStore;

/**
 * @internal
 */
#[AsCommand('tollbooth:contract:snapshot', 'Save the current API contract under a version, to diff later releases against it')]
final readonly class SnapshotCommand
{
    public function __construct(
        private CurrentContract $contract,
        private SnapshotStore $store,
        private string $defaultVersion,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Version of the API, tollbooth.docs.version by default')] ?string $version = null,
        #[Option('Replace an existing snapshot of that version')] bool $force = false,
    ): int {
        $version ??= $this->defaultVersion;
        $documents = $this->contract->documents();

        try {
            $file = $this->store->save($version, $documents, $this->clock?->now() ?? new \DateTimeImmutable(), $force);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Contract %s saved to %s (%d group%s). Commit it with the release.', $version, $file, \count($documents), 1 === \count($documents) ? '' : 's'));

        return Command::SUCCESS;
    }
}
