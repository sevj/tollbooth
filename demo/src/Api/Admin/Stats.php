<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Admin;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\StatsView;
use Tollbooth\Demo\Store;

#[Endpoint('GET', '/stats')]
final readonly class Stats
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(): StatsView
    {
        return $this->store->stats();
    }
}
