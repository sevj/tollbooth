<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Mobile;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\NotificationPreferences;

#[Endpoint('GET', '/notifications/preferences')]
final readonly class GetNotificationPreferences
{
    public function __invoke(): NotificationPreferences
    {
        return new NotificationPreferences(quietHoursStart: '22:00');
    }
}
