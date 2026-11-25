<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Health;

use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\HealthCheckInterface;

final readonly class PaymentGatewayCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'payment_gateway';
    }

    public function check(): CheckResult
    {
        $latency = 0 === intdiv(time(), 60) % 5 ? random_int(1800, 2600) : random_int(120, 260);

        return $latency > 1000
            ? CheckResult::warning(\sprintf('GET /status answered 200 in %.1f s.', $latency / 1000))
            : CheckResult::ok(\sprintf('GET /status answered 200 in %d ms.', $latency));
    }
}
