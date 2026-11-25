<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Mobile;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\AddressInput;
use Tollbooth\Demo\Model\AddressView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/addresses', status: 201)]
final readonly class AddAddress
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(#[Body] AddressInput $input, Consumer $consumer): AddressView
    {
        return $this->store->addAddress($consumer->id, $input);
    }
}
