<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Store;

#[Endpoint('DELETE', '/webhooks/{id}')]
final readonly class DeleteWebhook
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id, Consumer $consumer): void
    {
        $this->store->deleteWebhook($consumer->id, $id);
    }
}
