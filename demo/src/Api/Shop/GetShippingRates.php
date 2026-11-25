<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\Money;
use Tollbooth\Demo\Model\ShippingRateView;

#[Endpoint('GET', '/shipping/rates')]
#[PublicEndpoint]
final readonly class GetShippingRates
{
    /**
     * @return list<ShippingRateView>
     */
    public function __invoke(#[Query] float $weight, #[Query] string $country = 'FR', #[Query] bool $express = false): array
    {
        $base = (int) round(490 + $weight * 120 + ('FR' === $country ? 0 : 700));
        $rates = [new ShippingRateView('colissimo', new Money($base), 3, false), new ShippingRateView('dhl', new Money($base + 350), 2, false)];
        if ($express) {
            $rates[] = new ShippingRateView('chronopost', new Money($base * 2), 1, true);
        }

        return $rates;
    }
}
