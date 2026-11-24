<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Secured;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;

#[Endpoint('GET', '/status')]
#[PublicEndpoint]
final class Status
{
    /**
     * @return array{status: string, consumer: string}
     */
    public function __invoke(Consumer $consumer): array
    {
        return ['status' => 'ok', 'consumer' => $consumer->id];
    }
}
