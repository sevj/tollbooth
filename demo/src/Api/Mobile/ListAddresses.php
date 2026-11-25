<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Mobile;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\AddressView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/addresses')]
final readonly class ListAddresses
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<AddressView>
     */
    public function __invoke(Consumer $consumer): array
    {
        return $this->store->addresses($consumer->id);
    }
}
