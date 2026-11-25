<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Admin;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CouponView;
use Tollbooth\Demo\Model\CreateCouponInput;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/coupons', status: 201)]
final readonly class CreateCoupon
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(#[Body] CreateCouponInput $input): CouponView
    {
        return $this->store->addCoupon($input->code, $input->percent, $input->expiresAt);
    }
}
