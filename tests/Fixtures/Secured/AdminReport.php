<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Secured;

use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tollbooth\Core\Attribute\Auth;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/admin/report')]
#[Auth('user')]
#[IsGranted('ROLE_ADMIN')]
final class AdminReport
{
    /**
     * @return array{total: int}
     */
    public function __invoke(): array
    {
        return ['total' => 42];
    }
}
