<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Legacy;

use Tollbooth\Core\Attribute\DeprecatedEndpoint;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\UserV1;

#[Endpoint('GET', '/users/{id}')]
#[DeprecatedEndpoint(since: '2026-06-01', sunset: '2027-03-01', link: 'https://docs.example.com/migrate-to-v2')]
final readonly class GetUserV1
{
    public function __invoke(int $id): UserV1
    {
        return new UserV1($id, 'user'.$id.'@example.com', 'User '.$id);
    }
}
