<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\WebhookView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/webhooks')]
final readonly class ListWebhooks
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @return list<WebhookView>
     */
    public function __invoke(Consumer $consumer): array
    {
        return $this->store->webhooks($consumer->id);
    }
}
