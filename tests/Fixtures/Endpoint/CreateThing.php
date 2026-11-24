<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Endpoint;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Tests\Fixtures\Model\CreateThingInput;
use Tollbooth\Tests\Fixtures\Model\ThingView;

#[Endpoint('POST', '/things', status: 201)]
final class CreateThing
{
    public function __invoke(#[Body] CreateThingInput $input): ThingView
    {
        return new ThingView(1, $input->name, $input->color, unitCount: $input->unitCount);
    }
}
