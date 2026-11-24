<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Secured;

use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('POST', '/things', status: 201)]
final class WriteThing
{
    public function __invoke(): void
    {
    }
}
