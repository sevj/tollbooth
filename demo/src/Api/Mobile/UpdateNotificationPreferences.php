<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Mobile;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\NotificationPreferences;

#[Endpoint('PUT', '/notifications/preferences')]
final readonly class UpdateNotificationPreferences
{
    public function __invoke(#[Body] NotificationPreferences $preferences): NotificationPreferences
    {
        return $preferences;
    }
}
