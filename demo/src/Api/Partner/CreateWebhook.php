<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CreateWebhookInput;
use Tollbooth\Demo\Model\WebhookView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/webhooks', status: 201)]
final readonly class CreateWebhook
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(#[Body] CreateWebhookInput $input, Consumer $consumer): WebhookView
    {
        return $this->store->addWebhook($consumer->id, $input->url, $input->events);
    }
}
