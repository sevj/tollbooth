<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

enum Granularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
