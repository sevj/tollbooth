<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Endpoint;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Tests\Fixtures\Model\Color;
use Tollbooth\Tests\Fixtures\Model\ThingView;

#[Endpoint('GET', '/things/{id}')]
final class GetThing
{
    public function __invoke(int $id, #[Query] ?string $note = null): ThingView
    {
        if (404 === $id) {
            throw new NotFoundHttpException('Thing 404 does not exist.');
        }

        return new ThingView($id, 'Thing '.$id, Color::Red, $note);
    }
}
