<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Limited;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\RateLimit;

#[Endpoint('GET', '/search')]
#[RateLimit('2/minute')]
final class Search
{
    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        return [];
    }
}
