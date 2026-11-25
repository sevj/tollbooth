<?php

declare(strict_types=1);

namespace Tollbooth\Core\Health;

interface HealthCheckInterface
{
    public const string TAG = 'tollbooth.health_check';

    public function name(): string;

    public function check(): CheckResult;
}
