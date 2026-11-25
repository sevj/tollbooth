<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Admin;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CustomerView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/customers')]
final readonly class ListCustomers
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<CustomerView>
     */
    public function __invoke(): array
    {
        return $this->store->customers();
    }
}
