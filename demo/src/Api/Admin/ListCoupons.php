<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Admin;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CouponView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/coupons')]
final readonly class ListCoupons
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<CouponView>
     */
    public function __invoke(): array
    {
        return $this->store->coupons();
    }
}
