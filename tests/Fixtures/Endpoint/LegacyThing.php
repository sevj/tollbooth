<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Endpoint;

use Tollbooth\Core\Attribute\DeprecatedEndpoint;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Tests\Fixtures\Model\Color;
use Tollbooth\Tests\Fixtures\Model\ThingView;

#[Endpoint('GET', '/v1/things/{id}')]
#[DeprecatedEndpoint(since: '2026-06-01', sunset: '2027-03-01', link: 'https://example.com/migrate-to-v2')]
final class LegacyThing
{
    public function __invoke(int $id): ThingView
    {
        return new ThingView($id, 'Legacy thing', Color::Red);
    }
}
