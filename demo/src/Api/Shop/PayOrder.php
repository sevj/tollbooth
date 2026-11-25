<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\PaymentView;
use Tollbooth\Demo\PaymentGatewayTimeout;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/orders/{id}/payment', status: 201)]
final readonly class PayOrder
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id): PaymentView
    {
        usleep(random_int(250, 600) * 1000);
        if (0 === random_int(0, 40)) {
            throw new PaymentGatewayTimeout('The payment gateway did not answer within 5 s.');
        }

        return $this->store->pay($id);
    }
}
