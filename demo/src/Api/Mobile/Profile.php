<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Mobile;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Scope;
use Tollbooth\Demo\Model\ProfileView;

#[Endpoint('GET', '/me')]
#[Scope('profile')]
final readonly class Profile
{
    public function __invoke(Consumer $consumer): ProfileView
    {
        return new ProfileView($consumer->id, $consumer->name, $consumer->method->value, $consumer->plan);
    }
}
