<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Partner;

use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/ping', group: 'default')]
final class PingFromElsewhere
{
    /**
     * @return array{pong: bool}
     */
    public function __invoke(): array
    {
        return ['pong' => true];
    }
}
