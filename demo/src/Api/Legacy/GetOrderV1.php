<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Legacy;

use Tollbooth\Core\Attribute\DeprecatedEndpoint;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\OrderV1;

#[Endpoint('GET', '/orders/{id}')]
#[DeprecatedEndpoint(since: '2026-09-01')]
final readonly class GetOrderV1
{
    public function __invoke(int $id): OrderV1
    {
        return new OrderV1($id, 0 === $id % 4 ? 'shipped' : 'paid', 4990 + $id % 7 * 1000);
    }
}
