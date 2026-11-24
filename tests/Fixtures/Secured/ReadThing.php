<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Secured;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Tests\Fixtures\Model\Color;
use Tollbooth\Tests\Fixtures\Model\ThingView;

#[Endpoint('GET', '/things/{id}')]
final class ReadThing
{
    public function __invoke(int $id): ThingView
    {
        return new ThingView($id, 'Secured thing', Color::Red);
    }
}
