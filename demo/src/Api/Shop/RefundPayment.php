<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\PaymentView;
use Tollbooth\Demo\Model\RefundInput;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/payments/{id}/refund')]
final readonly class RefundPayment
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id, #[Body] RefundInput $input): PaymentView
    {
        usleep(random_int(300, 700) * 1000);

        return $this->store->refund($id, $input->amount);
    }
}
