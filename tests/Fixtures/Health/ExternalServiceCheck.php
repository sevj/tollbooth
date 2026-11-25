<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Health;

use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\HealthCheckInterface;

final class ExternalServiceCheck implements HealthCheckInterface
{
    public static ?\Closure $result = null;

    public function name(): string
    {
        return 'external_service';
    }

    public function check(): CheckResult
    {
        $result = null === self::$result ? null : (self::$result)();

        return $result instanceof CheckResult ? $result : CheckResult::ok('Answered 200.');
    }
}
