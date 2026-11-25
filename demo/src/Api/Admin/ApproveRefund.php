<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Admin;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\PaymentView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/refunds/{id}/approve')]
final readonly class ApproveRefund
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id): PaymentView
    {
        return $this->store->refund($id, null);
    }
}
