<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Mobile;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Store;

#[Endpoint('DELETE', '/addresses/{id}')]
final readonly class RemoveAddress
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id, Consumer $consumer): void
    {
        $this->store->deleteAddress($consumer->id, $id);
    }
}
