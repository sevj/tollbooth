<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Secured;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Auth;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/me')]
#[Auth('api_key', 'user')]
final class WhoAmI
{
    /**
     * @return array{id: string, method: string, plan: ?string}
     */
    public function __invoke(Consumer $consumer): array
    {
        return ['id' => $consumer->id, 'method' => $consumer->method->value, 'plan' => $consumer->plan];
    }
}
