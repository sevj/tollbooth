<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Endpoint;

use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('DELETE', '/things/{id}')]
final class DeleteThing
{
    public function __invoke(int $id): void
    {
    }
}
