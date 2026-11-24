<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Endpoint;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Tests\Fixtures\Model\Color;
use Tollbooth\Tests\Fixtures\Model\ThingView;

#[Endpoint('get', 'things')]
final class ListThings
{
    /**
     * @return list<ThingView>
     */
    public function __invoke(#[Query] int $page = 1, #[Query] ?Color $color = null): array
    {
        return [new ThingView($page * 10, 'Page '.$page, $color ?? Color::Amber)];
    }
}
