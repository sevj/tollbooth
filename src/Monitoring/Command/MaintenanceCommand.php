<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Monitoring\RollupInterface;

/**
 * @internal
 */
#[AsCommand('tollbooth:monitoring:maintain', 'Aggregate the finished minutes and purge what is past retention')]
final readonly class MaintenanceCommand
{
    public function __construct(
        private RollupInterface $storage,
        private int $callsRetentionDays,
        private int $aggregatesRetentionDays,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only aggregate, keep everything')] bool $noPurge = false,
    ): int {
        $now = ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();

        $rows = $this->storage->rollup($now);
        $io->writeln(\sprintf('%d aggregate row%s written.', $rows, 1 === $rows ? '' : 's'));

        if (!$noPurge) {
            $purged = $this->storage->purge($now - $this->callsRetentionDays * 86400, $now - $this->aggregatesRetentionDays * 86400);
            $io->writeln(\sprintf('%d call%s and %d aggregate row%s purged.', $purged['calls'], 1 === $purged['calls'] ? '' : 's', $purged['minutes'], 1 === $purged['minutes'] ? '' : 's'));
        }

        return Command::SUCCESS;
    }
}
