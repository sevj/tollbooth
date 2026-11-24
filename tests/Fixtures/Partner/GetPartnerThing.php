<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Partner;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Tests\Fixtures\Model\Color;
use Tollbooth\Tests\Fixtures\Model\ThingView;

#[Endpoint('GET', '/things/{id}', name: 'get_thing')]
final class GetPartnerThing
{
    public function __invoke(int $id): ThingView
    {
        return new ThingView($id, 'Partner thing '.$id, Color::Amber, unitCount: 4);
    }
}
