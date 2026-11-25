<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class WebhookView
{
    /**
     * @param list<string> $events
     */
    public function __construct(
        public string $id,
        public string $url,
        public array $events,
    ) {
    }
}
