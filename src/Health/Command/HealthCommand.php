<?php

declare(strict_types=1);

namespace Tollbooth\Health\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Core\Health\HealthStatus;
use Tollbooth\Health\CheckOutcome;
use Tollbooth\Health\HealthRunner;

/**
 * @internal
 */
#[AsCommand('tollbooth:health', 'Run the health checks and list the findings')]
final readonly class HealthCommand
{
    public function __construct(
        private HealthRunner $runner,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $report = $this->runner->run();

        $io->table(['', 'Check', 'Source', 'Summary', 'ms'], array_map(static fn (CheckOutcome $check): array => [
            match ($check->status) {
                HealthStatus::Ok => 'OK',
                HealthStatus::Warning => 'WARN',
                HealthStatus::Critical => 'FAIL',
            },
            $check->name,
            $check->source,
            $check->summary,
            number_format($check->duration, 1),
        ], $report->checks));

        foreach ($report->findings as $finding) {
            $io->writeln(($finding->warning ? '<comment>!</comment> ' : '- ').$finding->message);
        }

        $line = \sprintf('Status: %s', $report->status->overall());
        match ($report->status) {
            HealthStatus::Ok => $io->success($line),
            HealthStatus::Warning => $io->warning($line),
            HealthStatus::Critical => $io->error($line),
        };

        return HealthStatus::Critical === $report->status ? Command::FAILURE : Command::SUCCESS;
    }
}
