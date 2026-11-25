<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Admin;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CustomerView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/customers/{id}/ban')]
final readonly class BanCustomer
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id): CustomerView
    {
        return $this->store->ban($id);
    }
}
