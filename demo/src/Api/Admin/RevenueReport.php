<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Admin;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\Granularity;
use Tollbooth\Demo\Model\Money;
use Tollbooth\Demo\Model\RevenuePointView;

#[Endpoint('GET', '/reports/revenue')]
final readonly class RevenueReport
{
    /**
     * @return list<RevenuePointView>
     */
    public function __invoke(#[Query] string $from, #[Query] string $to, #[Query] Granularity $granularity = Granularity::Day): array
    {
        usleep(random_int(300, 900) * 1000);
        $points = [];
        $period = new \DatePeriod(new \DateTimeImmutable($from), new \DateInterval(match ($granularity) {
            Granularity::Day => 'P1D',
            Granularity::Week => 'P1W',
            Granularity::Month => 'P1M',
        }), new \DateTimeImmutable($to));
        foreach ($period as $index => $start) {
            $points[] = new RevenuePointView($start->format('Y-m-d'), new Money(380000 + ($index * 7919) % 120000), 400 + ($index * 37) % 90);
        }

        return $points;
    }
}
