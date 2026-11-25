<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CouponCheckInput;
use Tollbooth\Demo\Model\CouponView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/coupons/validate')]
final readonly class ValidateCoupon
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(#[Body] CouponCheckInput $input): CouponView
    {
        return $this->store->findCoupon($input->code) ?? throw new NotFoundHttpException(\sprintf('The coupon "%s" does not exist.', $input->code));
    }
}
