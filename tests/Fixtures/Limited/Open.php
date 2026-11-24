<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Limited;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;

#[Endpoint('GET', '/open')]
#[PublicEndpoint]
final class Open
{
    /**
     * @return array{open: bool}
     */
    public function __invoke(): array
    {
        return ['open' => true];
    }
}
