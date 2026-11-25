<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Secured;

use Tollbooth\Core\Attribute\Auth;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Scope;

#[Endpoint('GET', '/catalog')]
#[Auth('api_key', 'anonymous')]
#[Scope('things:read')]
final class Catalog
{
    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        return ['gate'];
    }
}
