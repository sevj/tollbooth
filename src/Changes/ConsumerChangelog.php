<?php

declare(strict_types=1);

namespace Tollbooth\Changes;

/**
 * @internal
 */
final class ConsumerChangelog
{
    public static function markdown(ChangeReport $report, string $version, bool $released = false): string
    {
        $lines = [\sprintf('## %s%s', $version, $released ? '' : ' (unreleased)')];
        foreach (ChangeKind::cases() as $kind) {
            $changes = $report->of($kind);
            if ([] === $changes) {
                continue;
            }

            $lines[] = '';
            $lines[] = '### '.$kind->title();
            foreach ($changes as $change) {
                $lines[] = \sprintf('- `%s`: %s', $change->element, $change->message);
            }
        }

        return implode("\n", $lines)."\n";
    }
}
