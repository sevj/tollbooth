<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\Finding;
use Tollbooth\Core\Health\FindingProviderInterface;
use Tollbooth\Core\Health\HealthCheckInterface;
use Tollbooth\Core\Health\HealthStatus;
use Tollbooth\Health\HealthRunner;

final class HealthRunnerTest extends TestCase
{
    public function testWorstStatusWins(): void
    {
        self::assertSame(HealthStatus::Warning, HealthStatus::Ok->worst(HealthStatus::Warning));
        self::assertSame(HealthStatus::Critical, HealthStatus::Critical->worst(HealthStatus::Warning));
        self::assertSame('degraded', HealthStatus::Warning->overall());
    }

    public function testChecksAreRunAndFailuresContained(): void
    {
        $runner = new HealthRunner(
            [self::check('db', static fn (): CheckResult => CheckResult::ok('fine'))],
            [self::check('mailer', static fn (): never => throw new \LogicException('smtp password wrong'))],
        );

        $report = $runner->run();

        self::assertSame(HealthStatus::Critical, $report->status);
        self::assertSame(['db', 'mailer'], array_map(static fn ($check): string => $check->name, $report->checks));
        self::assertSame('The check failed with LogicException.', $report->checks[1]->summary);
    }

    public function testWarningsComeFirstAndFailingProvidersAreReported(): void
    {
        $providers = [
            self::findings([new Finding('info'), new Finding('warn', true)]),
            new class implements FindingProviderInterface {
                public function findings(): iterable
                {
                    throw new \RuntimeException();
                }
            },
        ];

        $findings = (new HealthRunner(findingProviders: $providers))->run()->findings;

        self::assertSame(['warn', 'Some findings could not be computed: RuntimeException.', 'info'], array_map(static fn (Finding $finding): string => $finding->message, $findings));
    }

    private static function check(string $name, \Closure $result): HealthCheckInterface
    {
        return new class($name, $result) implements HealthCheckInterface {
            public function __construct(private string $name, private \Closure $result)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function check(): CheckResult
            {
                $result = ($this->result)();
                \assert($result instanceof CheckResult);

                return $result;
            }
        };
    }

    /**
     * @param list<Finding> $findings
     */
    private static function findings(array $findings): FindingProviderInterface
    {
        return new class($findings) implements FindingProviderInterface {
            /**
             * @param list<Finding> $findings
             */
            public function __construct(private array $findings)
            {
            }

            public function findings(): iterable
            {
                return $this->findings;
            }
        };
    }
}
