<?php

declare(strict_types=1);

namespace Tollbooth\Changes\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Changes\Change;
use Tollbooth\Changes\ChangeKind;
use Tollbooth\Changes\ChangeReport;
use Tollbooth\Changes\ConsumerChangelog;
use Tollbooth\Changes\ContractDiffer;
use Tollbooth\Changes\CurrentContract;
use Tollbooth\Changes\Impact;
use Tollbooth\Changes\SnapshotStore;

/**
 * @internal
 */
#[AsCommand('tollbooth:diff', 'Compare the API contract with a saved snapshot')]
final readonly class DiffCommand
{
    public function __construct(
        private CurrentContract $contract,
        private SnapshotStore $store,
        private Impact $impact,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Snapshot version or file to compare against, the latest snapshot by default')] ?string $base = null,
        #[Option('Snapshot to compare instead of the current code')] ?string $target = null,
        #[Option('Fail on "breaking", "risky" or "none"')] string $failOn = 'breaking',
        #[Option('Output: "text", "markdown" or "json"')] string $format = 'text',
    ): int {
        $kinds = ['none', ...array_map(static fn (ChangeKind $kind): string => $kind->value, ChangeKind::cases())];
        if (!\in_array($failOn, $kinds, true)) {
            $io->error(\sprintf('--fail-on must be one of %s, not "%s".', implode(', ', $kinds), $failOn));

            return Command::INVALID;
        }
        if (!\in_array($format, ['text', 'markdown', 'json'], true)) {
            $io->error(\sprintf('--format must be text, markdown or json, not "%s".', $format));

            return Command::INVALID;
        }

        $base ??= $this->store->latest();
        if (null === $base) {
            $io->error('No snapshot to compare with. Save one with tollbooth:contract:snapshot <version> at the next release.');

            return Command::FAILURE;
        }

        try {
            $before = $this->store->load($base);
            $after = null === $target ? ['version' => 'current', 'groups' => $this->contract->documents()] : $this->store->load($target);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $report = (new ContractDiffer())->diff($before['groups'], $after['groups']);
        $next = $report->nextVersion($before['version']) ?? 'next';
        $ids = ContractDiffer::operationIds($before['groups'], $after['groups']);

        match ($format) {
            'markdown' => $io->write(ConsumerChangelog::markdown($report, null === $target ? $next : $after['version'], null !== $target)),
            'json' => $io->writeln((string) json_encode([
                'base' => $before['version'],
                'target' => $after['version'],
                'next_version' => $next,
                'changes' => array_map(fn (Change $change): array => $change->toArray() + ['impact' => $this->impact->of($change->operations, $ids)], $report->changes),
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)),
            default => $this->text($io, $report, $before['version'], $after['version'], $next, $ids),
        };

        $threshold = ChangeKind::tryFrom($failOn);
        if (null !== $threshold && $report->reaches($threshold)) {
            if ('text' === $format) {
                $io->error(\sprintf('%d breaking and %d risky change%s against %s.', $report->count(ChangeKind::Breaking), $report->count(ChangeKind::Risky), 1 === $report->count(ChangeKind::Risky) ? '' : 's', $before['version']));
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $ids
     */
    private function text(SymfonyStyle $io, ChangeReport $report, string $base, string $target, string $next, array $ids): void
    {
        $io->writeln(\sprintf('Contract %s → %s', $base, $target));
        $io->newLine();
        if ($report->isEmpty()) {
            $io->success('No change.');

            return;
        }

        foreach ($report->changes as $change) {
            $line = \sprintf('%-10s %s: %s', strtoupper($change->kind->value), $change->element, $change->message);
            if (\in_array($change->kind, [ChangeKind::Breaking, ChangeKind::Risky, ChangeKind::Deprecated], true)) {
                $impact = $this->impact->of($change->operations, $ids);
                if (null !== $impact) {
                    $line .= \sprintf(' (%d call%s from %d consumer%s in %d days)', $impact['calls'], 1 === $impact['calls'] ? '' : 's', \count($impact['consumers']), 1 === \count($impact['consumers']) ? '' : 's', $this->impact->days());
                }
            }
            $io->writeln(' '.$line);
        }

        $io->newLine();
        $io->writeln(\sprintf(' %s · next version %s', implode(' · ', array_map(
            static fn (ChangeKind $kind): string => \sprintf('%d %s', $report->count($kind), $kind->value),
            ChangeKind::cases(),
        )), $next));
    }
}
