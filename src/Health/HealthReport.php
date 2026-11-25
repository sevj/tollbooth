<?php

declare(strict_types=1);

namespace Tollbooth\Health;

use Tollbooth\Core\Health\Finding;
use Tollbooth\Core\Health\HealthStatus;

final readonly class HealthReport
{
    /**
     * @param list<CheckOutcome>    $checks
     * @param list<Finding>         $findings
     * @param array<string, string> $facts
     */
    public function __construct(
        public HealthStatus $status,
        public \DateTimeImmutable $checkedAt,
        public array $checks,
        public array $findings = [],
        public array $facts = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $details = true): array
    {
        $report = [
            'status' => $this->status->overall(),
            'checked_at' => $this->checkedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];

        if (!$details) {
            return $report;
        }

        return $report + [
            'checks' => array_map(static fn (CheckOutcome $check): array => $check->toArray(), $this->checks),
            'findings' => array_map(static fn (Finding $finding): array => ['level' => $finding->warning ? 'warning' : 'info', 'message' => $finding->message], $this->findings),
            'facts' => $this->facts,
        ];
    }
}
