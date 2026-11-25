<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Admin;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Store;

#[Endpoint('DELETE', '/coupons/{code}')]
final readonly class DeleteCoupon
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $code): void
    {
        $this->store->deleteCoupon($code);
    }
}
