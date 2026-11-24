<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Endpoint;

use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/explode')]
final class Explode
{
    public function __invoke(): never
    {
        throw new \RuntimeException('Database password is hunter2.');
    }
}
